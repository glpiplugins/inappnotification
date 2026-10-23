#!/usr/bin/env bash
#
# All quality checks of the plugin. PHP tools run in the official GLPI image of the plugin's GLPI
# version, so the host only needs docker and node/npm (plus curl/python3 for the e2e tests).
#
#   tools/check.sh            # unit tests, PHPStan, ESLint, bundle up to date
#   tools/check.sh --e2e      # additionally the Docker end-to-end tests (tests/e2e/run.sh)
#
# GLPI_CORE_DIR: a GLPI checkout with Composer dev dependencies (PHPUnit, PHPStan); defaults to
# ../glpi-core next to the plugin. GLPI_IMAGE: defaults to the image of the supported GLPI version.

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
GLPI_CORE_DIR=${GLPI_CORE_DIR:-$PLUGIN_DIR/../glpi-core}
GLPI_MAJOR=$(sed -n "/'min' =>/{s/.*'min' => '\([0-9]*\)\..*/\1/p;q}" "$PLUGIN_DIR/setup.php")
GLPI_IMAGE=${GLPI_IMAGE:-$([ "$GLPI_MAJOR" = "12" ] && echo glpi/glpi:12.0.0 || echo glpi/glpi:11.0.8)}
cd "$PLUGIN_DIR"

[ -x "$GLPI_CORE_DIR/vendor/bin/phpstan" ] || {
    echo "No GLPI dev tools in $GLPI_CORE_DIR (set GLPI_CORE_DIR)." >&2
    exit 1
}
GLPI_CORE_DIR=$(cd "$GLPI_CORE_DIR" && pwd)

in_glpi() { # in_glpi <command...>, run in the plugin directory inside the GLPI image
    docker run --rm --entrypoint php -u "$(id -u):$(id -g)" \
        -v "$GLPI_CORE_DIR:/plugins/glpi-core:ro" \
        -v "$PLUGIN_DIR:/var/www/glpi/plugins/inappnotification" \
        -w /var/www/glpi/plugins/inappnotification "$GLPI_IMAGE" "$@"
}

echo "== PHPUnit ($GLPI_IMAGE)"
in_glpi /plugins/glpi-core/vendor/bin/phpunit -c phpunit.xml.dist

echo "== PHPStan"
in_glpi /plugins/glpi-core/vendor/bin/phpstan analyse --memory-limit=1G --no-progress

echo "== ESLint and bundle"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run lint
BUNDLE_COPY=$(mktemp)
cp public/js/dist/inappnotification-vue.js "$BUNDLE_COPY"
npm run build >/dev/null
if ! cmp -s public/js/dist/inappnotification-vue.js "$BUNDLE_COPY"; then
    rm -f "$BUNDLE_COPY"
    echo "public/js/dist was out of date with src/vue (now rebuilt): commit the new bundle." >&2
    exit 1
fi
rm -f "$BUNDLE_COPY"

if [ "${1:-}" = "--e2e" ]; then
    echo "== End-to-end"
    tests/e2e/run.sh
fi

echo "All checks passed."
