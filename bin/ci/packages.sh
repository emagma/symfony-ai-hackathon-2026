#!/usr/bin/env bash
# Every package that carries its own tools, checked on its own
# (bin/ci/package.sh), on the lowest or the highest dependencies. The
# `packages` jobs of the CI; runs by hand too, from anywhere (PHP 8.4 with
# intl, zip, pdo_sqlite, memory_limit=-1; composer; network):
#
#   bin/ci/packages.sh highest
#   bin/ci/packages.sh lowest
#   SYLIUS_REQUIRE=2.1.* bin/ci/packages.sh highest
#
# With SYLIUS_REQUIRE, only the packages that require sylius/sylius (the shop
# plugins) are checked, with that constraint on sylius/sylius: one Sylius line
# at a time (2.0, 2.1, 2.2, 2.3).
#
# The packages are discovered, not listed: a package is checked here once its
# require-dev holds its own PHPUnit, PHPStan and coding standard. The others
# are still checked with the applications' tools only (bin/ci/mcp-server.sh,
# bin/ci/shop.sh), which check these ones too, on the applications' locks.
# The Sylius 1.14 plugin (-sylius1-) is left to its own job (plugin-1.14): its
# Sylius 1.14 vendor needs api-platform/core 2.7, which Composer 2.9 refuses to
# install because of security advisories.
set -euo pipefail

deps="${1:?usage: bin/ci/packages.sh <lowest|highest>}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

packages=()
for manifest in packages/*/composer.json; do
    [[ "$manifest" == *-sylius1-* ]] && continue
    if php -r '
        $dev = json_decode(file_get_contents($argv[1]), true)["require-dev"] ?? [];
        $sylius = isset(json_decode(file_get_contents($argv[1]), true)["require"]["sylius/sylius"]);
        if ("" !== (string) getenv("SYLIUS_REQUIRE") && !$sylius) {
            exit(1);
        }
        exit(isset($dev["phpunit/phpunit"], $dev["phpstan/phpstan"], $dev["sylius-labs/coding-standard"]) ? 0 : 1);
    ' "$manifest"; then
        packages+=("$(basename "$(dirname "$manifest")")")
    fi
done
echo "Packages on their own (${#packages[@]}), $deps dependencies: ${packages[*]}"

for package in "${packages[@]}"; do
    echo "::: packages/$package ($deps)"
    bin/ci/package.sh "$package" "$deps"
done
