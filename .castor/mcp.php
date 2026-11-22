<?php

declare(strict_types=1);

/**
 * apps/mcp-server - the MCP server (tools, Admin API client, authorization,
 * write confirmation, audit, tracing). Its own database on the shared server.
 */

namespace mcp;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\context;
use function Castor\io;
use function Castor\run;
use function init\compose;
use function init\console;
use function init\in;
use function init\long;
use function init\ok;
use function init\quiet;

const SERVICE = 'mcp-server';

/**
 * The consumers of the server's own queues (compose.yaml): the steps of the
 * tasks, the passes of the agents and the outgoing messages, the clock of the
 * agents. They read the server's tables as soon as they start.
 */
const CONSUMERS = ['mcp-tasks-worker', 'mcp-agents-worker', 'mcp-scheduler'];

/**
 * The bundles extracted from the server (packages/mcp-*-bundle), label =>
 * directory in the container. They have no vendor/ of their own: the server
 * installs them as path repositories, so their quality tools are the
 * server's, run with each package's own configuration files - the same
 * commands as the `mcp-server` CI job.
 */
const PACKAGES = [
    'MCP core bundle' => '/srv/app/packages/mcp-core-bundle',
    'MCP auth bundle' => '/srv/app/packages/mcp-auth-bundle',
    'MCP Keycloak bundle' => '/srv/app/packages/mcp-auth-keycloak-bundle',
    'MCP audit bundle' => '/srv/app/packages/mcp-audit-bundle',
    'MCP Sylius bundle' => '/srv/app/packages/mcp-sylius-bundle',
    'MCP Sylius 2.x bundle' => '/srv/app/packages/mcp-sylius-v2-bundle',
    'MCP guidelines bundle' => '/srv/app/packages/mcp-guidelines-bundle',
    'MCP tasks bundle' => '/srv/app/packages/mcp-tasks-bundle',
    'MCP tasks Messenger bundle' => '/srv/app/packages/mcp-tasks-messenger-bundle',
    'MCP Sylius events bundle' => '/srv/app/packages/mcp-sylius-events-bundle',
    'MCP agents bundle' => '/srv/app/packages/mcp-agents-bundle',
    'MCP knowledge bundle' => '/srv/app/packages/mcp-knowledge-bundle',
    'MCP screens bundle' => '/srv/app/packages/mcp-screens-bundle',
    'MCP Sylius screens bundle' => '/srv/app/packages/mcp-sylius-screens-bundle',
    'MCP skills bundle' => '/srv/app/packages/mcp-skills-bundle',
    'Symfony AI hackathon 2026 bundle' => '/srv/app/packages/symfony-ai-hackathon-2026-bundle',
];

#[AsTask(name: 'console', description: 'The MCP server\'s Symfony console (all args forwarded)')]
function console_(#[AsRawTokens] array $rawTokens = []): void
{
    \guard_host();

    run(compose('exec', SERVICE, 'php', 'bin/console', ...$rawTokens), context: context()->withTty());
}

#[AsTask(name: 'shell', description: 'A bash shell in the MCP server container')]
function shell(): void
{
    \guard_host();

    run(compose('exec', SERVICE, 'bash'), context: context()->withTty());
}

#[AsTask(name: 'db', description: 'Create the MCP server\'s database if needed and run its migrations')]
function db(): void
{
    \guard_host();
    io()->section('MCP server database');

    // docker/database/init-mcp.sh creates the database on a FRESH volume only;
    // a volume from before the MCP server existed has the grant but not the
    // schema. Idempotent either way.
    // On a column a migration has not added yet, the consumers crash-loop: none
    // while migrating. `up`, not `start`: on a fresh clone `castor init` has
    // not created them yet (`start` would silently start nothing).
    run(compose('stop', ...CONSUMERS), context: long());
    run(console(SERVICE, 'doctrine:database:create', '--if-not-exists'), context: long());
    run(console(SERVICE, 'doctrine:migrations:migrate', '-n'), context: long());
    run(compose('up', '-d', '--no-deps', ...CONSUMERS), context: long());

    ok('MCP server database migrated');
}

#[AsTask(name: 'permissions', description: 'The MCP grants every tool declares (what the Keycloak realm must hand out)')]
function permissions(string $format = 'table'): void
{
    \guard_host();

    run(console(SERVICE, 'mcp:permissions', '--format=' . $format), context: long());
}

/**
 * The declared grants as a list, for the realm drift check.
 *
 * @return list<string>
 */
function declared_grants(): array
{
    $out = capture(console(SERVICE, 'mcp:permissions', '--format=json', '--no-ansi'), context: quiet(), onFailure: '');

    $start = strpos($out, '{');
    $end = strrpos($out, '}');
    if (false === $start || false === $end) {
        throw new \RuntimeException(
            "Could not read the server's declared grants. Run it yourself to see what happened:\n"
            . '  castor mcp:permissions',
        );
    }

    /** @var array{grants?: list<string>, undeclared?: list<string>} $json */
    $json = json_decode(substr($out, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);

    if ([] !== ($json['undeclared'] ?? [])) {
        io()->warning(
            "Tools with no #[Permission] declaration - unreachable under any grants provider:\n  "
            . implode("\n  ", $json['undeclared']),
        );
    }

    return $json['grants'] ?? [];
}

#[AsTask(name: 'shop-extensions', description: 'Compare MCP_SHOP_EXTENSIONS with the extensions the shop publishes')]
function shop_extensions(): int
{
    \guard_host();

    return shop_extensions_check() ? 0 : 1;
}

/**
 * `mcp:shop-extensions:check` in the container, its output on screen: an
 * extension active on the server and absent from the shop fails (its tools
 * would 404), one published by the shop and inactive here only warns.
 */
function shop_extensions_check(): bool
{
    return run(console(SERVICE, 'mcp:shop-extensions:check'), context: long()->withAllowFailure())->isSuccessful();
}

#[AsTask(name: 'sylius-version', description: 'Compare MCP_SYLIUS_VERSION with the Sylius version the shop publishes')]
function sylius_version(): int
{
    \guard_host();

    return sylius_version_check() ? 0 : 1;
}

/**
 * `mcp:sylius-version:check` in the container, its output on screen: another
 * major.minor fails (routes and tools would not match the shop), a plugin
 * that does not publish the version only warns.
 */
function sylius_version_check(): bool
{
    return run(console(SERVICE, 'mcp:sylius-version:check'), context: long()->withAllowFailure())->isSuccessful();
}

#[AsTask(name: 'bench', description: 'Run the MCP "autonomous agent" scenario against the shop and dump the trace')]
function bench(): void
{
    \guard_host();

    run(console(SERVICE, 'mcp:bench:agent'), context: long());
}

#[AsTask(name: 'quality', description: 'ECS + PHPStan + deptrac + PHPUnit of the MCP server and of its bundles (packages/mcp-*-bundle)')]
function quality(): void
{
    \guard_host();
    ecs();
    phpstan();
    deptrac();
    phpunit();
}

#[AsTask(name: 'quality:ecs', description: 'Coding standard of the MCP server (--fix to apply)')]
function ecs(bool $fix = false): void
{
    \guard_host();
    io()->section('MCP server: ECS');
    run(in(SERVICE, 'vendor/bin/ecs', 'check', ...($fix ? ['--fix'] : [])), context: long());
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': ECS');
        run(in(SERVICE, 'vendor/bin/ecs', 'check', '--config', $package . '/ecs.php', ...($fix ? ['--fix'] : [])), context: long());
    }
}

#[AsTask(name: 'quality:phpstan', description: 'Static analysis of the MCP server (level max, with a baseline)')]
function phpstan(): void
{
    \guard_host();
    io()->section('MCP server: PHPStan');
    run(in(SERVICE, 'vendor/bin/phpstan', 'analyse', '--no-progress'), context: long());
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': PHPStan');
        run(
            compose('exec', '-T', '-w', $package, SERVICE, '../../apps/mcp-server/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--no-progress', '--memory-limit=1G'),
            context: long(),
        );
    }
}

#[AsTask(name: 'quality:deptrac', description: 'Boundaries between the components of the MCP server (deptrac.yaml)')]
function deptrac(): void
{
    \guard_host();
    io()->section('MCP server: deptrac');
    run(in(SERVICE, 'vendor/bin/deptrac', 'analyse', '--no-progress'), context: long());
}

#[AsTask(name: 'quality:phpunit', description: 'Unit and functional tests of the MCP server')]
function phpunit(): void
{
    \guard_host();
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': PHPUnit');
        run(in(SERVICE, 'vendor/bin/phpunit', '-c', $package . '/phpunit.xml.dist'), context: long());
    }
    io()->section('MCP server: PHPUnit');
    run(in(SERVICE, 'vendor/bin/phpunit'), context: long());
    ok('MCP server tests passed');
}

const QA_SHOP = 'shop-qa';

#[AsTask(name: 'qa', description: 'The QA scenarios (tests/Qa) against a throwaway shop loaded with the fixtures, or against --shop-url')]
function qa(
    ?string $filter = null,
    bool $keep = false,
    ?string $shop_url = null,
    ?string $sylius_version = null,
    ?string $shop_extensions = null,
): int {
    \guard_host();

    // Another shop, already loaded with the QA fixtures (a Sylius 2.0
    // sandbox): only the scenarios run, with that shop's line and extensions.
    if (null !== $shop_url) {
        return qa_run($shop_url, $filter, $sylius_version, $shop_extensions);
    }

    // The scenarios write to the shop and read its fixtures: they get their
    // own, freshly loaded (a few minutes), and the dev shop (shop_dev,
    // public/media) is never touched. A second container of the shop image
    // serves the `shop_qa` database, its media in apps/shop/var/qa-media.
    io()->section('QA shop: database and fixtures');
    run(compose('exec', '-T', 'database', 'sh', '-c',
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS shop_qa; CREATE DATABASE shop_qa;'
        . ' GRANT ALL PRIVILEGES ON shop_qa.* TO shop;" 2>/dev/null'), context: long());
    $url = str_replace('/shop_dev?', '/shop_qa?', trim(capture(compose('exec', '-T', 'shop', 'sh', '-c', 'echo $DATABASE_URL'))));
    $media = \dirname(__DIR__) . '/apps/shop/var/qa-media';
    @mkdir($media, 0o775, true);
    $shop = ['--no-deps', '-e', 'DATABASE_URL=' . $url, '-v', $media . ':/srv/app/apps/shop/public/media', 'shop'];
    foreach ([
        // The shop's prod container compiles once: a fixture configuration
        // changed since (another branch) would go unseen and load without it.
        ['cache:clear'],
        ['doctrine:migrations:migrate', '-n', '--allow-no-migration'],
        ['sylius:fixtures:load', '-n'],
        ['sylius-mcp:service-account'],
    ] as $command) {
        run(compose('run', '--rm', '-T', ...[...$shop, 'php', 'bin/console', ...$command]), context: long());
    }

    run(['docker', 'rm', '-f', QA_SHOP], context: quiet());
    run(compose('run', '-d', '--name', QA_SHOP, ...$shop), context: long());
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 30 && !$ready; ++$attempt) {
            $ready = run(in(SERVICE, 'curl', '-s', '-o', '/dev/null', 'http://' . QA_SHOP . '/api/v2/docs.jsonld'), context: quiet())->isSuccessful();
            if (!$ready) {
                sleep(1);
            }
        }
        if (!$ready) {
            io()->error('The QA shop did not answer.');

            return 1;
        }

        return qa_run('http://' . QA_SHOP . '/api/v2/admin/', $filter, $sylius_version, $shop_extensions);
    } finally {
        if (!$keep) {
            run(['docker', 'rm', '-f', QA_SHOP], context: quiet());
        }
    }
}

/**
 * The QA suite against the Admin API at $apiUrl, the server told the shop's
 * Sylius version and extensions when they are given (the stack's otherwise).
 */
function qa_run(string $apiUrl, ?string $filter, ?string $syliusVersion, ?string $shopExtensions): int
{
    io()->section('MCP server: QA scenarios');
    // The test kernel compiles once (APP_DEBUG=0): a configuration change
    // since the last run would go unseen.
    run(in(SERVICE, 'rm', '-rf', 'var/cache/test'), context: long());
    $env = ['-e', 'SYLIUS_API_URL=' . $apiUrl];
    if (null !== $syliusVersion) {
        array_push($env, '-e', 'MCP_SYLIUS_VERSION=' . $syliusVersion);
    }
    if (null !== $shopExtensions) {
        array_push($env, '-e', 'MCP_SHOP_EXTENSIONS=' . $shopExtensions);
    }
    $process = run(
        compose('exec', '-T', ...[...$env, SERVICE, 'vendor/bin/phpunit', '-c', 'phpunit.qa.xml.dist', '--testdox', ...(null === $filter ? [] : ['--filter', $filter])]),
        context: long()->withAllowFailure(),
    );

    return $process->isSuccessful() ? 0 : 1;
}
