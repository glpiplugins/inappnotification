# Changelog

All notable changes of the plugin. Versions 2.x are for GLPI 12; the 1.x versions for GLPI 11 are no
longer maintained.

## [2.2.0] - 2026-11-19

### Added

- Spanish (`es_ES`), Portuguese (`pt_PT`) and Brazilian Portuguese (`pt_BR`) translations; all
  catalogs are complete.
- "Send a test in-app notification to you" on the settings page of the in-app mode
  (Setup > Notifications).
- Licence (GPL-3.0-or-later) file and headers, `inappnotification.xml` for the GLPI plugin catalog.
- Uninstalling removes the in-app notification links, queued in-app notifications, the
  `notifications_inapp` switch and the plugin settings, so GLPI keeps working without the mode.

### Changed

- Kinds and details of upcoming notifications, and the source column of the notification list, are
  translated when displayed (in the viewer's language), no longer stored in the language of the
  automatic action. The upcoming caches are rebuilt on update.
- "Scheduled task" is called "Automatic action", as in GLPI; the notification list is called
  "In-app notifications".
- The bell shows "Unread: n" and "Further notifications: n", which translate without plural issues.
- Pages and forms use Twig templates and GLPI's form macros; the code follows GLPI's php-cs-fixer
  and Rector baselines for plugins.
- Development: GLPI's `make locales-extract` runs completely (Vue files through `vue3-gettext`), the
  release archive is built with GLPI's `tools:plugin:release`.
- Notification lists no longer borrow the `bookmark_public` right: users can still only see and
  update their own entries, deleting or purging through the generic actions is no longer offered.

### Removed

- The developer test panel (`front/dev.php`) and its `dev_test_panel` setting.
- Unused code: the `reactivate` action of the bell endpoint (the muted list uses a massive action),
  fallbacks for database states older than the update, the plugin's own class autoloader, the
  `savedsearches_id`, `savedsearch_alerts_id` and `source_label` columns and the `kind_label`
  column of the upcoming tables.

## [2.1.3]

- First version for GLPI 12 (port of 1.x).
