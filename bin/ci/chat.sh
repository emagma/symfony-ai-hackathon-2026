#!/usr/bin/env bash
# The quality gate of the test chat: PHPStan, PHPUnit, then the JavaScript
# tests and a syntax check of public/app.js. The `chat` job of the CI; runs by
# hand too, from anywhere
# (PHP 8.4 with intl, zip, memory_limit=-1; composer; Node 22; network):
#
#   bin/ci/chat.sh
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
export COMPOSER_NO_INTERACTION="${COMPOSER_NO_INTERACTION:-1}"

cd apps/chat
composer install --prefer-dist --no-progress
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpunit
node --test 'tests/js/*.test.js'
node --check public/app.js
