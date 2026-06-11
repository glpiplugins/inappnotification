# InAppNotification for GLPI

`inappnotification` adds a persistent in-app notification center to GLPI, with a bell in the top bar, a rich dropdown, and list pages for searchable history and muted/upcoming items.

## Current Status

Implemented features:

- Custom GLPI notification mode `inapp` is registered.
- Notifications are persisted in dedicated plugin tables.
- Repeated events are aggregated by fingerprint (`occurrence_count`, `unread_count`).
- Top-bar bell with unread badge and dropdown UI.
- Dropdown actions (single item):
  - Mark as read
  - Mark as unread
  - Snooze (1 day)
  - Suppress
- Dropdown bulk selection:
  - Per-item checkbox
  - Select all (visible tab/category)
  - Bulk actions via actions menu: mark as read, snooze, suppress
- Dedicated pages:
  - Notification center/search list
  - Upcoming notifications
  - Upcoming notifications (all users, permission-based)
  - Muted notifications
- Dashboard cards:
  - Unread total
  - Unread per source family
- Sound toggle in bell dropdown (client-side setting)
- Theme-compatible UI (Tabler/GLPI styles)

## Notification Sources

InAppNotification currently captures events through the native GLPI notification pipeline:

1. Notification mode `inapp`
- Entry point: `PluginInappnotificationNotificationInapp::sendNotification()`
- Persistence: `AlertStore::captureFromNotificationMode()`
- Controlled by native GLPI notification configuration:
  - by itemtype/event
  - by notification template
  - by target/recipient
  - by mode/channel (`inapp`)

Source families (`source_group`):

- `savedsearch`
- `ticket`
- `project`
- `reminder`
- `sla`
- `crontask`
- `other`

## GLPI Integration

The plugin integrates through standard GLPI extension points:

- Notification mode registration (`Notification_NotificationTemplate::registerMode`)
- Plugin hooks:
  - dashboard cards
  - extra search visibility/default where criteria
- Menu integration:
  - `Tools` entries
  - plugin config page
- Native GLPI search for plugin data (`AlertEntry`)
- Native session/security handling:
  - login checks
  - CSRF handling
  - user-specific visibility

## Configuration

Path:

- `Setup > Plugins > InAppNotification > Configuration`

Available settings:

- Enable/disable source families:
  - Saved searches
  - Tickets
  - Projects
  - Reminders
  - SLA / SLM
  - Scheduled tasks
  - Other alert sources
- Ticket filter:
  - Capture only open tickets (`ticket_open_only`)
- Itemtype CSV filters:
  - Include whitelist (`include_itemtypes`)
  - Exclude blacklist (`exclude_itemtypes`)

Additional behavior:

- The core switch `notifications_inapp` is ensured during plugin init/install.
- Dashboard cards are available in native GLPI dashboard configuration.

## Localization

Default source language is English.

The plugin now includes GLPI-style translation files (.po source + .mo runtime) in:

- `locales/en_GB.po`
- `locales/de_DE.po`
- `locales/fr_FR.po`

Notes:

- PHP strings use `__('...','inappnotification')` / `_n(...)`.
- The Vue bell receives already-translated labels from the server (PHP gettext, plugin domain)
  via a `<meta name="inappnotification:boot">` tag — there is no separate JS translation dictionary.

## Installation

Requirements:

- GLPI `>= 11.0.0` and `< 12.0.0`

Steps:

1. Copy the plugin to `glpi/plugins/inappnotification`.
2. In GLPI, open `Setup > Plugins`, then install and enable the plugin.
3. Optionally adjust settings at `Setup > Plugins > InAppNotification > Configuration`.

## Frontend (build)

The top-bar bell is a Vue 3 component that reuses GLPI's own Vue runtime (`window._vue`), loaded as
a deferred JS module. Sources live in `src/vue/`; the built bundle and stylesheet in
`public/js/dist/` are committed and shipped (GLPI does not build plugins at runtime). To change the
bell UI:

```bash
npm install
npm run build      # vite build -> public/js/dist/inappnotification-vue.{js,css}
npm run lint       # eslint over src/vue
```

PHP unit tests (pure logic, no DB) run against glpi-core's vendor:

```bash
../glpi-core/vendor/bin/phpunit -c phpunit.xml.dist
```

## Runtime Behavior

- Unread badge uses aggregated `unread_count`.
- Re-triggering the same fingerprint increments counters and sets notification back to unread.
- Visibility is user-specific: users only see their own notification entries.
- Snoozed/suppressed entries are excluded from active unread lists until reactivated/expired.

## Current Known Local Patches

This plugin relies on two intentional local (non-upstream) hotfixes in the vendored `glpi-core`
checkout:

1. Browser permission prompt timing
- File: `glpi-core/js/notifications_ajax.js`
- Purpose: call `Notification.requestPermission()` only from a user gesture (`pointerdown`/`keydown`).

2. Pending reason key guard
- File: `glpi-core/src/PendingReason_Item.php`
- Purpose: avoid `Undefined array key "pendingreasons_id"` by guarding missing key access.

If you upgrade/replace GLPI core, re-check whether these fixes are still needed.

> ⚠️ `git -C ../glpi-core status` currently shows **additional** local modifications to the vendored
> core beyond these two (e.g. `src/Html.php`, `src/Glpi/Plugin/Hooks.php`, `src/CommonGLPI.php`,
> `src/Toolbox.php`, …). These are not attributed/documented here yet and must be audited before any
> core upgrade — they may belong to other plugins in the workspace.

## Design Principles

- Keep core coupling minimal.
- Reuse native GLPI mechanics where possible.
- Keep plugin behavior explicit and auditable.
- Favor safe defaults and user-scoped visibility.
