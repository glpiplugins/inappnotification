# InAppNotification for GLPI

`inappnotification` adds a persistent in-app notification center to GLPI: a notification **mode**
(`inapp`) that captures events from GLPI's native notification pipeline, a reactive **bell** in the
top bar, and server-rendered pages for searchable history, upcoming and muted items.

- Requires GLPI `>= 11.0.0` and `< 12.0.0`.
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
    Top-bar bell (Vue) ──▶ front/notifications.php ───┘
```

- **Mode classes** (global, GLPI naming convention) resolved by
  `Notification_NotificationTemplate::getModeClass('inapp')`:
  - `PluginInappnotificationNotificationInapp` — the channel (`sendNotification()`),
  - `PluginInappnotificationNotificationEventInapp` — the event class,
  - `PluginInappnotificationNotificationInappSetting` — the mode settings page.
- **Tables**: `glpi_plugin_inappnotification_alerts`, `…_scheduled`, `…_scheduled_global`.
- The **bell** is a Vue component mounted onto GLPI's shared Vue runtime (see *Frontend*); it polls
  `front/notifications.php` for the list/counts and posts actions back to it.

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
- Native GLPI `Search` for the `AlertEntry` itemtype; native session/CSRF handling in `front/*.php`.
- On install/init, the core switch `notifications_inapp` is ensured.

Access: the "all users upcoming" list requires the `plugin_inappnotification_upcoming_all` right
(managed on the profile's *InAppNotification* tab). All other views are scoped to the logged-in user.

## Configuration

`Setup > Plugins > InAppNotification > Configuration` (stored under the `plugin:inappnotification`
config context; defaults seeded on install/init):

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

PHPUnit unit tests cover pure, DB-free logic (`Settings`, `AlertStore` helpers) and run on the host
against glpi-core's vendor:

```bash
../glpi-core/vendor/bin/phpunit -c phpunit.xml.dist
```

If the host PHP CLI lacks the `dom`/`xml` extensions, run the same command inside the GLPI container.

## Installation

1. Copy the plugin to `glpi/plugins/inappnotification`.
2. `Setup > Plugins` → install and enable (creates tables, seeds config, registers the right).
3. Configure GLPI notifications to use the `inapp` mode, and/or adjust the plugin settings.

## Runtime behavior

- The unread badge uses the aggregated `unread_count`.
- Re-triggering the same fingerprint increments counters and flips the entry back to unread.
- Visibility is user-specific (enforced in search via the default-where hooks).
- Snoozed/suppressed entries are excluded from active unread lists until they expire or are reactivated.

## Project layout

```
setup.php            lifecycle: hooks, mode registration, boot meta tag, install/uninstall
hook.php             search restriction hooks (per-user visibility)
src/Mode/            global mode classes (Inapp / EventInapp / InappSetting)
src/AlertEntry.php, src/ScheduledEntry.php, src/ScheduledGlobalEntry.php   CommonDBTM models
src/Service/         AlertStore (capture/read/mutate), ScheduledNotificationStore
src/Config/Settings.php          plugin settings
src/Dashboard/       dashboard card provider / registry
src/Access.php, src/ProfileTab.php   rights
front/               notifications.php (AJAX JSON), center/muted/scheduled pages, item forms, config
src/vue/             Vue bell sources (built to public/js/dist/)
locales/             .po sources + compiled .mo
tests/               PHPUnit unit tests
```

## Design principles

- Reuse native GLPI mechanics (notification pipeline, search, Vue runtime, gettext) over bespoke plumbing.
- Keep core coupling minimal — the plugin runs against an unmodified `glpi-core`.
- User-scoped visibility and safe defaults; explicit, auditable behavior.
