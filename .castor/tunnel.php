<?php

declare(strict_types=1);

namespace tunnel;

use Castor\Attribute\AsTask;
use function Castor\capture;
use function Castor\http_request;
use function Castor\io;
use function Castor\run;
use function init\compose;
use function init\demo_users;
use function init\dotenv;
use function init\dotenv_path;
use function init\long;
use function init\ok;
use function init\quiet;
use function keycloak\await_realm;
use function keycloak\token as keycloak_token;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Real-world test of /_mcp from a remote MCP client (Claude Web/Desktop,
 * ChatGPT) through Cloudflare quick tunnels - see compose.tunnel.yaml for why
 * there are two of them and why the URLs travel through docker/.env.
 */
const BLOCK_START = '# -- tunnel (managed by `castor tunnel:up` / `tunnel:down`, dropped by `init:env`) --';
const BLOCK_END = '# -- /tunnel --';
const OVERLAY = 'compose.tunnel.yaml';
const URL_PATTERN = '#https://[a-z0-9-]+\.trycloudflare\.com#';

#[AsTask(description: 'Expose the MCP server and Keycloak through Cloudflare quick tunnels, then print the connector config')]
function up(): void
{
    \guard_host();
    io()->section('Tunnel');

    // 1. Overlay on, URLs unknown: the `:-` defaults in compose.tunnel.yaml
    //    keep mcp-server/keycloak on their local config until the URLs exist.
    write_env_block([]);

    // 2. Start the tunnels alone; each prints its random hostname on boot.
    //    Recreated, not restarted: a container left from a previous run keeps
    //    its log, whose first URL is the dead one of that run.
    run(compose('up', '-d', '--force-recreate', 'tunnel-mcp', 'tunnel-keycloak'), context: long());

    $mcpUrl = tunnel_url('tunnel-mcp');
    $kcUrl = tunnel_url('tunnel-keycloak');
    ok("mcp-server  $mcpUrl");
    ok("keycloak    $kcUrl");

    // 3. Now the real values: the issuer Keycloak advertises and the server
    //    forwards to the client, plus trusted proxies - a recreate for both.
    write_env_block(['TUNNEL_MCP_URL' => $mcpUrl, 'TUNNEL_KEYCLOAK_URL' => $kcUrl]);
    run(compose('up', '-d', '--force-recreate', 'keycloak', 'mcp-server'), context: long());
    await_realm();
    forget_discovery();


    print_mcp_config($mcpUrl, $kcUrl);
}

#[AsTask(description: 'Tear the tunnels down and restore the local-only configuration')]
function down(): void
{
    \guard_host();
    io()->section('Tunnel');

    if (!has_block()) {
        io()->note('No tunnel is configured (docker/.env has no managed block). Nothing to do.');

        return;
    }

    // Stop the tunnels while the overlay is still in COMPOSE_FILE, so compose
    // knows the services; then drop the block and put shop/keycloak back.
    run(compose('rm', '-sf', 'tunnel-mcp', 'tunnel-keycloak', 'keycloak-public'), context: long());
    write_env_block(null);
    // Castor loaded docker/.env - tunnel block included - into its own
    // environment at startup, and compose lets the process environment win
    // over --env-file: blank the variables for this recreate or the containers
    // keep the public issuer.
    run(
        compose('up', '-d', '--force-recreate', 'keycloak', 'mcp-server', 'shop'),
        context: long()->withEnvironment(['TUNNEL_MCP_URL' => '', 'TUNNEL_KEYCLOAK_URL' => '']),
    );
    await_realm();
    forget_discovery();

    ok('tunnel closed - the stack is back on http://localhost:8081 / http://keycloak:8085');
}

#[AsTask(description: 'Print the public URLs and the MCP connector configuration of the running tunnel')]
function info(): void
{
    \guard_host();

    $env = dotenv();
    if (!isset($env['TUNNEL_MCP_URL'], $env['TUNNEL_KEYCLOAK_URL'])) {
        throw new \RuntimeException('No tunnel is running. Start one with: castor tunnel:up');
    }

    print_mcp_config($env['TUNNEL_MCP_URL'], $env['TUNNEL_KEYCLOAK_URL']);
}

// ---------------------------------------------------------------------------
// Plumbing
// ---------------------------------------------------------------------------

/**
 * cloudflared prints "Your quick Tunnel has been created! Visit it at" followed
 * by the hostname within a few seconds of boot. The QUIC handshake (UDP 7844)
 * is the usual reason it never shows up.
 */
function tunnel_url(string $service, int $timeoutSeconds = 60): string
{
    $deadline = microtime(true) + $timeoutSeconds;
    io()->write("  waiting for $service ");

    do {
        $logs = capture(compose('logs', '--no-color', '--no-log-prefix', $service), context: quiet(), onFailure: '');
        // The last one: a restarted container logs every URL it ever got.
        if (preg_match_all(URL_PATTERN, $logs, $m) > 0) {
            io()->newLine();

            return end($m[0]);
        }
        io()->write('.');
        usleep(1_000_000);
    } while (microtime(true) < $deadline);

    io()->newLine();

    throw new \RuntimeException(
        "$service did not announce a trycloudflare.com URL within {$timeoutSeconds}s.\n"
        . "  Inspect it with: castor docker:logs $service\n"
        . '  If the QUIC handshake is blocked (UDP 7844), add "--protocol", "http2" to its command in ' . OVERLAY . '.',
    );
}

function has_block(): bool
{
    return str_contains((string) file_get_contents(dotenv_path()), BLOCK_START);
}

/**
 * Rewrites the managed block at the end of docker/.env. Compose keeps the LAST
 * definition of a key, so re-declaring COMPOSE_FILE there is enough to append
 * the overlay. `null` removes the block altogether.
 *
 * @param array<string, string>|null $urls
 */
function write_env_block(?array $urls): void
{
    $path = dotenv_path();
    $content = (string) file_get_contents($path);

    $start = strpos($content, BLOCK_START);
    if (false !== $start) {
        $end = strpos($content, BLOCK_END, $start);
        $content = rtrim(substr($content, 0, $start)) . "\n"
            . (false === $end ? '' : ltrim(substr($content, $end + \strlen(BLOCK_END)), "\n"));
    }

    if (null !== $urls) {
        $files = array_values(array_filter(explode(':', dotenv()['COMPOSE_FILE'] ?? 'compose.yaml:compose.dev.yaml'), static fn (string $f): bool => OVERLAY !== $f));
        $files[] = OVERLAY;

        $lines = [BLOCK_START, 'COMPOSE_FILE=' . implode(':', $files)];
        foreach ($urls as $key => $value) {
            $lines[] = "$key=$value";
        }
        $lines[] = BLOCK_END;

        $content = rtrim($content) . "\n\n" . implode("\n", $lines) . "\n";
    }

    file_put_contents($path, $content);
}

/**
 * The chat caches the authorization server it discovered from the MCP server's
 * metadata for an hour; the issuer just changed under it.
 */
function forget_discovery(): void
{
    if (!\in_array(\chat\OVERLAY, \init\compose_files(), true)) {
        return;
    }
    run(compose('exec', '-T', 'chat', 'php', 'bin/console', 'cache:pool:clear', 'cache.app'), context: quiet()->withAllowFailure());
    ok('chat discovery cache cleared');
}

/**
 * The paste-ready connector configuration, for every client that matters.
 */
function print_mcp_config(string $mcpUrl, string $kcUrl): void
{
    $mcp = $mcpUrl . '/_mcp';
    $json = json_encode(['mcpServers' => ['sylius-mcp' => ['type' => 'http', 'url' => $mcp, 'oauth' => ['clientId' => 'mcp-cli']]]], \JSON_UNESCAPED_SLASHES);

    io()->section('MCP endpoint');
    io()->definitionList(
        ['MCP URL' => $mcp],
        ['Metadata' => $mcpUrl . '/.well-known/oauth-protected-resource'],
        ['Keycloak' => $kcUrl . '/realms/sylius - this realm only; the admin console stays local (' . \init\KEYCLOAK_BASE . '/admin)'],
        ['Shop' => 'http://localhost/en_US/ - local only, nothing remote needs it'],
    );

    io()->section('Claude Web / Desktop - Settings → Connectors → Add custom connector');
    io()->definitionList(
        ['Name' => 'Sylius MCP (dev tunnel)'],
        ['Remote MCP server URL' => $mcp],
        ['OAuth Client ID' => 'mcp-llm   (Advanced settings - REQUIRED: the realm refuses anonymous DCR)'],
        ['OAuth Client Secret' => '(leave empty - public client, PKCE)'],
    );

    io()->section('ChatGPT - Settings → Connectors → Create');
    io()->definitionList(
        ['Name' => 'Sylius MCP (dev tunnel)'],
        ['MCP Server URL' => $mcp],
        ['Authentication' => 'OAuth'],
        ['OAuth Client ID' => 'mcp-llm'],
        ['OAuth Client Secret' => '(leave empty)'],
    );

    io()->section('Claude Code / JSON clients - OAuth client mcp-cli (loopback callback), then /mcp to log in');
    io()->writeln([
        "  claude mcp add --transport http --client-id mcp-cli sylius-mcp $mcp",
        '',
        "  $json",
        '',
    ]);

    io()->section('Keycloak accounts for the consent screen');
    io()->definitionList(...[...demo_users(), new TableSeparator(), ['Stop' => 'castor tunnel:down']]);
    io()->warning('While the tunnel is up, anyone who has its URL can log in as a demo user (password = username) and act on the shop with that user\'s grants. Close it with castor tunnel:down when done.');
}
