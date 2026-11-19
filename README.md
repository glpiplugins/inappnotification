# InAppNotification for GLPI

`inappnotification` adds a persistent in-app notification center to GLPI: a notification **mode**
(`inapp`) that captures events from GLPI's native notification pipeline, a reactive **bell** in the
top bar, and pages for searchable history, upcoming and muted notifications.

- Requires GLPI `12.0.x` (`>= 12.0.0`, `<= 12.0.99`) and PHP `>= 8.3`. The 1.x versions for
  GLPI 11 are no longer maintained.
- Translations: English, German, French, Spanish, Portuguese and Brazilian Portuguese.
- Runs against an **unmodified** GLPI (no core patches required).
- Licence: [GPL-3.0-or-later](LICENSE). Author: Roy Brannath.

## Features

- Registers a notification mode `inapp` next to GLPI's email and browser modes.
- Persists notifications in dedicated tables, aggregating repeated events by fingerprint
  (`occurrence_count`, `unread_count`).
- Top-bar **bell** (Vue 3) with an unread badge and a dropdown:
  - filter tabs per source family; unread / recently-read grouping;
  - per-item actions: mark read / unread, snooze 1 day, suppress;
  - bulk selection + bulk actions (mark read, snooze, suppress);
  - "mark all as read"; sound toggle (cross-tab — only one tab chimes);
  - footer links to the full list and to muted items.
- Lists (GLPI search): notifications, upcoming notifications (own; all users, right-gated),
  muted (snoozed/suppressed) notifications.
- Dashboard cards: unread total and unread per source family.
- A test notification can be sent from the settings page of the in-app mode.

## Installation

1. Download the archive of a release and extract it to `glpi/plugins/inappnotification` (or clone
   the repository there; the built bell is part of it).
2. `Setup > Plugins` → install and enable (creates the tables, the settings, the profile right and
   the automatic action, and enables the in-app mode).
3. Add the in-app mode to the GLPI notifications that should reach the bell
   (`Setup > Notifications > Notifications`, *Templates* tab of a notification).
4. Optionally adjust the plugin settings and grant the profile right (see below).

Upgrading from 1.x (GLPI 11): after `db:update` to GLPI 12, deploy 2.x and run the plugin update
(`Setup > Plugins` or `bin/console plugin:install inappnotification` + `plugin:activate`). It converts
the plugin's `DATETIME` columns to `TIMESTAMP` and keeps all notifications.

Uninstalling removes the plugin's tables, settings, profile right and automatic action, and the
in-app mode from the GLPI notifications.

## Configuration

`Setup > Plugins > InAppNotification > Configuration` (stored in the `plugin:inappnotification`
configuration context):

- Capture sources: saved searches, tickets, projects, reminders, SLA/SLM, automatic actions, other.
- Capture only open tickets.
- Include / exclude itemtypes: comma-separated GLPI class names.

Access: the "all users upcoming" list requires the `plugin_inappnotification_upcoming_all` right
(managed on the profile's *InAppNotification* tab). All other lists are scoped to the logged-in user.

## How it works

```
GLPI notification event ──(mode 'inapp')──▶ PluginInappnotificationNotificationInapp::sendNotification()
                                                      │
                                                      ▼
                                           AlertStore::captureFromNotificationMode()
                                                      │  writes (per user, fingerprinted)
                                                      ▼
                                    glpi_plugin_inappnotification_alerts
                                                      ▲  reads / mutates (JSON)
                                                      │
    Top-bar bell (Vue) ──▶ NotificationController ────┘
```

- **Mode classes** (global, GLPI naming convention, `src/Plugin*.php`) resolved by
  `Notification_NotificationTemplate::getModeClass('inapp')`:
  - `PluginInappnotificationNotificationInapp` — the channel (`sendNotification()`),
  - `PluginInappnotificationNotificationEventInapp` — the event class,
  - `PluginInappnotificationNotificationInappSetting` — the mode settings page.
- **Tables**: `glpi_plugin_inappnotification_alerts`, `…_scheduled` (per user), `…_scheduled_global`.
- The **bell** is a Vue component mounted onto GLPI's shared Vue runtime (see *Frontend*). It talks
  to `NotificationController`: `GET /plugins/inappnotification/Notifications` for the list and the
  counts, `POST …/Notifications/<action>` for `mark_read`, `mark_all_read`, `mark_unread`, `snooze`
  and `suppress`.
- **Upcoming notifications** (planning reminders, pending-reason reminders and resolutions, alerts)
  are materialized hourly by the automatic action `RefreshUpcoming` into the all-users table, and
  projected into the per-user table when the user opens the list. Both store language-neutral
  values; the kind and the detail are translated when displayed.

Source families (`source_group`): `savedsearch`, `ticket`, `project`, `reminder`, `sla`, `crontask`,
`other`. What reaches the bell is governed by GLPI's own notification configuration (itemtype/event,
template, recipients and the `inapp` mode) and the capture settings above.

## GLPI integration points

Wired in `plugin_init_inappnotification()` (`setup.php`):

- `Notification_NotificationTemplate::registerMode('inapp', …, 'inappnotification')`.
- `Plugin::registerClass(ProfileTab, ['addtabon' => [Profile]])` — the plugin right on the profile form.
- `$PLUGIN_HOOKS`:
  - `menu_toadd` → a `Tools` entry (`AlertEntry`); `config_page` → `front/config.form.php`;
  - `dashboard_cards` → the unread cards;
  - `add_default_where` + the `addDefaultWhere` auto-hook (`hook.php`) → restrict the plugin's
    itemtypes to the current user in GLPI search;
  - `add_css` / `add_javascript_module` → the built bell assets in `public/js/dist/`;
  - `add_header_tag` → the `inappnotification:boot` meta tag (configuration and server-translated
    labels of the bell).
- GLPI search for the plugin itemtypes (their `getSearchURL()` points at the plugin's list pages);
  pages and forms are Twig templates (`templates/`) using GLPI's form macros.
- `src/Controller/NotificationController.php`: JSON endpoint of the bell, with the firewall strategy
  "authenticated" so it also works in the simplified interface. No CSRF tokens: GLPI 12 checks
  `Sec-Fetch-Site`/`Origin` on every request with a body; actions are POST-only (a GET gets 405).
- Activation and deactivation reset GLPI's caches, so the controller routes are found right away.
- `plugin_init` does no database work, as it runs on every request.

## Runtime behavior

- The unread badge uses the aggregated `unread_count`.
- Re-triggering the same fingerprint increments the counters and flips the entry back to unread.
- Snoozed/suppressed entries are excluded from active unread lists until they expire or are
  reactivated. An expired snooze re-notifies. The muted list is its own itemtype
  (`MutedAlertEntry`, same table, fixed muted filter).
- The personal lists show the user's entries whatever entity is active; the all-users upcoming list
  is limited to the active entities.

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

## Development

### Localization

Source language is English; catalogs live in `locales/` (`inappnotification.pot`, `.po` sources and
compiled `.mo`). PHP and Twig use `__('…', 'inappnotification')` / `_n(…)`. The Vue bell has no
translation dictionary: it receives **already translated** labels from the server through the
`<meta name="inappnotification:boot">` tag (`plugin_inappnotification_bell_labels()` in `setup.php`),
so every label it uses must be listed there.

GLPI's tools extract and compile the catalogs (gettext required), from a GLPI development checkout:

```bash
bin/console tools:locales:extract --plugin=inappnotification   # or: make locales-extract
bin/console tools:locales:compile --plugin=inappnotification   # or: make locales-compile
```

The extraction also scans the Vue files with `npm run vue:gettext:extract` (`vue3-gettext`,
configured in `.gettext.config.js`; run `npm ci` first) and regenerates `en_GB.po` from the
template. The other `.po` files are then updated from `inappnotification.pot` (e.g. with `msgmerge`).

### Frontend

The bell is a Vue 3 component that **reuses GLPI's own Vue runtime** (`window._vue`, exposed by
core's `build/vue/app.js`) rather than bundling its own. `vite.config.js` marks `vue` as an external
mapped to `window._vue`, so there is no second Vue in the browser. The bundle is registered as a
deferred JS module so it runs after core's `app.js`.

Sources are in `src/vue/`; the built files in `public/js/dist/` are committed and shipped (GLPI does
not build plugins at runtime).

```bash
npm ci
npm run build      # vite build -> public/js/dist/inappnotification-vue.{js,css}
npm run lint       # eslint over src/vue
```

### Checks

`tools/check.sh` runs everything that needs no running GLPI, inside the official GLPI image of the
supported version (`glpi/glpi:12.0.0`), with the development tools of a GLPI checkout
(`GLPI_CORE_DIR`, default `../..`): PHPUnit, PHPStan, GLPI's php-cs-fixer and Rector baselines
for plugins (`.php-cs-fixer.php`, `rector.php`), GLPI's licence header check (`.licence-header`),
ESLint and a check that the committed bundle matches `src/vue`. `tools/check.sh --e2e` adds the
end-to-end tests. Inside the `plugins/` directory of a GLPI development checkout, the targets of
GLPI's `PluginsMakefile.mk` are available through the plugin's `Makefile`.

- **Unit tests** (`tests/Unit`, no database): settings, `AlertStore` helpers, the language-neutral
  upcoming details and the per-user search restriction of `hook.php`, including a guard that fails
  when a plugin itemtype is missing from it.
- **End-to-end tests** (`tests/e2e/run.sh`): start their own GLPI and MariaDB containers, upgrade from
  the 1.2.3 schema, uninstall, install, check the bell endpoint (per-user isolation, cross-site
  requests, rights, simplified interface), the pages, the settings and the test notification, then
  drive the bell in Chromium (`tests/e2e/bell.cjs`): several tabs with Web Locks and with the
  `localStorage` fallback, the chime, a navigated tab, user actions and the muted list. Needs docker,
  curl, python3 and `npm ci` (Playwright). `E2E_KEEP=1` keeps the containers, `E2E_BROWSER=0` skips
  the browser part.

### Release

1. Set the version in `setup.php`, `package.json`, `CHANGELOG.md` and `inappnotification.xml`
   (catalog entry and download URL), commit and tag it (e.g. `2.2.0`).
2. Build the archive with GLPI's release tool (git, composer, npm and gettext required):

   ```bash
   bin/console tools:plugin:release --plugin=inappnotification \
       --dest=/tmp/glpi-inappnotification-2.2.0.tar.bz2
   ```

   It exports the committed files, compiles the catalogs and leaves out `tests/`, `tools/`, the
   test configuration and the development files listed in `.ignore-release`. `make plugin-release`
   does the same, with the date in the archive name.
3. Attach the archive to the GitHub release of the tag; `inappnotification.xml` links to it.

## Project layout

```
setup.php            lifecycle: hooks, mode registration, boot meta tag, install/update/uninstall
hook.php             search restriction hooks (per-user visibility)
src/Plugin*.php      global classes: notification mode (Inapp / EventInapp / InappSetting), cron task
src/AlertEntry.php, src/MutedAlertEntry.php, src/ScheduledEntry.php, src/ScheduledGlobalEntry.php
                     CommonDBTM models (search itemtypes)
src/Controller/      NotificationController (JSON endpoint of the bell)
src/Service/         AlertStore (capture/read/mutate), ScheduledNotificationStore (upcoming)
src/Config/          plugin settings
src/Dashboard/       dashboard card provider / registry
src/Access.php, src/ProfileTab.php   rights
front/               list pages, item redirect, settings forms
templates/           Twig templates of the pages and forms
src/vue/             Vue bell sources (built to public/js/dist/); composables/useTabCoordinator.js
                     coordinates polling and the chime across tabs
locales/             .pot template, .po sources and compiled .mo
tests/               PHPUnit unit tests (tests/Unit), end-to-end tests (tests/e2e)
tools/               check.sh (all checks)
```

## Licence

Copyright (C) 2026 Roy Brannath.

InAppNotification is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See [LICENSE](LICENSE).
