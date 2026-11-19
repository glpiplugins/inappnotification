#!/usr/bin/env bash

#
# -------------------------------------------------------------------------
# InAppNotification plugin for GLPI
#
# https://github.com/glpiplugins/inappnotification
#
# @copyright 2026 Roy Brannath
# @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
# -------------------------------------------------------------------------
#
# LICENSE
#
# This file is part of InAppNotification.
#
# InAppNotification is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# InAppNotification is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with InAppNotification. If not, see <https://www.gnu.org/licenses/>.
# -------------------------------------------------------------------------
#

# All quality checks of the plugin. PHP tools run in the official GLPI image of the plugin's GLPI
# version, so the host only needs docker and node/npm (plus curl/python3 for the e2e tests).
#
#   tools/check.sh            # unit tests, PHPStan, php-cs-fixer, Rector, licence headers,
#                             # ESLint, bundle up to date
#   tools/check.sh --e2e      # additionally the Docker end-to-end tests (tests/e2e/run.sh)
#
# GLPI_CORE_DIR: a GLPI 12 checkout with Composer dev dependencies (PHPUnit, PHPStan, php-cs-fixer,
# Rector) and its tools/ directory; defaults to ../.. (the plugin inside its plugins/ directory).
# GLPI_IMAGE: defaults to glpi/glpi:12.0.0.

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
GLPI_CORE_DIR=${GLPI_CORE_DIR:-$PLUGIN_DIR/../..}
GLPI_IMAGE=${GLPI_IMAGE:-glpi/glpi:12.0.0}
cd "$PLUGIN_DIR"

[ -x "$GLPI_CORE_DIR/vendor/bin/phpstan" ] || {
    echo "No GLPI dev tools in $GLPI_CORE_DIR (set GLPI_CORE_DIR)." >&2
    exit 1
}
GLPI_CORE_DIR=$(cd "$GLPI_CORE_DIR" && pwd)

in_glpi() { # in_glpi <shell command>, run in the plugin directory inside the GLPI image
    docker run --rm --entrypoint sh -u "$(id -u):$(id -g)" \
        -e GLPI_DIR=/plugins/glpi-core -e PHP_CS_FIXER_IGNORE_ENV=1 \
        -e GLPI_MARKETPLACE_DIR=/var/www/glpi/plugins -e GLPI_CONFIG_DIR=/tmp/config \
        -e GLPI_VAR_DIR=/tmp/files -e GLPI_LOG_DIR=/tmp/files/_log \
        -v "$GLPI_CORE_DIR:/plugins/glpi-core:ro" \
        -v "$PLUGIN_DIR:/var/www/glpi/plugins/inappnotification" \
        -w /var/www/glpi/plugins/inappnotification "$GLPI_IMAGE" -c "$1"
}
BIN=/plugins/glpi-core/vendor/bin

echo "== PHPUnit ($GLPI_IMAGE)"
in_glpi "$BIN/phpunit -c phpunit.xml.dist"

echo "== PHPStan"
in_glpi "$BIN/phpstan analyse --memory-limit=1G --no-progress"

echo "== php-cs-fixer (GLPI plugin baseline)"
in_glpi "$BIN/php-cs-fixer check --show-progress=none"

echo "== Rector (GLPI plugin baseline)"
in_glpi "$BIN/rector process --dry-run --no-progress-bar"

echo "== Licence headers"
# GLPI's own check (`make license-headers-check`), run with a throw-away GLPI config.
in_glpi "mkdir -p /tmp/config /tmp/files/_cache /tmp/files/_lock /tmp/files/_log /tmp/files/_sessions /tmp/files/_tmp \
    && php /plugins/glpi-core/bin/console tools:licence_headers_check --plugin=inappnotification"

echo "== ESLint and bundle"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run lint
BUNDLE_COPY=$(mktemp -d)
cp public/js/dist/* "$BUNDLE_COPY/"
npm run build >/dev/null
if ! diff -rq public/js/dist "$BUNDLE_COPY" >/dev/null; then
    rm -rf "$BUNDLE_COPY"
    echo "public/js/dist was out of date with src/vue (now rebuilt): commit the new bundle." >&2
    exit 1
fi
rm -rf "$BUNDLE_COPY"

if [ "${1:-}" = "--e2e" ]; then
    echo "== End-to-end"
    tests/e2e/run.sh
fi

echo "All checks passed."
