<?php

declare(strict_types=1);

/*
 * Used by bin/ci/package.sh: adds to a package's composer.json, in its throwaway copy, the path
 * repositories of the other packages, at the version the applications declare for them (D7: one
 * version for all). The emagma dependencies are not on Packagist yet; this is how a package is
 * resolved on its own meanwhile. Never run on a tracked file.
 *
 * usage: php bin/ci/package-repositories.php <repository root> <composer.json of the copy>
 */

[, $root, $target] = $argv + [null, null, null];
if (!is_string($root) || !is_string($target) || !is_file($target)) {
    fwrite(\STDERR, "usage: php bin/ci/package-repositories.php <repository root> <composer.json>\n");

    exit(2);
}

$package = json_decode((string) file_get_contents($target), true, flags: \JSON_THROW_ON_ERROR);
$repositories = [];
foreach (glob($root . '/apps/*/composer.json') ?: [] as $app) {
    $manifest = json_decode((string) file_get_contents($app), true, flags: \JSON_THROW_ON_ERROR);
    foreach ($manifest['repositories'] ?? [] as $repository) {
        if ('path' !== ($repository['type'] ?? null)) {
            continue;
        }
        $url = realpath(dirname($app) . '/' . $repository['url']);
        $versions = $repository['options']['versions'] ?? [];
        if (false === $url || [] === $versions || isset($versions[$package['name']])) {
            continue;
        }
        $repositories[$url] = [
            'type' => 'path',
            'url' => $url,
            'only' => array_keys($versions),
            'options' => ['symlink' => true, 'versions' => $versions],
        ];
    }
}

$package['repositories'] = array_values($repositories);
file_put_contents($target, json_encode($package, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
printf("%d path repositories added for %s\n", count($repositories), $package['name']);
