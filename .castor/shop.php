<?php

declare(strict_types=1);

namespace shop;

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

const SERVICE = 'shop';

#[AsTask(name: 'console', description: 'The shop\'s Symfony console (all args forwarded)')]
function console_(#[AsRawTokens] array $rawTokens = []): void
{
    \guard_host();

    run(compose('exec', SERVICE, 'php', 'bin/console', ...$rawTokens), context: context()->withTty());
}

#[AsTask(name: 'shell', description: 'A bash shell in the shop container')]
function shell(): void
{
    \guard_host();

    run(compose('exec', SERVICE, 'bash'), context: context()->withTty());
}

#[AsTask(name: 'db', description: 'Recreate the shop database: migrations and fixtures')]
function db(bool $no_fixtures = false, bool $yes = false): void
{
    \guard_host();
    io()->section('Shop database');

    if (!$yes && row_count() > 0 &&
        !io()->confirm('The shop database already holds data. Drop it and re-seed?', false)) {
        io()->note('Database left untouched.');

        return;
    }

    // The Messenger consumer auto-creates messenger_messages the moment the
    // database exists, and the Sylius migration that creates it then fails
    // (1050). Keep it out of the way while the schema is rebuilt.
    run(compose('stop', 'shop-worker'), context: long());
    run(console(SERVICE, 'doctrine:database:drop', '--force', '--if-exists'), context: long());
    run(console(SERVICE, 'doctrine:database:create'), context: long());
    run(console(SERVICE, 'doctrine:migrations:migrate', '-n'), context: long());
    run(compose('start', 'shop-worker'), context: long());

    if ($no_fixtures) {
        io()->note('Fixtures skipped - the shop is empty but for the MCP service account.');
    } else {
        // The Sylius core `default` suite: channels, products, customers, orders.
        // The CMS plugin's `cms` suite is NOT loaded on top of it: it recreates
        // the `category` root taxon and fails on the slug unique index. It is
        // written for an empty shop (`castor shop:db --no-fixtures`, then
        // `castor shop:console sylius:fixtures:load cms`).
        run(console(SERVICE, 'sylius:fixtures:load', '-n'), context: long());
    }

    service_account();
    ok('shop database seeded');
}

#[AsTask(name: 'service-account', description: 'Create (or reset) the admin account the MCP server calls the Admin API with')]
function service_account(): void
{
    \guard_host();
    // Idempotent (the plugin's command), credentials from MCP_SERVICE_ACCOUNT_*.
    run(console(SERVICE, 'sylius-mcp:service-account'), context: long());
}

/**
 * -1 when the question cannot be answered (no database yet, stack down), which
 * the caller treats as "nothing to lose".
 *
 * The CONCAT sentinel is not decoration: a bare COUNT(*) would be
 * indistinguishable from the digits in a connection error message.
 */
function row_count(): int
{
    $out = capture(
        console(SERVICE, 'dbal:run-sql', '--no-ansi', "SELECT CONCAT('ROWS=', COUNT(*)) FROM sylius_product"),
        context: quiet(),
        onFailure: '',
    );

    return 1 === preg_match('/ROWS=(\d+)/', $out, $m) ? (int) $m[1] : -1;
}

#[AsTask(name: 'assets',description: 'yarn install + build in the shop, then assets:install')]
function assets(): void
{
    \guard_host();
    io()->section('Shop assets');

    // Node 20 and yarn ship in the php image - there is no node service.
    run(in(SERVICE, 'sh', '-c', 'yarn install && yarn build'), context: long());
    run(console(SERVICE, 'assets:install'), context: long());

    ok('shop assets built');
}

#[AsTask(name: 'quality', description: 'ECS + PHPStan + PHPUnit of the shop app')]
function quality(): void
{
    \guard_host();

    run(in(SERVICE, 'vendor/bin/ecs', 'check'), context: long());
    run(in(SERVICE, 'vendor/bin/phpstan', 'analyse', '--no-progress'), context: long());
    run(in(SERVICE, 'vendor/bin/phpunit'), context: long());
}
