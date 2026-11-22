<?php

declare(strict_types=1);

// The autoloader of the PHPUnit that runs: the package's own vendor/, or the shop's that installs
// the plugin. The latter does not cover a dependency's tests: their namespace is added here.
$loader = require \defined('PHPUNIT_COMPOSER_INSTALL') ? PHPUNIT_COMPOSER_INSTALL : __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Emagma\\SyliusMcpPlugin\\Tests\\', __DIR__);
