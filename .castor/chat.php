<?php

declare(strict_types=1);

/**
 * apps/chat - the local multi-LLM test chat, an MCP client of the MCP server.
 * Its own composer root (compose.chat.yaml says why), its own container.
 */

namespace chat;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\run;
use function init\compose;
use function init\compose_files;
use function init\dotenv;
use function init\long;
use function init\ok;

const SERVICE = 'chat';
const OVERLAY = 'compose.chat.yaml';
const PROVIDERS = [
    'OpenAI' => 'TESTCHAT_OPENAI_API_KEY',
    'Anthropic' => 'TESTCHAT_ANTHROPIC_API_KEY',
    'Mistral' => 'TESTCHAT_MISTRAL_API_KEY',
    'Qwen' => 'TESTCHAT_QWEN_API_KEY',
];

function guard_overlay(): void
{
    if (!\in_array(OVERLAY, compose_files(), true)) {
        throw new \RuntimeException(\sprintf(
            "%s is not in COMPOSE_FILE. Add it to `compose_file` in .project.local.ini (or delete that line to\n"
            . 'adopt the .project.ini default), then run `castor init:env`.',
            OVERLAY,
        ));
    }
}

#[AsTask(name: 'up', description: 'Start the chat on http://localhost:8090')]
function up(): void
{
    \guard_host();
    guard_overlay();
    io()->section('Chat');
    run(compose('up', '-d', '--wait', SERVICE), context: long());
    info();
}

/**
 * Keys written into apps/chat/.env are never read - compose sets the
 * variables, and a real environment variable wins over a .env file - and that
 * file is committed. Say so before someone pushes a key.
 */
function warn_misplaced_keys(): void
{
    foreach (['apps/chat/.env', 'apps/chat/.env.local'] as $file) {
        $path = \init\project_root() . '/' . $file;
        if (is_file($path) && 1 === preg_match('/^TESTCHAT_[A-Z]+_API_KEY\s*=\s*\S+/m', (string) file_get_contents($path))) {
            io()->warning(\sprintf(
                "%s holds an API key: it is ignored there%s.\nPut it in .project.local.ini ([infrastructure.ai.<provider>] api_key), then castor init:env && castor chat:up.",
                $file,
                str_ends_with($file, '.local') ? '' : ', and that file is COMMITTED',
            ));
        }
    }
}

#[AsTask(name: 'info', description: 'Print the chat URL and which LLM providers have a key')]
function info(): void
{
    warn_misplaced_keys();
    $env = dotenv();

    io()->definitionList(
        ['Chat' => \sprintf('http://localhost:%s - log in with a Keycloak demo user (password = username)', $env['CHAT_PORT'] ?? '8090')],
        ...array_map(
            static fn (string $label, string $key): array => ["  $label" => '' !== ($env[$key] ?? '') ? 'key set' : 'no key - greyed out (set it in .project.local.ini, then castor init:env)'],
            array_keys(PROVIDERS),
            PROVIDERS,
        ),
    );
}

#[AsTask(name: 'quality', description: 'PHPStan (level max), PHPUnit and the JavaScript tests of the chat')]
function quality(): void
{
    \guard_host();
    guard_overlay();
    io()->section('Chat: PHPStan');
    run(compose('exec', '-T', SERVICE, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'), context: long());
    io()->section('Chat: PHPUnit');
    run(compose('exec', '-T', SERVICE, 'vendor/bin/phpunit'), context: long());
    // The same as bin/ci/chat.sh. No shell in between: Node expands the glob.
    io()->section('Chat: JavaScript');
    run(compose('exec', '-T', SERVICE, 'node', '--test', 'tests/js/*.test.js'), context: long());
    run(compose('exec', '-T', SERVICE, 'node', '--check', 'public/app.js'), context: long());
    ok('chat tests passed');
}

#[AsTask(name: 'console', description: 'The chat\'s Symfony console (all args forwarded)')]
function console(#[AsRawTokens] array $rawTokens = []): void
{
    \guard_host();
    run(compose('exec', SERVICE, 'php', 'bin/console', ...$rawTokens), context: long()->withTty());
}
