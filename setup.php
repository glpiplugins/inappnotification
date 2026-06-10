<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\Config\Settings;
use GlpiPlugin\Inappnotification\Dashboard\CardRegistry;
use GlpiPlugin\Inappnotification\ProfileTab;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

define('PLUGIN_INAPPNOTIFICATION_VERSION', '1.2.3');

function plugin_version_inappnotification(): array
{
    return [
        'name'         => __('InAppNotification', 'inappnotification'),
        'version'      => PLUGIN_INAPPNOTIFICATION_VERSION,
        'author'       => 'GLPI Plugin',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://glpi-project.org',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
        ],
    ];
}

function plugin_init_inappnotification(): void
{
    global $PLUGIN_HOOKS;

    plugin_inappnotification_register_autoloader();
    Notification_NotificationTemplate::registerMode(
        'inapp',
        __('InAppNotification (In-app)', 'inappnotification'),
        'inappnotification'
    );
    plugin_inappnotification_ensure_core_mode_config();
    if (class_exists(Settings::class)) {
        Settings::ensureDefaults();
    }
    if (class_exists(Access::class)) {
        Access::ensureProfileRightDefinition();
    }

    $PLUGIN_HOOKS['csrf_compliant']['inappnotification'] = true;

    $PLUGIN_HOOKS['menu_toadd']['inappnotification'] = [
        'tools' => AlertEntry::class,
    ];
    $PLUGIN_HOOKS['config_page']['inappnotification'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['inappnotification'] = 'plugin_inappnotification_dashboard_cards';
    $PLUGIN_HOOKS[Hooks::ADD_DEFAULT_WHERE]['inappnotification'] = 'plugin_inappnotification_add_default_where';
    Plugin::registerClass(ProfileTab::class, [
        'addtabon' => [Profile::class],
    ]);

    $PLUGIN_HOOKS['add_css']['inappnotification'][] = 'public/js/dist/inappnotification-vue.css';
    // The notification bell is a Vue component built into an IIFE bundle. It is loaded as a
    // deferred JS module (footer) so it runs after GLPI's build/vue/app.js, i.e. once
    // window._vue / window.Vue are available.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['inappnotification'][] = 'public/js/dist/inappnotification-vue.js';

    // Deliver translated UI labels + config to the bell via a <meta> boot tag (server-side
    // gettext, plugin domain). Replaces the former JS dictionary and per-AJAX i18n payload.
    $boot_header_tags = plugin_inappnotification_header_tags();
    if ($boot_header_tags !== []) {
        $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['inappnotification'] = $boot_header_tags;
    }
}

/**
 * Static UI labels for the notification bell, translated server-side (plugin gettext domain).
 *
 * @return array<string, string>
 */
function plugin_inappnotification_bell_labels(): array
{
    $keys = [
        'All', 'Ticket', 'Search', 'Project', 'Reminder', 'SLA', 'Scheduled task', 'Other',
        'Notification types', 'Loading...', 'No notifications yet', 'No notifications in this category',
        'Unread', 'Recently read', 'Notification Center', 'unread', 'All caught up', 'Sound off', 'Sound on',
        'Enable notification sound', 'Mute notification sound', 'Mark all as read', 'Select item', 'Actions',
        'Mark as read', 'Mark as unread', 'Snooze 1 day', 'Suppress', 'Select all', 'Open list', 'Muted items',
        'Notification', 'more notifications', 'Open notification list',
    ];

    $labels = [];
    foreach ($keys as $key) {
        $labels[$key] = __($key, 'inappnotification');
    }

    return $labels;
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
    $base = (string) ($CFG_GLPI['root_doc'] ?? '') . '/plugins/inappnotification/front';

    $boot = [
        'config' => [
            'apiUrl'    => $base . '/notifications.php',
            'centerUrl' => $base . '/center.php',
            'mutedUrl'  => $base . '/muted.php',
            'pollMs'    => 10000,
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

function plugin_inappnotification_register_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    spl_autoload_register(static function (string $class): void {
        // Global (non-namespaced) notification mode classes live under src/Mode/.
        // GLPI's core legacy autoloader only resolves them from src/<ClassName>.php,
        // so they must be mapped explicitly here.
        $legacy_map = [
            'PluginInappnotificationNotificationInapp' => __DIR__ . '/src/Mode/NotificationInapp.php',
            'PluginInappnotificationNotificationEventInapp' => __DIR__ . '/src/Mode/NotificationEventInapp.php',
            'PluginInappnotificationNotificationInappSetting' => __DIR__ . '/src/Mode/NotificationInappSetting.php',
        ];
        if (isset($legacy_map[$class]) && is_file($legacy_map[$class])) {
            require_once $legacy_map[$class];
            return;
        }

        $prefix = 'GlpiPlugin\\Inappnotification\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    $registered = true;
}

function plugin_inappnotification_check_prerequisites(): bool
{
    return true;
}

function plugin_inappnotification_check_config(): bool
{
    return true;
}

function plugin_inappnotification_install(): bool
{
    global $DB;

    if (!class_exists(Settings::class)) {
        require_once __DIR__ . '/src/Config/Settings.php';
    }

    $migration = new Migration(PLUGIN_INAPPNOTIFICATION_VERSION);

    $migration->addPostQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_inappnotification_alerts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `savedsearches_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `savedsearch_alerts_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `alert_name` VARCHAR(255) NOT NULL DEFAULT '',
            `alert_message` TEXT DEFAULT NULL,
            `target_url` VARCHAR(255) NOT NULL DEFAULT '',
            `source_group` VARCHAR(64) NOT NULL DEFAULT 'other',
            `source_label` VARCHAR(255) NOT NULL DEFAULT '',
            `source_itemtype` VARCHAR(190) NOT NULL DEFAULT '',
            `source_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `alert_fingerprint` VARCHAR(190) NOT NULL DEFAULT '',
            `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `unread_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `is_suppressed` TINYINT(1) NOT NULL DEFAULT 0,
            `snoozed_until` DATETIME DEFAULT NULL,
            `last_triggered_at` DATETIME DEFAULT NULL,
            `date_creation` DATETIME DEFAULT NULL,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_fingerprint` (`users_id`, `alert_fingerprint`),
            KEY `idx_user_read_date` (`users_id`, `is_read`, `last_triggered_at`),
            KEY `idx_user_muted` (`users_id`, `is_suppressed`, `snoozed_until`),
            KEY `idx_savedsearch` (`savedsearches_id`),
            KEY `idx_entity` (`entities_id`),
            KEY `idx_source_group` (`source_group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migration->addPostQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_inappnotification_scheduled` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `recipient_label` VARCHAR(255) NOT NULL DEFAULT '',
            `kind` VARCHAR(64) NOT NULL DEFAULT '',
            `kind_label` VARCHAR(255) NOT NULL DEFAULT '',
            `scheduled_at` DATETIME DEFAULT NULL,
            `itemtype` VARCHAR(190) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `item_label` VARCHAR(255) NOT NULL DEFAULT '',
            `item_url` VARCHAR(255) NOT NULL DEFAULT '',
            `detail` TEXT DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entity_label` VARCHAR(255) NOT NULL DEFAULT '',
            `fingerprint` VARCHAR(190) NOT NULL DEFAULT '',
            `date_creation` DATETIME DEFAULT NULL,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_fingerprint` (`users_id`, `fingerprint`),
            KEY `idx_user_scheduled` (`users_id`, `scheduled_at`),
            KEY `idx_entities` (`entities_id`),
            KEY `idx_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migration->addPostQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_inappnotification_scheduled_global` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recipient_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `recipient_label` VARCHAR(255) NOT NULL DEFAULT '',
            `kind` VARCHAR(64) NOT NULL DEFAULT '',
            `kind_label` VARCHAR(255) NOT NULL DEFAULT '',
            `scheduled_at` DATETIME DEFAULT NULL,
            `itemtype` VARCHAR(190) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `item_label` VARCHAR(255) NOT NULL DEFAULT '',
            `item_url` VARCHAR(255) NOT NULL DEFAULT '',
            `detail` TEXT DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entity_label` VARCHAR(255) NOT NULL DEFAULT '',
            `fingerprint` VARCHAR(190) NOT NULL DEFAULT '',
            `date_creation` DATETIME DEFAULT NULL,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_recipient_fingerprint` (`recipient_users_id`, `fingerprint`),
            KEY `idx_scheduled` (`scheduled_at`),
            KEY `idx_recipient` (`recipient_users_id`),
            KEY `idx_entities` (`entities_id`),
            KEY `idx_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($DB->tableExists('glpi_plugin_inappnotification_alerts')) {
        $migration->addField('glpi_plugin_inappnotification_alerts', 'source_group', 'string', [
            'value' => 'other',
            'after' => 'target_url',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'source_label', 'string', [
            'value' => '',
            'after' => 'source_group',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'source_itemtype', 'string', [
            'value' => '',
            'after' => 'source_label',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'source_items_id', 'integer', [
            'value' => 0,
            'after' => 'source_itemtype',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'alert_fingerprint', 'string', [
            'value' => '',
            'after' => 'source_items_id',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'is_suppressed', 'bool', [
            'value' => 0,
            'after' => 'is_read',
        ]);
        $migration->addField('glpi_plugin_inappnotification_alerts', 'snoozed_until', 'datetime', [
            'after' => 'is_suppressed',
        ]);

        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `source_group` = 'savedsearch'
             WHERE `source_group` = '' AND `savedsearch_alerts_id` > 0"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `source_group` = 'other'
             WHERE `source_group` = ''"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `source_label` = `alert_name`
             WHERE `source_label` = ''"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `source_itemtype` = '" . \SavedSearch_Alert::class . "'
             WHERE `source_itemtype` = '' AND `savedsearch_alerts_id` > 0"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `source_items_id` = `savedsearch_alerts_id`
             WHERE `source_items_id` = 0 AND `savedsearch_alerts_id` > 0"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `alert_fingerprint` = CONCAT('savedsearch:', `savedsearch_alerts_id`)
             WHERE `alert_fingerprint` = '' AND `savedsearch_alerts_id` > 0"
        );
        $migration->addPostQuery(
            "UPDATE `glpi_plugin_inappnotification_alerts`
             SET `alert_fingerprint` = CONCAT('legacy:', `id`)
             WHERE `alert_fingerprint` = ''"
        );

        $migration->dropKey('glpi_plugin_inappnotification_alerts', 'uniq_user_alert');
        $migration->addKey(
            'glpi_plugin_inappnotification_alerts',
            ['users_id', 'alert_fingerprint'],
            'uniq_user_fingerprint',
            'UNIQUE'
        );
        $migration->addKey(
            'glpi_plugin_inappnotification_alerts',
            ['source_group'],
            'idx_source_group',
            'INDEX'
        );
        $migration->addKey(
            'glpi_plugin_inappnotification_alerts',
            ['users_id', 'is_suppressed', 'snoozed_until'],
            'idx_user_muted',
            'INDEX'
        );
    }

    if ($DB->tableExists('glpi_plugin_inappnotification_scheduled')) {
        $migration->addField('glpi_plugin_inappnotification_scheduled', 'recipient_label', 'string', [
            'value' => '',
            'after' => 'users_id',
        ]);
    }

    $migration->executeMigration();
    Settings::ensureDefaults();
    if (!class_exists(Access::class)) {
        require_once __DIR__ . '/src/Access.php';
    }
    Access::ensureProfileRightDefinition();
    Config::setConfigurationValues('core', [
        'notifications_inapp' => 1,
    ]);

    return true;
}

function plugin_inappnotification_uninstall(): bool
{
    global $DB;

    $DB->dropTable('glpi_plugin_inappnotification_alerts', true);
    $DB->dropTable('glpi_plugin_inappnotification_scheduled', true);
    $DB->dropTable('glpi_plugin_inappnotification_scheduled_global', true);
    if (!class_exists(Access::class)) {
        require_once __DIR__ . '/src/Access.php';
    }
    Access::deleteProfileRightDefinition();

    return true;
}

function plugin_inappnotification_dashboard_cards(?array $cards = null): array
{
    $cards = is_array($cards) ? $cards : [];

    if (!class_exists(CardRegistry::class)) {
        require_once __DIR__ . '/src/Dashboard/CardRegistry.php';
    }

    return array_merge($cards, CardRegistry::getCards());
}

function plugin_inappnotification_ensure_core_mode_config(): void
{
    $config = Config::getConfigurationValues('core', ['notifications_inapp']);
    if (!array_key_exists('notifications_inapp', $config)) {
        Config::setConfigurationValues('core', [
            'notifications_inapp' => 1,
        ]);
    }
}
