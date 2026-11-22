#!/usr/bin/env bash
# The quality gate of the shop and of the plugins it installs: ECS and PHPStan
# of the shop, then ECS, PHPStan and PHPUnit of each plugin. The shop job of
# the CI; runs by hand too, from anywhere (PHP 8.4 with
# intl, zip, gd, exif, memory_limit=-1; composer; network):
#
#   bin/ci/shop.sh
#
# With SYMFONY_REQUIRE=8.1.* (the CI's Symfony 8.1 run), the dependencies are resolved with `composer update -W` on
# Symfony 8.1 instead of installed from the lock, then the same steps run. It
# rewrites tracked files: CI only, or CI_ALLOW_COMPOSER_UPDATE=1 on a
# throwaway copy (bin/ci/lib.sh).
#
# The plugins are discovered, not listed: every packages/sylius-mcp*-plugin
# present but the 1.14 one (-sylius1-), which needs a Sylius 1.14 vendor/ of its own
# (packages/sylius-mcp-sylius1-plugin/ci/quality.sh). They have no vendor/ of their
# own: the shop installs them as path repositories, so they are checked with
# the shop's tools and their own configuration files.
set -euo pipefail

# shellcheck source=bin/ci/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
ci_guard_symfony_require

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
export LC_ALL=C
export COMPOSER_NO_INTERACTION="${COMPOSER_NO_INTERACTION:-1}"

# The names below only fix the order: one that is absent is skipped, and a
# plugin they do not name is checked after them, in name order. Adding a
# plugin needs no edit here.
plugins=()
for plugin in sylius-mcp-plugin sylius-mcp-cms-plugin sylius-mcp-event-plugin; do
    if [ -d "packages/$plugin" ]; then
        plugins+=("$plugin")
    fi
done
for dir in packages/sylius-mcp*-plugin; do
    if [ -d "$dir" ] && [[ "$dir" != *-sylius1-* ]] && [[ " ${plugins[*]} " != *" $(basename "$dir") "* ]]; then
        plugins+=("$(basename "$dir")")
    fi
done
echo "Plugins (${#plugins[@]}): ${plugins[*]}"

cd apps/shop
ci_install_dependencies
vendor/bin/ecs check --no-progress-bar
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

for plugin in "${plugins[@]}"; do
    echo "::: packages/$plugin"
    vendor/bin/ecs check --no-progress-bar --config "../../packages/$plugin/ecs.php"
    (cd "../../packages/$plugin" && ../../apps/shop/vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G)
    vendor/bin/phpunit -c "../../packages/$plugin/phpunit.xml.dist"
done
