#!/usr/bin/env bash
# The quality gate of one package on its own, as a project that installs it
# would see it: in a throwaway copy, its own vendor/ resolved from its
# composer.json, then its PHPUnit and, on the highest dependencies, its ECS and
# PHPStan. Run by bin/ci/packages.sh; by hand too, from anywhere (PHP 8.4 with
# intl, zip, pdo_sqlite, memory_limit=-1; composer; network):
#
#   bin/ci/package.sh mcp-audit-bundle highest
#   bin/ci/package.sh mcp-audit-bundle lowest
#
# highest: the latest versions its constraints allow (Symfony 8.x).
# lowest: `--prefer-lowest`, the floors of its constraints (Symfony 7.4): a
# floor that no longer holds shows here first.
#
# SYLIUS_REQUIRE=2.1.* pins sylius/sylius in the copy (a shop plugin proven on one
# Sylius line; the package's own constraint is replaced there only).
#
# Its emagma dependencies are not on Packagist yet: they are resolved from
# packages/ as path repositories, at the version the applications declare
# (bin/ci/package-repositories.php), in the copy only. Nothing is written in
# the repository.
set -euo pipefail

package="${1:?usage: bin/ci/package.sh <package> <lowest|highest>}"
deps="${2:-highest}"
case "$deps" in
    lowest|highest) ;;
    *) echo "usage: bin/ci/package.sh <package> <lowest|highest>" >&2; exit 2 ;;
esac

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src="$root/packages/$package"
if [ ! -f "$src/composer.json" ]; then
    echo "No package packages/$package" >&2
    exit 2
fi
export LC_ALL=C
export COMPOSER_NO_INTERACTION="${COMPOSER_NO_INTERACTION:-1}"
# A copy has no history to read the root version from.
export COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-dev-main}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The package as it would be published: no vendor/, no lock, no cache.
tar -C "$src" --exclude=./vendor --exclude=./composer.lock --exclude=./.phpunit.cache -cf - . | tar -C "$work" -xf -
php "$root/bin/ci/package-repositories.php" "$root" "$work/composer.json"

if [ -n "${SYLIUS_REQUIRE:-}" ]; then
    php -r '
        $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        if (!isset($manifest["require"]["sylius/sylius"])) {
            fwrite(STDERR, "SYLIUS_REQUIRE given, but the package does not require sylius/sylius\n");
            exit(2);
        }
        $manifest["require"]["sylius/sylius"] = $argv[2];
        file_put_contents($argv[1], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    ' "$work/composer.json" "$SYLIUS_REQUIRE"
fi

cd "$work"
flags=(--prefer-dist --no-progress)
if [ "$deps" = lowest ]; then
    flags+=(--prefer-lowest --prefer-stable)
fi
composer update "${flags[@]}"

version="$(composer show symfony/http-kernel | awk '$1 == "versions" { print $NF }')"
echo "packages/$package, $deps dependencies: Symfony ${version#v} (symfony/http-kernel)${SYLIUS_REQUIRE:+, Sylius $SYLIUS_REQUIRE}"

vendor/bin/phpunit
if [ "$deps" = highest ]; then
    vendor/bin/ecs check --no-progress-bar
    vendor/bin/phpstan analyse --no-progress --memory-limit=1G
fi
