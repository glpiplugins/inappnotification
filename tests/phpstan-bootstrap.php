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

// PHPStan bootstrap: the GLPI core classes, functions and constants come from the GLPI checkout
// whose PHPStan runs (see tools/check.sh) or from the GLPI the plugin is installed in; the
// plugin's constants from its setup file.
if (!class_exists(CommonDBTM::class)) {
    require_once (getenv('GLPI_DIR') ?: dirname(__DIR__, 3)) . '/vendor/autoload.php';
}

require_once __DIR__ . '/../setup.php';
