# shellcheck shell=bash
# Sourced by bin/ci/mcp-server.sh and bin/ci/shop.sh: how an app's
# dependencies are installed, from the lock or on another Symfony version.
#
# SYMFONY_REQUIRE unset (or empty): `composer install`, the lock, Symfony 7.4.
# SYMFONY_REQUIRE=8.1.*: `composer update -W` instead, Flex holding every
# symfony/* package to that version (it reads SYMFONY_REQUIRE before
# extra.symfony.require). That proves the packages on Symfony 8.1 while the
# apps' locks stay on 7.4. It rewrites tracked files (composer.json through
# bump-after-update, composer.lock, symfony.lock, and the shop's bundles.php,
# assets/controllers.json and package.json through Flex recipes): it runs only
# in CI (CI=true), whose checkout is thrown away, or with
# CI_ALLOW_COMPOSER_UPDATE=1 on a copy of the repository.

# Refuses the SYMFONY_REQUIRE mode outside CI unless allowed. Call it before
# anything else.
ci_guard_symfony_require() {
    if [ -z "${SYMFONY_REQUIRE:-}" ]; then
        return 0
    fi
    if [ "${CI:-}" = true ] || [ "${CI_ALLOW_COMPOSER_UPDATE:-}" = 1 ]; then
        return 0
    fi
    echo "SYMFONY_REQUIRE=$SYMFONY_REQUIRE runs composer update -W, which rewrites composer.json, composer.lock and Flex recipe files." >&2
    echo "Refused outside CI (CI=true). On a throwaway copy of the repository, set CI_ALLOW_COMPOSER_UPDATE=1." >&2
    exit 1
}

# Installs the dependencies of the app in the current directory, then prints
# the Symfony version installed and checks it against SYMFONY_REQUIRE.
ci_install_dependencies() {
    # --no-scripts: no cache:clear of the dev kernel; the tests boot their own.
    if [ -z "${SYMFONY_REQUIRE:-}" ]; then
        composer install --prefer-dist --no-progress --no-scripts
    else
        # Plugins stay enabled: Flex is what applies SYMFONY_REQUIRE.
        composer update -W --prefer-dist --no-progress --no-scripts
    fi

    local version
    version="$(composer show symfony/http-kernel | awk '$1 == "versions" { print $NF }')"
    version="${version#v}"
    echo "Symfony installed: $version (symfony/http-kernel; SYMFONY_REQUIRE=${SYMFONY_REQUIRE:-unset, from the lock})"
    if [ -z "$version" ]; then
        echo "symfony/http-kernel is not installed" >&2
        exit 1
    fi
    if [ -n "${SYMFONY_REQUIRE:-}" ] && [[ "$SYMFONY_REQUIRE" =~ ^[0-9]+\.[0-9]+\.\*$ ]] \
        && [[ "$version" != "${SYMFONY_REQUIRE%\*}"* ]]; then
        echo "Symfony $version does not match SYMFONY_REQUIRE=$SYMFONY_REQUIRE" >&2
        exit 1
    fi
}
