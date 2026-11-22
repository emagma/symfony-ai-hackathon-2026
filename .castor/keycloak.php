<?php

declare(strict_types=1);

/**
 * Keycloak - the OIDC provider shared by the three apps.
 *
 * The realm is edited ONLY through .castor/keycloak-realm.php, whose task
 * `castor keycloak:realm` regenerates realm-sylius.json. Keycloak imports
 * that file into a FRESH container only: a plain restart keeps the live H2
 * state (there is no volume) and silently skips the import, hence
 * `--recreate`.
 */

namespace keycloak;

use Castor\Attribute\AsTask;

use function Castor\http_request;
use function Castor\io;
use function Castor\run;
use function init\compose;
use function init\long;
use function init\ok;
use function init\wait_for_http;

use const init\KEYCLOAK_BASE;

#[AsTask(name: 'init', description: 'Ensure the sylius realm is imported and its roles cover the server\'s grants (--recreate to re-import)')]
function init(bool $recreate = false, bool $no_repair = false): void
{
    \guard_host();
    io()->section('Keycloak');

    if ($recreate) {
        recreate();
    } else {
        run(compose('up', '-d', 'keycloak'), context: long());
    }

    await_realm();

    $drift = role_drift();

    // The realm JSON is imported ONLY into a fresh container - a restart keeps
    // the live H2 state (there is no volume) and skips the import. So a realm
    // that has drifted from the committed file is repaired by recreating the
    // container, not by restarting it.
    if ([] !== $drift['missing'] && !$no_repair && !$recreate) {
        io()->note(\sprintf(
            '%d grant(s) have no realm role - re-importing the realm from docker/keycloak/realm-sylius.json.',
            \count($drift['missing']),
        ));
        recreate();
        await_realm();
        $drift = role_drift();
    }

    if ([] !== $drift['missing']) {
        throw new \RuntimeException(\sprintf(
            "%d MCP grant(s) declared by the server have NO Keycloak realm role, so no user can ever be\n"
            . "authorized for the matching tools:\n    %s\n\n"
            . "The realm is only edited through .castor/keycloak-realm.php:\n"
            . "    castor keycloak:realm\n"
            . '    castor keycloak:init --recreate',
            \count($drift['missing']),
            implode("\n    ", $drift['missing']),
        ));
    }

    report_drift($drift);
}

#[AsTask(name: 'recreate', description: 'Recreate the container so the committed realm is imported again')]
function recreate(): void
{
    \guard_host();
    run(compose('up', '-d', '--force-recreate', 'keycloak'), context: long());
}

function await_realm(): void
{
    // Not /health/ready: KC_HEALTH_ENABLED serves it on the management port
    // 9000, which compose.dev.yaml does not publish. Realm discovery is both
    // reachable and a stronger statement - it proves the import actually ran.
    $discovery = KEYCLOAK_BASE . '/realms/sylius/.well-known/openid-configuration';

    if (!wait_for_http($discovery, 180, static fn (int $c): bool => 200 === $c, 'the "sylius" realm')) {
        throw new \RuntimeException(
            "Keycloak did not serve the sylius realm within 180s.\n"
            . '  Inspect it with: castor docker:logs keycloak',
        );
    }

    ok('realm "sylius" is imported');
}

/**
 * The drift detector: what the server's tools declare, against what the realm
 * can actually hand out.
 *
 * @return array{missing: list<string>, extra: list<string>, declared: list<string>, realm: list<string>}
 */
function role_drift(): array
{
    $declared = \mcp\declared_grants();
    $realm = realm_mcp_roles();

    return [
        'missing' => array_values(array_diff($declared, $realm)),
        'extra' => array_values(array_diff($realm, $declared)),
        'declared' => $declared,
        'realm' => $realm,
    ];
}

/**
 * @param array{missing: list<string>, extra: list<string>, declared: list<string>, realm: list<string>} $drift
 */
function report_drift(array $drift): void
{
    // Inert rather than wrong: Grants::fromStrings() ignores grants it does
    // not know, so a surplus realm role unlocks nothing.
    if ([] !== $drift['extra']) {
        io()->warning(\sprintf(
            "%d realm role(s) grant something the server does not serve (harmless, but stale):\n  %s",
            \count($drift['extra']),
            implode("\n  ", $drift['extra']),
        ));
    }

    ok(\sprintf(
        '%d MCP grants declared, %d realm roles, no missing role',
        \count($drift['declared']),
        \count($drift['realm']),
    ));
}

/**
 * @return list<string>
 */
function realm_mcp_roles(): array
{
    $token = token('master', 'admin-cli', 'admin', 'admin');

    // max=1000 is load-bearing: the endpoint pages at 100 by default and the
    // realm holds 108 roles, so the default would silently drop 8 of them and
    // the comparison would look clean while missing real drift.
    $roles = http_request(
        'GET',
        KEYCLOAK_BASE . '/admin/realms/sylius/roles?briefRepresentation=true&first=0&max=1000',
        ['auth_bearer' => $token],
    )->toArray();

    $names = array_values(array_filter(
        array_column($roles, 'name'),
        static fn (string $name): bool => str_starts_with($name, 'mcp:'),
    ));
    sort($names);

    return $names;
}

function token(string $realm, string $client, string $username, string $password): string
{
    try {
        $body = http_request('POST', \sprintf('%s/realms/%s/protocol/openid-connect/token', KEYCLOAK_BASE, $realm), [
            'body' => [
                'grant_type' => 'password',
                'client_id' => $client,
                'username' => $username,
                'password' => $password,
                'scope' => 'openid',
            ],
        ])->toArray();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            \sprintf('Keycloak refused a token for %s@%s via client %s: %s', $username, $realm, $client, $e->getMessage()),
            previous: $e,
        );
    }

    return (string) $body['access_token'];
}

/**
 * The realm roles as the applications actually see them: read out of the
 * JWT itself (userinfo does not carry them), the way the MCP server does.
 *
 * @return list<string>
 */
function token_realm_roles(string $username, string $password): array
{
    // dev-cli is the only client with directAccessGrantsEnabled: the password
    // grant is banned by OAuth 2.1 and quarantined there on purpose.
    $jwt = token('sylius', 'dev-cli', $username, $password);

    $payload = json_decode(
        (string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'), true),
        true,
    );

    return \is_array($payload) ? ($payload['realm_access']['roles'] ?? []) : [];
}
