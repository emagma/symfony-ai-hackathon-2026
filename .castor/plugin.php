<?php

declare(strict_types=1);

/**
 * packages/sylius-mcp-plugin and packages/sylius-mcp-event-plugin,
 * the Sylius plugins the shop installs (the plugin, and the shop's events).
 *
 * The packages have no vendor/ of their own: the shop installs them as path
 * repositories, so their quality tools are the shop's, run from the shop
 * container with each package's own configuration files - the same commands
 * as the `shop` CI job.
 */

namespace plugin;

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\run;
use function init\compose;
use function init\in;
use function init\long;
use function init\ok;

const SERVICE = 'shop';

/** Label => package directory in the container. */
const PACKAGES = [
    'Plugin' => '/srv/app/packages/sylius-mcp-plugin',
    'Plugin events' => '/srv/app/packages/sylius-mcp-event-plugin',
];

#[AsTask(name: 'quality', description: 'ECS + PHPStan + PHPUnit of the plugins (core and events)')]
function quality(): void
{
    \guard_host();
    ecs();
    phpstan();
    phpunit();
}

#[AsTask(name: 'quality:ecs', description: 'Coding standard of the plugins (--fix to apply)')]
function ecs(bool $fix = false): void
{
    \guard_host();
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': ECS');
        run(in(SERVICE, 'vendor/bin/ecs', 'check', '--config', $package . '/ecs.php', ...($fix ? ['--fix'] : [])), context: long());
    }
}

#[AsTask(name: 'quality:phpstan', description: 'Static analysis of the plugins (level max, the core with a baseline)')]
function phpstan(): void
{
    \guard_host();
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': PHPStan');
        run(
            compose('exec', '-T', '-w', $package, SERVICE, '../../apps/shop/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--no-progress', '--memory-limit=1G'),
            context: long(),
        );
    }
}

#[AsTask(name: 'quality:phpunit', description: 'Unit tests of the plugins')]
function phpunit(): void
{
    \guard_host();
    foreach (PACKAGES as $label => $package) {
        io()->section($label . ': PHPUnit');
        run(in(SERVICE, 'vendor/bin/phpunit', '-c', $package . '/phpunit.xml.dist'), context: long());
    }
    ok('plugin tests passed');
}
