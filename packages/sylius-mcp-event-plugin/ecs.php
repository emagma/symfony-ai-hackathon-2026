<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

// Alone: `vendor/bin/ecs check`. Inside a shop that installs the plugin: its ECS with `--config` this
// file, from the shop's directory: the coding standard is the one of the vendor/ ECS runs from.
return ECSConfig::configure()
    // Explicit root files: withRootFiles() would pick the shop's (the cwd).
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/ecs.php',
    ])
    ->withSets([getcwd() . '/vendor/sylius-labs/coding-standard/ecs.php'])
;
