# InAppNotification for GLPI

`inappnotification` adds a persistent in-app notification center to GLPI: a notification **mode**
(`inapp`) that captures events from GLPI's native notification pipeline, a reactive **bell** in the
top bar, and server-rendered pages for searchable history, upcoming and muted items.

- Requires GLPI `12.0.x` (`>= 12.0.0`, `<= 12.0.99`) and PHP 8.5 (official `glpi/glpi:12.0.0` image).
  This is the `glpi12` branch; GLPI 11 is maintained on `main` (1.x).
- PHP namespace: `GlpiPlugin\Inappnotification\`.
- Runs against an **unmodified** `glpi-core` (no core patches required).

## Features

- Registers a custom notification mode `inapp` next to core's mail/ajax modes.
- Persists notifications in dedicated tables, aggregating repeated events by fingerprint
  (`occurrence_count`, `unread_count`).
- Top-bar **bell** (Vue 3) with an unread badge and a dropdown:
  - filter tabs per source family; unread / recently-read grouping;
  - per-item actions: mark read / unread, snooze 1 day, suppress;
  - bulk selection + bulk actions (mark read, snooze, suppress);
  - "mark all as read"; sound toggle (cross-tab — only one tab chimes);
  - footer links to the full list and to muted items.
- Server-rendered pages: notification search list, upcoming (own), upcoming (all users, right-gated),
  muted/snoozed.
- Dashboard cards: unread total and unread per source family.
- Per-user visibility enforced in GLPI search.

## How it works

```
GLPI notification event ──(mode 'inapp')──▶ PluginInappnotificationNotificationInapp::sendNotification()
                                                      │
                                                      ▼
                                           AlertStore::captureFromNotificationMode()
                                                      │  writes (per user, fingerprinted)
                                                      ▼
                                    glpi_plugin_inappnotification_alerts
                                                      ▲  reads / mutates (AJAX, JSON)
                                                      │
    Top-bar bell (Vue) ──▶ NotificationController ────┘
```

- **Mode classes** (global, GLPI naming convention) resolved by
  `Notification_NotificationTemplate::getModeClass('inapp')`:
  - `PluginInappnotificationNotificationInapp` — the channel (`sendNotification()`),
  - `PluginInappnotificationNotificationEventInapp` — the event class,
  - `PluginInappnotificationNotificationInappSetting` — the mode settings page.
- **Tables**: `glpi_plugin_inappnotification_alerts`, `…_scheduled`, `…_scheduled_global`.
- The **bell** is a Vue component mounted onto GLPI's shared Vue runtime (see *Frontend*). It talks
  to `NotificationController`: `GET /plugins/inappnotification/Notifications` for the list/counts,
  `POST …/Notifications/<action>` for actions. See *Several open tabs* for polling and the chime.

## Notification sources

Events are captured through the **native GLPI notification pipeline** — what reaches the bell is
governed entirely by GLPI's own notification configuration (by itemtype/event, template,
target/recipient, and the `inapp` mode/channel).

Source families (`source_group`): `savedsearch`, `ticket`, `project`, `reminder`, `sla`, `crontask`,
`other`.

## GLPI integration points

Wired in `plugin_init_inappnotification()` (`setup.php`):

- `Notification_NotificationTemplate::registerMode('inapp', …, 'inappnotification')`.
- `Plugin::registerClass(ProfileTab, ['addtabon' => [Profile]])` — exposes the plugin right on the
  profile form.
- `$PLUGIN_HOOKS`:
  - `menu_toadd` → a `Tools` entry (`AlertEntry`); `config_page` → `front/config.form.php`;
  - `dashboard_cards` → the unread cards;
  - `add_default_where` + the `addDefaultWhere` auto-hook → restrict the plugin's itemtypes to the
    current user in GLPI search;
  - `add_css` / `javascript_module` → the built bell assets in `public/js/dist/`;
  - `add_header_tag` → the `inappnotification:boot` meta tag (config + server-translated labels).
- Native GLPI `Search` for the plugin itemtypes (their `getSearchURL()` points at the plugin's own
  list pages); native session handling in `front/*.php`.
- `src/Controller/NotificationController.php`: JSON endpoint of the bell, with the firewall strategy
  "authenticated" so it also works in the simplified interface (controllers default to "central
  access"). No CSRF tokens: GLPI 12 checks `Sec-Fetch-Site`/`Origin` on every request with a body;
  actions are POST-only (a GET gets 405).
- Activation and deactivation reset GLPI's caches, so the controller routes are found right away.
- Install/update enables the core switch `notifications_inapp` and creates the settings defaults, the
  profile right and the cron task. `plugin_init` does no DB work, as it runs on every request.

Access: the "all users upcoming" list requires the `plugin_inappnotification_upcoming_all` right
(managed on the profile's *InAppNotification* tab). All other views are scoped to the logged-in user.

## Configuration

`Setup > Plugins > InAppNotification > Configuration` (stored under the `plugin:inappnotification`
config context; defaults seeded on install and on first read):

- Enable/disable source families: saved searches, tickets, projects, reminders, SLA/SLM, scheduled
  tasks, other.
- `ticket_open_only` — capture only open tickets.
- `include_itemtypes` / `exclude_itemtypes` — CSV whitelist / blacklist of itemtypes.

## Localization

Source language is English; catalogs live in `locales/` (`en_GB`, `de_DE`, `fr_FR`) as `.po` source +
compiled `.mo`.

- PHP/Twig strings: `__('…','inappnotification')` / `_n(…)`.
- The Vue bell receives **already-translated** labels from the server via the
  `<meta name="inappnotification:boot">` tag (built in `plugin_inappnotification_header_tags()`),
  so there is no JS translation dictionary.

Recompile a `.mo` after editing its `.po`:

```bash
php tools/po2mo.php locales/de_DE.po locales/de_DE.mo
```

## Frontend (build)

The bell is a Vue 3 component that **reuses GLPI's own Vue runtime** (`window._vue`, exposed by
core's `build/vue/app.js`) rather than bundling its own. `vite.config.js` marks `vue` as an external
mapped to `window._vue`, so there is no second Vue in the browser. The bundle is registered as a
deferred JS module (`javascript_module`) so it runs after core's `app.js`.

Sources are in `src/vue/`; the built artifacts in `public/js/dist/` are committed and shipped (GLPI
does not build plugins at runtime).

```bash
npm install
npm run build      # vite build -> public/js/dist/inappnotification-vue.{js,css}
npm run lint       # eslint over src/vue
```

## Tests

`tools/check.sh` runs everything that needs no running GLPI, inside the official GLPI image of the
supported version (`glpi/glpi:12.0.0`): PHPUnit, PHPStan, ESLint and a check that the committed bundle matches
`src/vue`. `tools/check.sh --e2e` adds the end-to-end tests.

- **Unit tests** (`tests/Unit`, no database): settings, `AlertStore` helpers and the per-user search
  restriction of `hook.php`, including a guard that fails when a plugin itemtype is missing from it.
- **End-to-end tests** (`tests/e2e/run.sh`): start their own GLPI and MariaDB containers, upgrade from
  the 1.2.3 schema, uninstall, install, check the bell endpoint (per-user isolation, CSRF, rights,
  simplified interface) and the pages, then drive the bell in Chromium (`tests/e2e/bell.cjs`):
  several tabs with Web Locks and with the `localStorage` fallback, the chime, a navigated tab, user
  actions and the muted list. Needs docker, curl, python3 and `npm ci` (Playwright). `E2E_KEEP=1`
  keeps the containers, `E2E_BROWSER=0` skips the browser part.

Static analysis against the GLPI 12 sources of the official image (deprecated core APIs included):

```bash
docker run --rm --entrypoint php -u "$(id -u):$(id -g)" \
  -v <glpi-core>:/plugins/glpi-core:ro -v "$PWD:/var/www/glpi/plugins/inappnotification" \
  -w /var/www/glpi/plugins/inappnotification glpi/glpi:12.0.0 \
  /plugins/glpi-core/vendor/bin/phpstan analyse --memory-limit=1G
```

## Developer test panel

For dev environments there is a panel that generates sample notifications so the bell, the muted
page and the upcoming pages can be exercised without real GLPI events: `front/dev.php`.

It is gated by the GLPI `config` right **and** either a development GLPI environment
(`GLPI_ENVIRONMENT_TYPE=development`) or the `dev_test_panel` switch under
`Setup > Plugins > InAppNotification > Configuration` (off by default). It can generate one
notification per source type, all notification states (unread/read/snoozed/suppressed/expired), and
seed an "upcoming" recipient-scope demo; a "Clear" button removes everything it created (in-app
notifications are tagged `[TEST]`, seeded upcoming rows use a `devtest:` fingerprint).

## Installation

1. Copy the plugin to `glpi/plugins/inappnotification`.
2. `Setup > Plugins` → install and enable (creates tables, seeds config, registers the right).
3. Configure GLPI notifications to use the `inapp` mode, and/or adjust the plugin settings.

Upgrading from 1.x (GLPI 11): after `db:update` to GLPI 12, deploy 2.x and run the plugin update
(`Setup > Plugins` or `bin/console plugin:install inappnotification` + `plugin:activate`). It converts
the plugin's `DATETIME` columns to `TIMESTAMP` and keeps all data.

## Runtime behavior

- The unread badge uses the aggregated `unread_count`.
- Re-triggering the same fingerprint increments counters and flips the entry back to unread.
- Visibility is user-specific (enforced in search via the default-where hooks).
- Snoozed/suppressed entries are excluded from active unread lists until they expire or are reactivated.
  The muted page lists them as its own itemtype (`MutedAlertEntry`, same table, fixed muted filter).

### Several open tabs

- One tab per browser polls the server (every 10 s) and shares each result with the other tabs over a
  `BroadcastChannel`; the other tabs do not poll. When that tab closes, another one takes over. A
  visible tab fetches itself when the shared data is older than 15 s, because the browser may throttle
  or suspend a poller in a background tab.
- Responses are ordered by the time their request started: a response overtaken by newer data (e.g. a
  poll that was in flight while another tab marked everything as read) is dropped.
- New notifications are detected once: the tab that receives data from the server compares it with a
  baseline shared via `localStorage` (per user) and updates it. Changes a tab made itself (e.g. "mark
  as unread") are not announced. A page load right after a new notification still announces it.
- Exactly one tab chimes: the first tab whose audio is unlocked. Browsers allow audio only after a user
  gesture, which usually carries over same-site navigations. If no tab has unlocked audio, the
  detecting tab tries itself. The mute toggle applies to all tabs.
- The roles are held with the Web Locks API. Over plain HTTP (no secure context) a `localStorage`
  lease is used instead; a role then moves on within a few seconds after its tab is gone.
- Browsers throttle timers in tabs that stay in the background for a while, so a chime can come later
  while every GLPI tab is hidden.
- Without `BroadcastChannel` every tab polls on its own; a short-lived `localStorage` mark keeps it to
  one chime per change, from a tab that can play audio.
- Implementation: `src/vue/composables/useTabCoordinator.js`. The bell root exposes the roles as
  `data-coordination`, `data-poller` and `data-sound`; a played chime fires `inappnotification:chime`
  on `window`.

## Project layout

```
setup.php            lifecycle: hooks, mode registration, boot meta tag, install/uninstall
hook.php             search restriction hooks (per-user visibility)
src/Mode/            global mode classes (Inapp / EventInapp / InappSetting)
src/AlertEntry.php, src/MutedAlertEntry.php, src/ScheduledEntry.php, src/ScheduledGlobalEntry.php
                     CommonDBTM models (search itemtypes)
src/Controller/      NotificationController (JSON endpoint of the bell)
src/Service/         AlertStore (capture/read/mutate), ScheduledNotificationStore
src/Config/Settings.php          plugin settings
src/Dashboard/       dashboard card provider / registry
src/Access.php, src/ProfileTab.php   rights
front/               center/muted/scheduled pages, item forms, config, dev panel
src/vue/             Vue bell sources (built to public/js/dist/); composables/useTabCoordinator.js
                     coordinates polling and the chime across tabs
locales/             .po sources + compiled .mo
tests/               PHPUnit unit tests (tests/Unit), end-to-end tests (tests/e2e)
tools/               check.sh (all checks), po2mo.php
```

## Design principles

- Reuse native GLPI mechanics (notification pipeline, search, Vue runtime, gettext) over bespoke plumbing.
- Keep core coupling minimal — the plugin runs against an unmodified `glpi-core`.
- User-scoped visibility and safe defaults; explicit, auditable behavior.
