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

namespace GlpiPlugin\Inappnotification;

use ProfileRight;
use Session;

/**
 * The plugin's profile right: viewing the upcoming notifications of all users.
 */
class Access
{
    public const RIGHT_UPCOMING_ALL = 'plugin_inappnotification_upcoming_all';

    private const int RIGHTMASK_READ = 1;

    public static function canViewAllUpcoming(): bool
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return false;
        }

        return Session::haveRight(self::RIGHT_UPCOMING_ALL, self::RIGHTMASK_READ);
    }

    public static function ensureProfileRightDefinition(): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['name' => self::RIGHT_UPCOMING_ALL],
            'LIMIT'  => 1,
        ]);

        if ($iterator->count() === 0) {
            ProfileRight::addProfileRights([self::RIGHT_UPCOMING_ALL]);
        }
    }

    public static function deleteProfileRightDefinition(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_UPCOMING_ALL]);
    }
}
