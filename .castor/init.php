<?php

declare(strict_types=1);

namespace init;

use Castor\Attribute\AsTask;
use function Castor\capture;
use Castor\Context;
use function Castor\context;
use function Castor\http_request;
use function Castor\io;
use function Castor\load_dot_env;
use function Castor\run;
use Symfony\Component\Process\ExecutableFinder;

const SHOP_BASE = 'http://localhost';
const MCP_BASE = 'http://localhost:8081';
const KEYCLOAK_BASE = 'http://localhost:8085';
const CHAT_BASE = 'http://localhost:8090';

const TUNNEL_OVERLAY = 'compose.tunnel.yaml';

/** Ports the dev stack publishes, probed by the preflight. */
const PUBLISHED_PORTS = [
    80 => 'shop',
    8081 => 'mcp-server',
    8090 => 'chat',
    3306 => 'database',
    8085 => 'Keycloak',
    8025 => 'Mailpit UI',
    1025 => 'Mailpit SMTP',
];

// ---------------------------------------------------------------------------
// Plumbing shared by every task file
// ---------------------------------------------------------------------------

function project_root(): string
{
    return \dirname(__DIR__);
}

function dotenv_path(): string
{
    return project_root() . '/docker/.env';
}

/**
 * Explicit compose invocation, always.
 *
 * Castor builds its Context once, at startup, from docker/.env. On a fresh
 * clone that file does not exist yet - `castor init` is what creates it - so
 * nothing from the dotenv is available to the very tasks that generate it.
 * Naming the env file explicitly makes these tasks independent of when the
 * file appeared. The overlays come from COMPOSE_FILE in that same file (read
 * fresh, not from the startup Context) so that `castor tunnel:up`, which
 * appends compose.tunnel.yaml there, and every other task agree on the stack.
 *
 * @return list<string>
 */
function compose(string ...$args): array
{
    $cmd = ['docker', 'compose', '--env-file', 'docker/.env'];
    foreach (compose_files() as $file) {
        $cmd[] = '-f';
        $cmd[] = $file;
    }

    return [...$cmd, ...$args];
}

/** @return list<string> */
function compose_files(): array
{
    $value = is_file(dotenv_path()) ? (load_dot_env(dotenv_path())['COMPOSE_FILE'] ?? '') : '';

    return '' === $value ? ['compose.yaml', 'compose.dev.yaml'] : explode(':', $value);
}

function overlay_enabled(string $overlay): bool
{
    return \in_array($overlay, compose_files(), true);
}

/**
 * A command inside one of the app containers.
 *
 * @return list<string>
 */
function in(string $service, string ...$cmd): array
{
    return compose('exec', '-T', $service, ...$cmd);
}

/**
 * The Symfony console of an app. Every app is its own composer root with a
 * bin/console at its root - the container's working_dir.
 *
 * @return list<string>
 */
function console(string $service, string ...$args): array
{
    return in($service, 'php', 'bin/console', ...$args);
}

/**
 * Steps with no meaningful upper bound: image build, composer install, yarn
 * build, a cold Keycloak boot. Without this they die on Castor's default
 * process timeout, and the failure looks like a hang rather than a timeout.
 */
function long(): Context
{
    return context()->withTimeout(null);
}

function quiet(): Context
{
    return context()->withAllowFailure()->withQuiet()->withTimeout(null);
}

/**
 * @return array<string, string>
 */
function dotenv(): array
{
    if (!is_file(dotenv_path())) {
        throw new \RuntimeException('docker/.env is missing. Run `castor init:env` first (or just `castor init`).');
    }

    return load_dot_env(dotenv_path());
}

/** The one image the three PHP apps run on. */
function php_image(): string
{
    return \sprintf('%s/php:dev', dotenv()['DOCKER_REGISTRY'] ?? '');
}

function image_exists(string $image): bool
{
    return '' !== trim(capture(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image], context: quiet(), onFailure: ''));
}

function has_binary(string $binary): bool
{
    return null !== (new ExecutableFinder())->find($binary);
}

/**
 * A curl poll with an acceptance predicate and a progress line, because these
 * waits are long enough that silence reads as a freeze.
 */
function wait_for_http(string $url, int $timeoutSeconds = 60, ?callable $accept = null, string $label = ''): bool
{
    $accept ??= static fn (int $code): bool => $code >= 200 && $code < 400;
    $deadline = microtime(true) + $timeoutSeconds;
    $spinning = false;

    do {
        $code = http_status($url, 3);

        if ($code > 0 && $accept($code)) {
            if ($spinning) {
                io()->newLine();
            }

            return true;
        }

        if ('' !== $label && !$spinning) {
            io()->write("  waiting for $label ");
            $spinning = true;
        }
        if ($spinning) {
            io()->write('.');
        }
        usleep(500_000);
    } while (microtime(true) < $deadline);

    if ($spinning) {
        io()->newLine();
    }

    return false;
}

/**
 * Status and lower-cased headers of a GET, -1 and none when unreachable.
 *
 * @return array<string, mixed>&array{status: int}
 */
function http_headers(string $url, int $timeoutSeconds = 5): array
{
    try {
        $response = http_request('GET', $url, ['timeout' => $timeoutSeconds, 'max_redirects' => 0]);
        $headers = array_map(static fn (array $values): string => implode(', ', $values), $response->getHeaders(false));

        return ['status' => $response->getStatusCode()] + $headers;
    } catch (\Throwable) {
        return ['status' => -1];
    }
}

function http_status(string $url, int $timeoutSeconds = 5): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        \CURLOPT_NOBODY => true,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_TIMEOUT => $timeoutSeconds,
        \CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);

    return curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
}

function ok(string $message): void
{
    io()->writeln("  <info>✓</info> $message");
}

// ---------------------------------------------------------------------------
// Preflight and environment
// ---------------------------------------------------------------------------

#[AsTask(name: 'preflight', description: 'Check host prerequisites: Docker, ports, /etc/hosts')]
function preflight(bool $skip_hosts = false, bool $yes = false): int
{
    \guard_host();
    io()->section('Preflight');

    $fatal = [];

    $required = [
        'docker' => 'Docker Engine',
        'bash' => 'bin/resolve-env.sh uses bashisms',
        'openssl' => 'bin/resolve-env.sh generates the passwords with it',
    ];
    foreach ($required as $binary => $why) {
        if (!has_binary($binary)) {
            $fatal[] = "Missing `$binary` - $why.";
        }
    }

    if (has_binary('docker')) {
        $version = capture(['docker', 'compose', 'version'], context: quiet(), onFailure: '');
        if (1 !== preg_match('/v?(\d+)\.\d+/', $version, $m) || (int) $m[1] < 2) {
            $fatal[] = 'docker compose v2 not found. The legacy `docker-compose` v1 binary is not supported.';
        } else {
            ok(trim(explode("\n", $version)[0]));
        }

        if ('' === trim(capture(['docker', 'info', '--format', '{{.ServerVersion}}'], context: quiet(), onFailure: ''))) {
            $fatal[] = 'The Docker daemon is unreachable. Start it, or add yourself to the `docker` group.';
        }
    }

    // Skipped when our own stack already holds the ports: on a re-run every one
    // of them is legitimately taken, and warning about it would be noise.
    if ('' === trim(capture(compose('ps', '--quiet'), context: quiet(), onFailure: ''))) {
        foreach (PUBLISHED_PORTS as $port => $service) {
            if (port_taken($port)) {
                io()->warning("Port $port ($service) is already in use - `docker compose up` will fail.");
            }
        }
    }

    if (!$skip_hosts) {
        $fatal = [...$fatal, ...check_hosts_entry($yes)];
    }

    foreach ($fatal as $message) {
        io()->error($message);
    }

    return [] === $fatal ? 0 : 1;
}

function port_taken(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (false === $socket) {
        return false;
    }
    fclose($socket);

    return true;
}

/**
 * `KC_HOSTNAME` is http://keycloak:8085, and that issuer has to resolve to the
 * same place from the browser and from the containers. The containers get it
 * from compose DNS; the host needs the /etc/hosts entry.
 *
 * Deliberately NOT fatal: everything but the browser-side OAuth flows (the
 * chat's login, remote connectors) works over localhost:8085 without it. Those break
 * with a confusing error ("Restart login cookie not found"), which is exactly
 * why it is worth naming here.
 *
 * @return list<string>
 */
function check_hosts_entry(bool $yes): array
{
    if ('127.0.0.1' === gethostbyname('keycloak')) {
        ok('`keycloak` resolves to 127.0.0.1');

        return [];
    }

    io()->warning(
        "`keycloak` does not resolve to 127.0.0.1 on this host.\n"
        . "The OIDC issuer http://keycloak:8085 must resolve identically from your browser and\n"
        . 'from the containers, or the browser-side logins fail with "Restart login cookie not found".',
    );

    $add = $yes ||
        (context()->supportsInteraction &&
            io()->confirm('Append "127.0.0.1 keycloak" to /etc/hosts now? (requires sudo)', true));

    if (!$add) {
        io()->note("Add it later, then re-run `castor init:verify`:\n"
            . '  echo "127.0.0.1 keycloak" | sudo tee -a /etc/hosts');

        return [];
    }

    run(['sudo', 'sh', '-c', 'printf "127.0.0.1 keycloak\n" >> /etc/hosts'], context: context()->withTty());

    if ('127.0.0.1' !== gethostbyname('keycloak')) {
        return ['Could not add the /etc/hosts entry. Add it by hand: echo "127.0.0.1 keycloak" | sudo tee -a /etc/hosts'];
    }

    ok('/etc/hosts now maps keycloak → 127.0.0.1');

    return [];
}

#[AsTask(name: 'env', description: 'Resolve .project.ini into .project.local.ini and docker/.env')]
function env(): void
{
    \guard_host();
    io()->section('Environment');

    // Idempotent: it preserves every non-CHANGE_ME value already in
    // .project.local.ini, so re-running never rotates the passwords.
    $process = run(['bash', 'bin/resolve-env.sh'], context: long()->withAllowFailure());

    if (!is_file(dotenv_path())) {
        throw new \RuntimeException(
            "bin/resolve-env.sh did not produce docker/.env (exit {$process->getExitCode()}).\n"
            . 'Re-run it directly to see why: bash bin/resolve-env.sh',
        );
    }

    guard_composer_auth();
    warn_compose_file_drift();

    ok('docker/.env is ready');
}

/**
 * compose.dev.yaml bind-mounts COMPOSER_AUTH_JSON into the containers. When the
 * host file does not exist, Docker creates a DIRECTORY at the mount point and
 * Composer then fails on it. The compose file already defaults to /dev/null,
 * point the variable there so the default actually applies.
 */
function guard_composer_auth(): void
{
    $values = load_dot_env(dotenv_path());
    $path = (string) ($values['COMPOSER_AUTH_JSON'] ?? '');
    $resolved = str_replace('~', (string) (getenv('HOME') ?: ''), $path);

    if ('' === $resolved || '/dev/null' === $resolved || is_file($resolved)) {
        return;
    }

    io()->note("No Composer auth.json at $resolved - mounting /dev/null instead.");
    file_put_contents(dotenv_path(), preg_replace(
        '/^COMPOSER_AUTH_JSON=.*$/m',
        'COMPOSER_AUTH_JSON=/dev/null',
        (string) file_get_contents(dotenv_path()),
    ));
}

/**
 * resolve-env.sh keeps every value already in .project.local.ini, compose_file
 * included. A clone resolved before an overlay was added to .project.ini
 * therefore keeps its old stack, silently: the new services are just never
 * started. Name it, since nothing else will.
 */
function warn_compose_file_drift(): void
{
    $template = (string) file_get_contents(project_root() . '/.project.ini');
    if (1 !== preg_match('/^compose_file\s*=\s*"([^"]*)"/m', $template, $m)) {
        return;
    }

    $expected = explode(':', $m[1]);
    $actual = array_values(array_diff(compose_files(), [TUNNEL_OVERLAY]));

    if ($expected === $actual) {
        return;
    }

    io()->warning(\sprintf(
        "COMPOSE_FILE is %s, but .project.ini now declares %s.\n"
        . 'resolve-env.sh preserved the value from .project.local.ini. To adopt the new default, delete the '
        . '`compose_file` line from .project.local.ini and re-run `castor init:env`.',
        implode(':', $actual),
        $m[1],
    ));
}

// ---------------------------------------------------------------------------
// Dependencies and containers
// ---------------------------------------------------------------------------

/**
 * The apps, each its own composer root: `apps/<name>/composer.json`.
 *
 * @return list<string>
 */
function apps(): array
{
    $apps = [];
    foreach (glob(project_root() . '/apps/*/composer.json') ?: [] as $file) {
        $apps[] = basename(\dirname($file));
    }

    return $apps;
}

#[AsTask(name: 'deps', description: 'composer install in every app (shop, mcp-server, chat)')]
function deps(): void
{
    \guard_host();
    io()->section('PHP dependencies');

    $values = dotenv();
    $user = \sprintf('%s:%s', $values['PROJECT_USER_ID'] ?? '1000', $values['PROJECT_GROUP_ID'] ?? '1000');

    $image = php_image();
    if (!image_exists($image)) {
        // Without this, `docker run` would try to pull from the registry and
        // fail on authentication rather than on the real cause.
        throw new \RuntimeException("The image $image does not exist yet. Build it first: castor docker:build");
    }

    // Throwaway containers rather than `compose exec`: this works whether or
    // not the stack is up, and on a fresh clone it is what creates vendor/.
    foreach (apps() as $app) {
        io()->writeln("  <comment>apps/$app</comment>");
        run([
            'docker', 'run', '--rm',
            '-u', $user,
            '-v', project_root() . ':/srv/app',
            '-w', "/srv/app/apps/$app",
            '-e', 'COMPOSER_HOME=/tmp/composer',
            $image,
            'composer', 'install', '--no-interaction', '--prefer-dist',
        ], context: long());
    }

    ok('every app has its vendor/');
}

#[AsTask(name: 'up', description: 'Build the image, install the dependencies, start the stack but the MCP server\'s consumers')]
function up(bool $skip_build = false): void
{
    \guard_host();
    io()->section('Containers');

    if (!$skip_build || !image_exists(php_image())) {
        run(compose('build', 'shop'), context: long());
    }

    // The image has to exist before this, and vendor/ has to exist before the
    // stack comes up - hence this order.
    deps();

    // Not the MCP server's consumers: they read its tables as soon as they
    // start, and on a fresh volume nothing has migrated its database yet.
    // `castor mcp:db` starts them once it has; `init` then waits for them.
    run(compose('up', '-d', '--wait', '--wait-timeout', '240', ...services_but_mcp_consumers()), context: long());

    // The shop's Admin API signs its JWTs with these; gitignored, so a fresh
    // clone has none. Idempotent.
    run(console('shop', 'lexik:jwt:generate-keypair', '--skip-if-exists'), context: long());

    ok('stack is up');
}

/**
 * Every service of the stack (its overlays included) but the MCP server's
 * consumers.
 *
 * @return list<string>
 */
function services_but_mcp_consumers(): array
{
    $services = array_filter(array_map(trim(...), explode("\n", capture(compose('config', '--services')))));

    return array_values(array_diff($services, \mcp\CONSUMERS));
}

// ---------------------------------------------------------------------------
// Verification and summary
// ---------------------------------------------------------------------------

#[AsTask(name: 'verify', description: 'Prove it works: every app answers, grants match the realm')]
function verify(): int
{
    \guard_host();
    io()->section('Verification');

    $failures = [];

    if (wait_for_http(SHOP_BASE . '/en_US/', 180, label: 'the shop (first hit compiles the container)')) {
        ok('the shop responds on ' . SHOP_BASE . '/en_US/');
    } else {
        $failures[] = 'The shop did not answer on ' . SHOP_BASE . '/en_US/ - see: castor docker:logs shop';
    }

    if (200 === http_status(KEYCLOAK_BASE . '/realms/sylius/.well-known/openid-configuration')) {
        ok('Keycloak serves the sylius realm');
    } else {
        $failures[] = 'Keycloak does not serve the sylius realm - see: castor keycloak:init';
    }

    // The MCP endpoint refuses anonymous calls and tells the client where to
    // authenticate (RFC 9728), and the metadata it points at is public.
    $challenge = http_headers(MCP_BASE . '/_mcp');
    if (401 === $challenge['status'] && str_contains($challenge['www-authenticate'] ?? '', 'resource_metadata=')) {
        ok('/_mcp is gated: 401 with the RFC 9728 pointer');
    } else {
        $failures[] = \sprintf('%s/_mcp answered %d without the resource_metadata challenge - see: castor docker:logs mcp-server', MCP_BASE, $challenge['status']);
    }
    if (200 === http_status(MCP_BASE . '/.well-known/oauth-protected-resource')) {
        ok('the protected-resource metadata is served');
    } else {
        $failures[] = 'GET ' . MCP_BASE . '/.well-known/oauth-protected-resource did not answer 200';
    }

    $files = compose_files();
    if (\in_array(\chat\OVERLAY, $files, true)) {
        if (200 === http_status(CHAT_BASE . '/healthz')) {
            ok('the chat answers on ' . CHAT_BASE);
        } else {
            $failures[] = 'The chat does not answer on ' . CHAT_BASE . '/healthz - see: castor docker:logs chat';
        }
    }

    // The realm hands out MCP grants as realm roles; the server reads them
    // from the JWT. Proves users, roles and the dev-cli client survived import.
    $roles = \keycloak\token_realm_roles('catalog-manager', 'catalog-manager');
    if (\in_array('mcp:sylius:product:write', $roles, true)) {
        ok('catalog-manager\'s token carries mcp:sylius:product:write');
    } else {
        $failures[] = 'catalog-manager\'s token does not carry mcp:sylius:product:write - re-import the realm: castor keycloak:init --recreate';
    }

    // MCP_SHOP_EXTENSIONS against the extensions the shop publishes, with the
    // service account: caught at install, not in the middle of a conversation.
    if (\mcp\shop_extensions_check()) {
        ok('the shop publishes every extension MCP_SHOP_EXTENSIONS activates');
    } else {
        $failures[] = 'mcp:shop-extensions:check failed (see above): an extension of [apps.mcp-server] shop_extensions the shop does not publish, or the shop unreachable - castor mcp:shop-extensions to re-run it';
    }

    // MCP_SYLIUS_VERSION against the version the shop publishes: configured,
    // not discovered, so a shop upgraded alone is caught here.
    if (\mcp\sylius_version_check()) {
        ok('MCP_SYLIUS_VERSION matches the shop\'s Sylius version');
    } else {
        $failures[] = 'mcp:sylius-version:check failed (see above): [apps.mcp-server] sylius_version is not the shop\'s Sylius line, or the shop unreachable - castor mcp:sylius-version to re-run it';
    }

    foreach ($failures as $failure) {
        io()->error($failure);
    }

    // A warning, not a failure: the stack runs without the key (the chat's
    // other providers work), but its absence would otherwise only show at the
    // first model call.
    $missingKey = anthropic_key_problem($files);
    if (null === $missingKey) {
        ok('the Anthropic key reaches the MCP server' . (\in_array(\chat\OVERLAY, $files, true) ? ' and the chat' : ''));
    } else {
        io()->warning($missingKey);
    }

    return [] === $failures ? 0 : 1;
}

/**
 * Why the Anthropic key does not reach the containers that use it, or null
 * when it does. The key goes from .project.local.ini ([infrastructure.ai.anthropic]
 * api_key) to docker/.env (TESTCHAT_ANTHROPIC_API_KEY), then to the chat under
 * that name and to the MCP server (and its workers, which extend it) as
 * MCP_AI_ANTHROPIC_API_KEY (compose.yaml). Its value is never printed: the
 * test runs in each container, on that container's own environment.
 *
 * @param list<string> $files
 */
function anthropic_key_problem(array $files): ?string
{
    $consequence = 'Without it, the chat\'s Anthropic models, the background tasks and the fraud agent fail at their first model call.';

    $values = is_file(dotenv_path()) ? load_dot_env(dotenv_path()) : [];
    $key = trim((string) ($values['MCP_AI_ANTHROPIC_API_KEY'] ?? '')) ?: trim((string) ($values['TESTCHAT_ANTHROPIC_API_KEY'] ?? ''));
    if ('' === $key || str_starts_with($key, 'sk-ant-...')) {
        return "No Anthropic API key. Set it in .project.local.ini:\n"
            . "  [infrastructure.ai.anthropic]\n"
            . "  api_key = sk-ant-...\n"
            . "then run `castor init:env && castor docker:up`. $consequence";
    }

    $variables = ['mcp-server' => 'MCP_AI_ANTHROPIC_API_KEY'];
    if (\in_array(\chat\OVERLAY, $files, true)) {
        $variables['chat'] = 'TESTCHAT_ANTHROPIC_API_KEY';
    }

    $without = [];
    foreach ($variables as $service => $variable) {
        if (!run(in($service, 'sh', '-c', \sprintf('test -n "$%s"', $variable)), context: quiet())->isSuccessful()) {
            $without[] = $service;
        }
    }

    return [] === $without ? null : \sprintf(
        "The Anthropic key is in docker/.env but not in the %s container%s: run `castor docker:up` to recreate %s. $consequence",
        implode(' and ', $without),
        1 === \count($without) ? '' : 's',
        1 === \count($without) ? 'it' : 'them',
    );
}

#[AsTask(name: 'info', description: 'Print the URLs and credentials of the running stack')]
function info(): void
{
    $values = is_file(dotenv_path()) ? load_dot_env(dotenv_path()) : [];

    $files = compose_files();

    io()->definitionList(...[
        ['Shop' => SHOP_BASE . '/en_US/'],
        ['Admin' => SHOP_BASE . '/admin - sylius@example.com / admin'],
        ['MCP server' => MCP_BASE . '/_mcp - Keycloak bearer (OAuth 2.1 + PKCE): client mcp-llm for Claude Web / ChatGPT, mcp-cli for Claude Code (.mcp.json)'],
        ['Keycloak' => KEYCLOAK_BASE . '/admin - admin / admin (realm sylius)'],
        ...demo_users(),
        ['Mailpit' => 'http://localhost:8025'],
        ...(\in_array(\chat\OVERLAY, $files, true) ? [
            ['Chat' => \sprintf('%s - castor chat:info for the LLM keys', CHAT_BASE)],
        ] : []),
    ]);

    io()->writeln([
        '  <comment>Next</comment>',
        '    castor shop:console <cmd>   the shop\'s Symfony console (mcp:console, chat:console likewise)',
        '    castor shop:db              re-seed the shop database only',
        '    castor mcp:permissions      what the tools require, what the realm hands out',
        '    castor tunnel:up            expose the MCP server to Claude Web / ChatGPT',
        '    castor quality:all          every quality gate',
        '    castor init:verify          re-run the checks above',
        '    castor docker:down          stop everything',
        '',
    ]);
}

/**
 * @return list<array<string, string>>
 */
function demo_users(): array
{
    return [
        ['Demo users' => 'password = username'],
        ['  full admin' => 'admin'],
        ['  catalogue' => 'catalog-reader · catalog-manager · catalog-admin'],
        ['  configuration' => 'config-reader · config-manager'],
        ['  other themes' => 'marketing-manager · auditor · sales-reader · customer-reader'],
        ['  edge cases' => 'product-reader (one atomic grant) · noaccess (valid identity, zero rights)'],
    ];
}

// ---------------------------------------------------------------------------
// The headline task
// ---------------------------------------------------------------------------

/**
 * Every flag defaults to false on purpose: a Castor bool parameter defaulting
 * to true produces an option that cannot be turned off.
 */
#[AsTask(
    name: 'init',
    namespace: '',
    description: 'Fresh clone → running stack: environment, containers, databases, assets - then verify',
)]
function init(
    bool $skip_build = false,
    bool $skip_db = false,
    bool $no_fixtures = false,
    bool $skip_assets = false,
    bool $skip_hosts = false,
    bool $yes = false,
): int {
    \guard_host();
    $started = microtime(true);

    io()->title('sylius-assistant-stack');

    if (0 !== preflight($skip_hosts, $yes)) {
        return 1;
    }

    env();
    up($skip_build);
    \keycloak\init();

    if (!$skip_db) {
        \shop\db($no_fixtures, $yes);
        \mcp\db();
    }
    // Everything, the MCP server's consumers included, now that its database
    // is migrated.
    run(compose('up', '-d', '--wait', '--wait-timeout', '240'), context: long());
    if (!$skip_assets) {
        \shop\assets();
    }

    $status = verify();

    // The summary prints even on a red check: someone who just cloned should
    // still get their URLs, and the failure is already on screen above.
    io()->newLine();
    0 === $status
        ? io()->success(\sprintf('Ready in %ds.', (int) (microtime(true) - $started)))
        : io()->warning(\sprintf('Up in %ds, but some checks failed - see above.', (int) (microtime(true) - $started)));

    info();

    return $status;
}
