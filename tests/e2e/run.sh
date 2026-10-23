#!/usr/bin/env bash
#
# End-to-end tests of inappnotification in a throw-away GLPI environment (Docker): upgrade from
# the 1.2.3 schema, uninstall, fresh install, the JSON endpoint of the bell (rights, CSRF, per-user
# isolation, simplified interface), the plugin pages and, if Playwright is installed (npm ci), the
# bell in the browser (several tabs, chime, muted list; see bell.cjs).
#
# Requirements: docker, curl, python3; node + npm ci for the browser part. Nothing outside the
# created containers is touched.
#
#   tests/e2e/run.sh                 # run everything, remove the environment afterwards
#   E2E_KEEP=1 tests/e2e/run.sh      # keep the containers for inspection (http://127.0.0.1:$E2E_PORT)
#   E2E_BROWSER=0 tests/e2e/run.sh   # skip the browser part
#
# Environment: GLPI_IMAGE (default: by branch, see below), DB_IMAGE (mariadb:11.8),
# E2E_PREFIX, E2E_PORT, E2E_KEEP (0), E2E_BROWSER (1).

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/../.." && pwd)
FIXTURES="$PLUGIN_DIR/tests/e2e/fixtures"
VERSION=$(sed -n "s/^define('PLUGIN_INAPPNOTIFICATION_VERSION', '\([^']*\)');/\1/p" "$PLUGIN_DIR/setup.php")
GLPI_MAJOR=$(sed -n "/'min' =>/{s/.*'min' => '\([0-9]*\)\..*/\1/p;q}" "$PLUGIN_DIR/setup.php")
GLPI_IMAGE=${GLPI_IMAGE:-$([ "$GLPI_MAJOR" = "12" ] && echo glpi/glpi:12.0.0 || echo glpi/glpi:11.0.8)}
DB_IMAGE=${DB_IMAGE:-mariadb:11.8}
PREFIX=${E2E_PREFIX:-inappnotification-e2e-$GLPI_MAJOR}
PORT=${E2E_PORT:-$((18000 + GLPI_MAJOR))} # 18011 for GLPI 11, 18012 for GLPI 12
KEEP=${E2E_KEEP:-0}
BROWSER=${E2E_BROWSER:-1}
BASE="http://127.0.0.1:$PORT"
API="$BASE/plugins/inappnotification/Notifications"
STATE=$(mktemp -d)
JAR="$STATE/cookies.txt"
FAILURES=0

cleanup() {
    if [ "$KEEP" = "1" ]; then
        echo "Environment kept: $BASE (glpi/glpi)"
        return
    fi
    docker rm -f -v "$PREFIX-app" "$PREFIX-db" >/dev/null 2>&1 || true
    docker network rm "$PREFIX" >/dev/null 2>&1 || true
    rm -rf "$STATE"
}
trap cleanup EXIT

pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILURES=$((FAILURES + 1)); }
section() { printf '\n== %s\n' "$1"; }
assert_eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$3', got '$2')"; fi; }
# Here-strings, not pipes: with pipefail an early exit of grep -q would fail the pipeline.
assert_contains() { if grep -qF -- "$3" <<<"$2"; then pass "$1"; else fail "$1 (no '$3' in: ${2:0:300})"; fi; }
assert_not_contains() { if grep -qF -- "$3" <<<"$2"; then fail "$1 ('$3' found)"; else pass "$1"; fi; }

sql() { docker exec -i "$PREFIX-db" mariadb -N -uglpi -pglpi glpi -e "$1"; }
console() { docker exec -u www-data "$PREFIX-app" php bin/console "$@" --no-interaction; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }

# GLPI 11 needs the CSRF token of the login form and, for fetch()-like POSTs, the page token in
# the X-Glpi-Csrf-Token header. GLPI 12 has no tokens (both stay empty) and checks Sec-Fetch-Site.
CSRF=''
login() {
    rm -f "$JAR"
    local page token
    page=$(curl -s -c "$JAR" -b "$JAR" "$BASE/index.php")
    token=$(printf '%s' "$page" | grep -oE 'name="_glpi_csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)"/\1/' || true)
    curl -s -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/front/login.php" \
        --data-urlencode "login_name=$1" --data-urlencode "login_password=$2" --data-urlencode auth=local \
        ${token:+--data-urlencode "_glpi_csrf_token=$token"}
    CSRF=$(http "$BASE/front/central.php" | grep -oE 'property="glpi:csrf_token" content="[^"]+"' | head -1 \
        | sed -E 's/.*content="([^"]+)"/\1/' || true)
}
# Browser-like same-origin request with the current session.
http() { curl -s -c "$JAR" -b "$JAR" -H 'Sec-Fetch-Site: same-origin' "$@"; }
# POST like the bell's fetch() does.
post() { http -X POST -H 'X-Requested-With: XMLHttpRequest' ${CSRF:+-H "X-Glpi-Csrf-Token: $CSRF"} "$@"; }
code() { http -o /dev/null -w '%{http_code}' "$@"; }

log_lines() { docker exec "$PREFIX-app" sh -c 'cat /var/glpi/logs/php-errors.log 2>/dev/null | wc -l'; }
# New php-errors.log entries mentioning the plugin.
plugin_log_entries() {
    docker exec "$PREFIX-app" sh -c "tail -n +$(($1 + 1)) /var/glpi/logs/php-errors.log 2>/dev/null" \
        | awk '/^\[/{if (b) print b; b=$0; next} {b=b"\n"$0} END{if (b) print b}' \
        | grep -i 'inappnotification' || true
}
column_types() {
    sql "SELECT GROUP_CONCAT(DISTINCT data_type) FROM information_schema.columns WHERE table_schema='glpi'
         AND table_name LIKE 'glpi_plugin_inappnotification%' AND column_name IN ('snoozed_until','last_triggered_at','scheduled_at','date_creation','date_mod')"
}
user_id() { sql "SELECT id FROM glpi_users WHERE name='$1'"; }
add_alert() { # add_alert <users_id> <name> [is_suppressed] -> id
    sql "INSERT INTO glpi_plugin_inappnotification_alerts (users_id, alert_name, alert_message, source_group, alert_fingerprint,
         is_read, unread_count, is_suppressed, last_triggered_at, date_creation, date_mod)
         VALUES ($1, '$2', 'e2e', 'other', 'e2e:$2', 0, 1, ${3:-0}, NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();"
}

section "Environment ($GLPI_IMAGE, plugin $VERSION)"
docker network create "$PREFIX" >/dev/null
docker run -d --name "$PREFIX-db" --network "$PREFIX" -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
    -e MARIADB_DATABASE=glpi -e MARIADB_USER=glpi -e MARIADB_PASSWORD=glpi "$DB_IMAGE" >/dev/null
for _ in $(seq 1 30); do sql 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
docker run -d --name "$PREFIX-app" --network "$PREFIX" -p "127.0.0.1:$PORT:80" \
    -e GLPI_DB_HOST="$PREFIX-db" -e GLPI_DB_PORT=3306 -e GLPI_DB_NAME=glpi -e GLPI_DB_USER=glpi -e GLPI_DB_PASSWORD=glpi \
    -v "$PLUGIN_DIR:/var/www/glpi/plugins/inappnotification:ro" "$GLPI_IMAGE" >/dev/null
for _ in $(seq 1 60); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = "200" ] && break; sleep 5; done
pass "GLPI is up at $BASE"
# Build the route cache while the plugin is inactive: activation must still expose the routes.
curl -s -o /dev/null "$API"

section "Upgrade from 1.2.3"
LOG0=$(log_lines)
docker exec -i "$PREFIX-db" mariadb -uglpi -pglpi glpi < "$FIXTURES/schema-1.2.3.sql"
console plugin:install inappnotification --username=glpi >/dev/null
console plugin:activate inappnotification >/dev/null
assert_contains "plugin enabled" "$(console plugin:list | grep inappnotification)" "Enabled"
assert_eq "entries kept" "$(sql "SELECT GROUP_CONCAT(alert_name ORDER BY id) FROM glpi_plugin_inappnotification_alerts")" "Upgrade test 1,Upgrade test 2"
assert_eq "snooze date kept" "$(sql "SELECT snoozed_until FROM glpi_plugin_inappnotification_alerts WHERE alert_fingerprint='upgradetest:2'")" "2026-10-30 12:00:00"
assert_eq "date columns are TIMESTAMP" "$(column_types)" "timestamp"
assert_eq "automatic action" "$(sql "SELECT name FROM glpi_crontasks WHERE itemtype='PluginInappnotificationScheduledTask'")" "RefreshUpcoming"
assert_eq "profile right" "$(sql "SELECT COUNT(*) > 0 FROM glpi_profilerights WHERE name='plugin_inappnotification_upcoming_all'")" "1"
assert_eq "in-app mode enabled" "$(sql "SELECT value FROM glpi_configs WHERE context='core' AND name='notifications_inapp'")" "1"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

section "Uninstall and fresh install"
console plugin:uninstall inappnotification >/dev/null
assert_eq "tables dropped" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_inappnotification%'")" "0"
assert_eq "profile right removed" "$(sql "SELECT COUNT(*) FROM glpi_profilerights WHERE name='plugin_inappnotification_upcoming_all'")" "0"
assert_eq "automatic action removed" "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype='PluginInappnotificationScheduledTask'")" "0"
console plugin:install inappnotification --username=glpi >/dev/null
console plugin:activate inappnotification >/dev/null
assert_eq "three tables" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_inappnotification%'")" "3"
assert_eq "date columns are TIMESTAMP" "$(column_types)" "timestamp"
assert_eq "automatic action" "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype='PluginInappnotificationScheduledTask'")" "1"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

section "Bell endpoint"
GLPI_ID=$(user_id glpi)
NORMAL_ID=$(user_id normal)
OWN=$(add_alert "$GLPI_ID" "glpi alert")
FOREIGN=$(add_alert "$NORMAL_ID" "normal alert")
login glpi glpi
assert_contains "boot meta tag on pages" "$(http "$BASE/front/central.php")" 'inappnotification:boot'
LIST=$(http "$API?limit=15")
assert_eq "list (routes found after activation)" "$(printf '%s' "$LIST" | json "d['success']")" "1"
assert_contains "list shows own entry" "$LIST" '"glpi alert"'
assert_not_contains "list hides other users' entries" "$LIST" '"normal alert"'
assert_eq "GET on an action" "$(code "$API/mark_all_read")" "405"
assert_eq "unknown action" "$(post -o /dev/null -w '%{http_code}' "$API/drop_tables")" "404"
assert_eq "cross-site POST" "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -H 'Sec-Fetch-Site: cross-site' -X POST "$API/mark_read" --data "id=$OWN")" "403"
if [ -n "$CSRF" ]; then
    assert_eq "POST without CSRF token" "$(http -o /dev/null -w '%{http_code}' -X POST -H 'X-Requested-With: XMLHttpRequest' "$API/mark_read" --data "id=$OWN")" "403"
fi
assert_eq "mark_read on another user's entry" "$(post "$API/mark_read" --data "id=$FOREIGN" | json "d['success']")" "1"
assert_eq "... leaves it unread" "$(sql "SELECT is_read FROM glpi_plugin_inappnotification_alerts WHERE id=$FOREIGN")" "0"
assert_eq "mark_read on own entry" "$(post "$API/mark_read" --data "id=$OWN" | json "d['data']['unread_total']")" "0"
assert_eq "mark_unread via ids[]" "$(post "$API/mark_unread" --data "ids[]=$OWN" | json "d['data']['unread_total']")" "1"
assert_eq "anonymous request" "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Requested-With: XMLHttpRequest' "$API")" "401"
assert_eq "legacy front/notifications.php is gone" "$(code "$BASE/plugins/inappnotification/front/notifications.php")" "404"
login post-only postonly
assert_eq "simplified interface user may use the bell" "$(http "$API" | json "d['success']")" "1"

section "Pages"
login glpi glpi
for page in center.php muted.php scheduled.php config.form.php; do
    assert_eq "$page" "$(code "$BASE/plugins/inappnotification/front/$page")" "200"
done
assert_eq "scheduled_all.php without the right" "$(code "$BASE/plugins/inappnotification/front/scheduled_all.php")" "403"
assert_eq "dev.php while switched off" "$(code "$BASE/plugins/inappnotification/front/dev.php")" "403"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

section "Active sub-entity"
# The bell counts the user's own entries across entities; the lists must not hide them when a
# sub-entity is active (entries of the root entity are not visible from there in GLPI search).
refresh_csrf() {
    CSRF=$(http "$BASE/front/central.php" | grep -oE 'property="glpi:csrf_token" content="[^"]+"' | head -1 \
        | sed -E 's/.*content="([^"]+)"/\1/' || true)
}
search_count() { # search_count <page> <itemtype> -> number of rows the GLPI search of that page returns
    local page token
    page=$(http "$BASE/plugins/inappnotification/front/$1")
    token=$(grep -oE '_idor_token[^0-9a-f]{1,30}[0-9a-f]{64}' <<<"$page" | head -1 | grep -oE '[0-9a-f]{64}' || true)
    http -G "$BASE/ajax/search.php" -H 'X-Requested-With: XMLHttpRequest' --data-urlencode action=display_results \
        --data-urlencode "itemtype=GlpiPlugin\\Inappnotification\\$2" --data-urlencode start=0 --data-urlencode "_idor_token=$token" \
        | grep -oE 'Showing [0-9]+ to [0-9]+ of [0-9]+' | grep -oE '[0-9]+$' || echo 0
}
add_alert "$GLPI_ID" "glpi muted" 1 >/dev/null
http -o /dev/null -X POST "$BASE/front/entity.form.php" --data-urlencode add=1 --data-urlencode "name=E2E School" \
    --data-urlencode entities_id=0 ${CSRF:+--data-urlencode "_glpi_csrf_token=$CSRF"}
SCHOOL=$(sql "SELECT id FROM glpi_entities WHERE name='E2E School'")
assert_eq "sub-entity created" "$([ -n "$SCHOOL" ] && echo yes)" "yes"
refresh_csrf
post -o /dev/null "$BASE/Session/ChangeEntity" --data "id=$SCHOOL&is_recursive=0"
OWN_TOTAL=$(sql "SELECT COUNT(*) FROM glpi_plugin_inappnotification_alerts WHERE users_id=$GLPI_ID")
assert_eq "notification list shows all own entries" "$(search_count center.php AlertEntry)" "$OWN_TOTAL"
assert_eq "muted list shows the muted entry" "$(search_count muted.php MutedAlertEntry)" "1"
assert_eq "bell counts the same muted entry" "$(http "$API" | json "d['data']['muted_total']")" "1"

if [ "$BROWSER" = "1" ]; then
    section "Bell in the browser"
    if [ -d "$PLUGIN_DIR/node_modules/playwright" ]; then
        sql "DELETE FROM glpi_plugin_inappnotification_alerts"
        if node "$PLUGIN_DIR/tests/e2e/bell.cjs" "$BASE" "docker exec -i $PREFIX-db mariadb -N -uglpi -pglpi glpi"; then
            pass "browser scenarios"
        else
            fail "browser scenarios (see above)"
        fi
        assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""
    else
        fail "Playwright missing: run npm ci (or E2E_BROWSER=0)"
    fi
fi

section "Result"
if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES check(s) failed."
    exit 1
fi
echo "All end-to-end checks passed."
