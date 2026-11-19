<?php

/**
 * -------------------------------------------------------------------------
 * InAppNotification plugin for GLPI
 *
 * https://github.com/glpiplugins/inappnotification
 *
 * @copyright 2026 Roy Brannath
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of InAppNotification.
 *
 * InAppNotification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * InAppNotification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with InAppNotification. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

use Glpi\Cache\CacheManager;
use Glpi\DBAL\QueryExpression;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\Config\Settings;
use GlpiPlugin\Inappnotification\Dashboard\CardRegistry;
use GlpiPlugin\Inappnotification\ProfileTab;
use GlpiPlugin\Inappnotification\Service\AlertStore;
use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;

define('PLUGIN_INAPPNOTIFICATION_VERSION', '2.2.0');

/** Notification mode registered by the plugin (see GLPI's Notification_NotificationTemplate). */
define('PLUGIN_INAPPNOTIFICATION_MODE', 'inapp');

function plugin_version_inappnotification(): array
{
    return [
        'name'         => __('InAppNotification', 'inappnotification'),
        'version'      => PLUGIN_INAPPNOTIFICATION_VERSION,
        'author'       => 'Roy Brannath',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/glpiplugins/inappnotification',
        'requirements' => [
            'glpi' => [
                'min' => '12.0.0',
                'max' => '12.0.99',
            ],
        ],
    ];
}

function plugin_init_inappnotification(): void
{
    global $PLUGIN_HOOKS;

    Notification_NotificationTemplate::registerMode(
        PLUGIN_INAPPNOTIFICATION_MODE,
        __('InAppNotification (In-app)', 'inappnotification'),
        'inappnotification',
    );
    // No DB work here: plugin_init runs on every request, including each poll of the bell. Settings
    // defaults, the profile right, the core "notifications_inapp" switch and the cron task are set up
    // by the install/update (Settings::get() also fills in missing defaults on first use).

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['inappnotification'] = [
        'tools' => AlertEntry::class,
    ];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['inappnotification'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['inappnotification'] = 'plugin_inappnotification_dashboard_cards';
    $PLUGIN_HOOKS[Hooks::ADD_DEFAULT_WHERE]['inappnotification'] = 'plugin_inappnotification_add_default_where';
    Plugin::registerClass(ProfileTab::class, [
        'addtabon' => [Profile::class],
    ]);

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['inappnotification'][] = 'js/dist/inappnotification-vue.css';
    // The notification bell is a Vue component built into an IIFE bundle. It is loaded as a
    // deferred JS module (footer) so it runs after GLPI's build/vue/app.js, i.e. once
    // window._vue / window.Vue are available.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['inappnotification'][] = 'js/dist/inappnotification-vue.js';

    // Translated UI labels and the configuration of the bell, delivered as a <meta> tag.
    $boot_header_tags = plugin_inappnotification_header_tags();
    if ($boot_header_tags !== []) {
        $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['inappnotification'] = $boot_header_tags;
    }
}

/**
 * Static UI labels of the notification bell, translated server-side (plugin gettext domain).
 *
 * @return array<string, string>
 */
function plugin_inappnotification_bell_labels(): array
{
    return [
        'All'                     => __('All', 'inappnotification'),
        'Ticket'                  => __('Ticket', 'inappnotification'),
        'Search'                  => __('Search', 'inappnotification'),
        'Project'                 => __('Project', 'inappnotification'),
        'Reminder'                => __('Reminder', 'inappnotification'),
        'SLA'                     => __('SLA', 'inappnotification'),
        'Automatic action'          => __('Automatic action', 'inappnotification'),
        'Other'                   => __('Other', 'inappnotification'),
        'Notification types'      => __('Notification types', 'inappnotification'),
        'Loading...'              => __('Loading...', 'inappnotification'),
        'No notifications yet'    => __('No notifications yet', 'inappnotification'),
        'No notifications in this category' => __('No notifications in this category', 'inappnotification'),
        'Unread'                  => __('Unread', 'inappnotification'),
        'Recently read'           => __('Recently read', 'inappnotification'),
        'Notification Center'     => __('Notification Center', 'inappnotification'),
        // TRANS: %d is the number of unread notifications
        'Unread: %d'              => __('Unread: %d', 'inappnotification'),
        'All caught up'           => __('All caught up', 'inappnotification'),
        'Sound off'               => __('Sound off', 'inappnotification'),
        'Sound on'                => __('Sound on', 'inappnotification'),
        'Enable notification sound' => __('Enable notification sound', 'inappnotification'),
        'Mute notification sound' => __('Mute notification sound', 'inappnotification'),
        'Mark all as read'        => __('Mark all as read', 'inappnotification'),
        'Select item'             => __('Select item', 'inappnotification'),
        'Actions'                 => __('Actions', 'inappnotification'),
        'Mark as read'            => __('Mark as read', 'inappnotification'),
        'Mark as unread'          => __('Mark as unread', 'inappnotification'),
        'Snooze 1 day'            => __('Snooze 1 day', 'inappnotification'),
        'Suppress'                => __('Suppress', 'inappnotification'),
        'Select all'              => __('Select all', 'inappnotification'),
        'Open list'               => __('Open list', 'inappnotification'),
        'Muted items'             => __('Muted items', 'inappnotification'),
        'Notification'            => __('Notification', 'inappnotification'),
        // TRANS: %d is the number of further new notifications not listed in the toast
        'Further notifications: %d' => __('Further notifications: %d', 'inappnotification'),
        'Open notification list'  => __('Open notification list', 'inappnotification'),
        'Notifications'           => __('Notifications', 'inappnotification'),
        'Test sound'              => __('Test sound', 'inappnotification'),
        'Sound blocked by the browser: click anywhere on the page, or allow autoplay for this site.'
            => __('Sound blocked by the browser: click anywhere on the page, or allow autoplay for this site.', 'inappnotification'),
    ];
}

/**
 * Boot payload (config + translated labels) for the bell, emitted as a <meta> header tag.
 * Returns an empty array for anonymous requests so nothing is exposed when logged out.
 *
 * @return array<int, array{tag: string, properties: array<string, string>}>
 */
function plugin_inappnotification_header_tags(): array
{
    if (!Session::getLoginUserID()) {
        return [];
    }

    global $CFG_GLPI;
    $plugin_root = $CFG_GLPI['root_doc'] . '/plugins/inappnotification';
    $base = $plugin_root . '/front';

    $boot = [
        'config' => [
            // NotificationController: GET = list, POST <apiUrl>/<action> = action.
            'apiUrl'    => $plugin_root . '/Notifications',
            'centerUrl' => $base . '/center.php',
            'mutedUrl'  => $base . '/muted.php',
            'pollMs'    => 10000,
            // Scopes the cross-tab coordination (roles, shared baseline) to the logged-in user.
            'userId'    => (int) Session::getLoginUserID(),
        ],
        'labels' => plugin_inappnotification_bell_labels(),
    ];

    return [
        [
            'tag' => 'meta',
            'properties' => [
                'name'    => 'inappnotification:boot',
                'content' => (string) json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ],
    ];
}

/**
 * Register the cron task that materializes the "upcoming notifications" global table.
 * Idempotent: leaves an already-registered task (and any admin frequency/state changes) alone.
 */
function plugin_inappnotification_register_cron(): void
{
    $task = new CronTask();
    if ($task->getFromDBbyName(PluginInappnotificationScheduledTask::class, 'RefreshUpcoming')) {
        return;
    }

    CronTask::register(
        PluginInappnotificationScheduledTask::class,
        'RefreshUpcoming',
        HOUR_TIMESTAMP, // future-dated notifications don't need finer granularity
        [
            'state'   => CronTask::STATE_WAITING,
            'comment' => __('Rebuild the upcoming in-app notifications list', 'inappnotification'),
        ],
    );
}

function plugin_inappnotification_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_INAPPNOTIFICATION_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(AlertStore::TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . AlertStore::TABLE . "` (
                `id` INT {$sign} NOT NULL AUTO_INCREMENT,
                `users_id` INT {$sign} NOT NULL DEFAULT 0,
                `entities_id` INT {$sign} NOT NULL DEFAULT 0,
                `alert_name` VARCHAR(255) NOT NULL DEFAULT '',
                `alert_message` TEXT DEFAULT NULL,
                `target_url` VARCHAR(255) NOT NULL DEFAULT '',
                `source_group` VARCHAR(64) NOT NULL DEFAULT 'other',
                `source_itemtype` VARCHAR(190) NOT NULL DEFAULT '',
                `source_items_id` INT {$sign} NOT NULL DEFAULT 0,
                `alert_fingerprint` VARCHAR(190) NOT NULL DEFAULT '',
                `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `unread_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `is_read` TINYINT NOT NULL DEFAULT 0,
                `is_suppressed` TINYINT NOT NULL DEFAULT 0,
                `snoozed_until` TIMESTAMP NULL DEFAULT NULL,
                `last_triggered_at` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_fingerprint` (`users_id`, `alert_fingerprint`),
                KEY `idx_user_read_date` (`users_id`, `is_read`, `last_triggered_at`),
                KEY `idx_user_muted` (`users_id`, `is_suppressed`, `snoozed_until`),
                KEY `idx_entity` (`entities_id`),
                KEY `idx_source_group` (`source_group`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
        );
    } else {
        plugin_inappnotification_migrate_alerts_data($migration);
    }

    // Columns shared by the per-user and the all-users table of upcoming notifications.
    $upcoming_columns = "`recipient_label` VARCHAR(255) NOT NULL DEFAULT '',
                `kind` VARCHAR(64) NOT NULL DEFAULT '',
                `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
                `itemtype` VARCHAR(190) NOT NULL DEFAULT '',
                `items_id` INT {$sign} NOT NULL DEFAULT 0,
                `item_label` VARCHAR(255) NOT NULL DEFAULT '',
                `item_url` VARCHAR(255) NOT NULL DEFAULT '',
                `detail` TEXT DEFAULT NULL,
                `entities_id` INT {$sign} NOT NULL DEFAULT 0,
                `entity_label` VARCHAR(255) NOT NULL DEFAULT '',
                `fingerprint` VARCHAR(190) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),";

    if (!$DB->tableExists(ScheduledNotificationStore::TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . ScheduledNotificationStore::TABLE . "` (
                `id` INT {$sign} NOT NULL AUTO_INCREMENT,
                `users_id` INT {$sign} NOT NULL DEFAULT 0,
                {$upcoming_columns}
                UNIQUE KEY `uniq_user_fingerprint` (`users_id`, `fingerprint`),
                KEY `idx_user_scheduled` (`users_id`, `scheduled_at`),
                KEY `idx_entities` (`entities_id`),
                KEY `idx_kind` (`kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
        );
    }

    if (!$DB->tableExists(ScheduledNotificationStore::GLOBAL_TABLE)) {
        $DB->doQuery(
            "CREATE TABLE `" . ScheduledNotificationStore::GLOBAL_TABLE . "` (
                `id` INT {$sign} NOT NULL AUTO_INCREMENT,
                `recipient_users_id` INT {$sign} NOT NULL DEFAULT 0,
                {$upcoming_columns}
                UNIQUE KEY `uniq_recipient_fingerprint` (`recipient_users_id`, `fingerprint`),
                KEY `idx_scheduled` (`scheduled_at`),
                KEY `idx_recipient` (`recipient_users_id`),
                KEY `idx_entities` (`entities_id`),
                KEY `idx_kind` (`kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
        );
    }

    foreach ([ScheduledNotificationStore::TABLE, ScheduledNotificationStore::GLOBAL_TABLE] as $table) {
        // Early 1.x: the recipient label was added later.
        $migration->addField($table, 'recipient_label', 'string');
    }

    plugin_inappnotification_convert_datetime_columns($migration);

    // First pass: columns and data. The second pass changes keys and drops the columns the data
    // was taken from, as GLPI's migration applies column changes before the post queries.
    $migration->executeMigration();

    $table = AlertStore::TABLE;
    $migration->dropKey($table, 'uniq_user_alert');
    $migration->addKey($table, ['users_id', 'alert_fingerprint'], 'uniq_user_fingerprint', 'UNIQUE');
    $migration->addKey($table, ['source_group'], 'idx_source_group');
    $migration->addKey($table, ['users_id', 'is_suppressed', 'snoozed_until'], 'idx_user_muted');
    // 2.2.0: columns that were written but never read.
    $migration->dropKey($table, 'idx_savedsearch');
    $migration->dropField($table, 'savedsearches_id');
    $migration->dropField($table, 'savedsearch_alerts_id');
    $migration->dropField($table, 'source_label');

    foreach ([ScheduledNotificationStore::TABLE, ScheduledNotificationStore::GLOBAL_TABLE] as $table) {
        // 2.2.0: kind and detail of an upcoming notification are translated when displayed, no
        // longer stored translated. Both tables are caches, rebuilt on the next view or cron run.
        $migration->dropField($table, 'kind_label');
        $DB->delete($table, [new QueryExpression('1=1')]);
    }

    $migration->executeMigration();

    // 2.2.0: setting of the removed developer test panel. The freshness mark of the upcoming
    // cache is reset as the cache was emptied above.
    Config::deleteConfigurationValues(Settings::CONTEXT, ['dev_test_panel', ScheduledNotificationStore::REFRESHED_AT_KEY]);
    Settings::ensureDefaults();
    Access::ensureProfileRightDefinition();
    Config::setConfigurationValues('core', [
        'notifications_' . PLUGIN_INAPPNOTIFICATION_MODE => 1,
    ]);
    plugin_inappnotification_register_cron();

    return true;
}

/**
 * Upgrade of the notifications from early 1.x versions: source, fingerprint and mute columns,
 * filled from the saved-search columns of those versions.
 */
function plugin_inappnotification_migrate_alerts_data(Migration $migration): void
{
    global $DB;

    $table = AlertStore::TABLE;

    $migration->addField($table, 'source_group', 'string', ['value' => 'other', 'after' => 'target_url']);
    $migration->addField($table, 'source_itemtype', 'string', ['value' => '', 'after' => 'source_group']);
    $migration->addField($table, 'source_items_id', 'fkey', ['after' => 'source_itemtype']);
    $migration->addField($table, 'alert_fingerprint', 'string', ['value' => '', 'after' => 'source_items_id']);
    $migration->addField($table, 'is_suppressed', 'bool', ['value' => 0, 'after' => 'is_read']);
    $migration->addField($table, 'snoozed_until', 'timestamp', ['after' => 'is_suppressed']);

    if ($DB->fieldExists($table, 'savedsearch_alerts_id')) {
        $migration->addPostQuery(
            "UPDATE `{$table}`
             SET `source_group` = 'savedsearch',
                 `source_itemtype` = '" . SavedSearch_Alert::class . "',
                 `source_items_id` = `savedsearch_alerts_id`
             WHERE `source_itemtype` = '' AND `savedsearch_alerts_id` > 0",
        );
        $migration->addPostQuery(
            "UPDATE `{$table}`
             SET `alert_fingerprint` = CONCAT('savedsearch:', `savedsearch_alerts_id`)
             WHERE `alert_fingerprint` = '' AND `savedsearch_alerts_id` > 0",
        );
    }

    $migration->addPostQuery(
        "UPDATE `{$table}`
         SET `source_group` = 'other'
         WHERE `source_group` = ''",
    );
    $migration->addPostQuery(
        "UPDATE `{$table}`
         SET `alert_fingerprint` = CONCAT('legacy:', `id`)
         WHERE `alert_fingerprint` = ''",
    );
}

/**
 * Upgrade from 1.x: DATETIME columns become TIMESTAMP, as GLPI requires for time-zone support
 * (same conversion as the core "migration:timestamps" command). Idempotent: only columns that
 * are still DATETIME are changed; a fresh install creates TIMESTAMP columns directly.
 */
function plugin_inappnotification_convert_datetime_columns(Migration $migration): void
{
    global $DB;

    // Plugin-owned tables only — never a GLPI core table.
    $columns = $DB->request([
        'SELECT' => ['table_name AS TABLE_NAME', 'column_name AS COLUMN_NAME'],
        'FROM'   => 'information_schema.columns',
        'WHERE'  => [
            'table_schema' => $DB->dbdefault,
            'table_name'   => [
                AlertStore::TABLE,
                ScheduledNotificationStore::TABLE,
                ScheduledNotificationStore::GLOBAL_TABLE,
            ],
            'data_type'    => 'datetime',
        ],
    ]);

    foreach ($columns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $field = (string) $column['COLUMN_NAME'];

        // TIMESTAMP cannot hold dates before 1970; such values can only be invalid
        // zero dates here, as every column is nullable.
        $DB->update(
            $table,
            [$field => null],
            [
                ['NOT' => [$field => null]],
                [$field => ['<', '1970-01-01 00:00:01']],
            ],
        );

        $migration->changeField($table, $field, $field, 'timestamp');
    }
}

// Activation resets compiled templates but not the Symfony route cache (production mode); without
// this the controller routes answer 404 until `bin/console cache:clear`.
function plugin_inappnotification_activate(): void
{
    (new CacheManager())->resetAllCaches();
}

function plugin_inappnotification_deactivate(): void
{
    (new CacheManager())->resetAllCaches();
}

function plugin_inappnotification_uninstall(): bool
{
    global $DB;

    CronTask::unregister('Inappnotification');

    $DB->dropTable(AlertStore::TABLE, true);
    $DB->dropTable(ScheduledNotificationStore::TABLE, true);
    $DB->dropTable(ScheduledNotificationStore::GLOBAL_TABLE, true);
    Access::deleteProfileRightDefinition();

    // Without the plugin GLPI cannot resolve the in-app mode: remove its notification links,
    // queued notifications and the core switch, and the plugin settings.
    $DB->delete(Notification_NotificationTemplate::getTable(), ['mode' => PLUGIN_INAPPNOTIFICATION_MODE]);
    $DB->delete(QueuedNotification::getTable(), ['mode' => PLUGIN_INAPPNOTIFICATION_MODE]);
    Config::deleteConfigurationValues('core', ['notifications_' . PLUGIN_INAPPNOTIFICATION_MODE]);
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Config::getConfigurationValues(Settings::CONTEXT)));

    return true;
}

function plugin_inappnotification_dashboard_cards(?array $cards = null): array
{
    return array_merge($cards ?? [], CardRegistry::getCards());
}
