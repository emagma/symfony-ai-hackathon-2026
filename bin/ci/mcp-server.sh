#!/usr/bin/env bash
# The quality gate of the MCP server and of its bundles: ECS, PHPStan, deptrac,
# PHPUnit (the functional suite included: its test kernel needs only SQLite and
# a fake Keycloak). The `mcp-server` job of the CI; runs by hand too, from anywhere (PHP 8.4 with
# intl, zip, gd, exif, pdo_sqlite, memory_limit=-1; composer; network):
#
#   bin/ci/mcp-server.sh
#
# With SYMFONY_REQUIRE=8.1.* (the CI's Symfony 8.1 run), the dependencies are resolved with `composer update -W`
# on Symfony 8.1 instead of installed from the lock, then the same steps run.
# It rewrites tracked files: CI only, or CI_ALLOW_COMPOSER_UPDATE=1 on a
# throwaway copy (bin/ci/lib.sh).
#
# The bundles are discovered, not listed: every packages/mcp-*-bundle present,
# and the hackathon bundle. They have no vendor/ of their own: installed as
# path repositories, they are checked with the server's tools and their own
# configuration files.
set -euo pipefail

# shellcheck source=bin/ci/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
ci_guard_symfony_require

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
export LC_ALL=C
export COMPOSER_NO_INTERACTION="${COMPOSER_NO_INTERACTION:-1}"

# The names below only fix the order (the core first, the hackathon bundle
# last): one that is absent is skipped, and a bundle they do not name is
# checked after them, in name order. Adding a bundle needs no edit here.
packages=()
for package in mcp-core-bundle mcp-auth-bundle mcp-auth-keycloak-bundle mcp-audit-bundle \
    mcp-telemetry-bundle mcp-telemetry-langfuse-bundle mcp-sylius-bundle mcp-sylius-v1-bundle \
    mcp-sylius-v2-bundle mcp-sylius-cms-bundle mcp-guidelines-bundle mcp-tasks-bundle \
    mcp-tasks-messenger-bundle mcp-sylius-events-bundle mcp-agents-bundle mcp-knowledge-bundle \
    mcp-screens-bundle mcp-sylius-screens-bundle mcp-skills-bundle; do
    if [ -d "packages/$package" ]; then
        packages+=("$package")
    fi
done
for dir in packages/mcp-*-bundle; do
    if [ -d "$dir" ] && [[ " ${packages[*]} " != *" $(basename "$dir") "* ]]; then
        packages+=("$(basename "$dir")")
    fi
done
if [ -d packages/symfony-ai-hackathon-2026-bundle ]; then
    packages+=(symfony-ai-hackathon-2026-bundle)
fi
cms=false
if [ -d packages/mcp-sylius-cms-bundle ]; then
    cms=true
fi
echo "Bundles (${#packages[@]}): ${packages[*]}"
echo "Functional pass with MCP_SHOP_EXTENSIONS=sylius_cms: $cms"

# Once, for every package: the classes are in the folder of their role.
bin/ci/audit-layout.sh

cd apps/mcp-server
# No composer scripts: no cache:clear of the dev kernel (VAR_DUMPER_SERVER has
# no default); PHPUnit boots its own test kernel.
ci_install_dependencies
vendor/bin/ecs check --no-progress-bar
# The baseline freezes the existing debt: red on a NEW error.
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/deptrac analyse --no-progress
vendor/bin/phpunit

for package in "${packages[@]}"; do
    echo "::: packages/$package"
    vendor/bin/ecs check --no-progress-bar --config "../../packages/$package/ecs.php"
    (cd "../../packages/$package" && ../../apps/mcp-server/vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G)
    vendor/bin/phpunit -c "../../packages/$package/phpunit.xml.dist"
done

# Second functional pass, every shop extension on: MCP_SHOP_EXTENSIONS is read
# at runtime (%env()%), the same container cache serves both passes (proved by
# McpEndpointTest). Only where the extension's bundle exists.
if [ "$cms" = true ]; then
    MCP_SHOP_EXTENSIONS=sylius_cms vendor/bin/phpunit --testsuite Functional
fi
