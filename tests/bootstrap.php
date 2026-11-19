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

declare(strict_types=1);

// Unit tests run without a database. The PHPUnit installation of a GLPI checkout (see
// tools/check.sh) already provides the GLPI core classes; otherwise they are loaded from the GLPI
// the plugin is installed in.
$pluginRoot = dirname(__DIR__);
if (!class_exists(CommonDBTM::class)) {
    require_once (getenv('GLPI_DIR') ?: dirname($pluginRoot, 2)) . '/vendor/autoload.php';
}

if (!defined('GLPI_CONFIG_DIR')) {
    define('GLPI_CONFIG_DIR', sys_get_temp_dir() . '/inappnotification-tests-config');
}

if (!is_dir(GLPI_CONFIG_DIR)) {
    mkdir(GLPI_CONFIG_DIR, 0o777, true);
}

// The plugin's classes, as GLPI loads them at runtime: PSR-4 for the namespace, src/<Class>.php
// for the global classes of the notification mode and the cron task.
spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $prefix = 'GlpiPlugin\\Inappnotification\\';
    $relative = str_starts_with($class, $prefix) ? substr($class, strlen($prefix)) : $class;
    if ($relative === $class && !str_starts_with($class, 'PluginInappnotification')) {
        return;
    }

    $path = $pluginRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
