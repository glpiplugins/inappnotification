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

use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;
use Override;

/**
 * Upcoming notifications of all users as a search itemtype, gated by a dedicated right
 * (see Access) instead of ownership.
 */
class ScheduledGlobalEntry extends ScheduledEntry
{
    protected const RECIPIENT_FIELD = 'recipient_users_id';

    public static string $rightname = Access::RIGHT_UPCOMING_ALL;

    public static function getTypeName($nb = 0): string
    {
        return _n('Upcoming notification (all users)', 'Upcoming notifications (all users)', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-users';
    }

    public static function canView(): bool
    {
        return Access::canViewAllUpcoming();
    }

    public static function getTable($classname = null): string
    {
        return ScheduledNotificationStore::GLOBAL_TABLE;
    }

    public static function getVisibilityCriteria(bool $forceall = false): array
    {
        return Access::canViewAllUpcoming() ? [] : ['WHERE' => [static::getTable() . '.id' => 0]];
    }

    /**
     * Unlike a user's own list, the all-users list is limited to the active entities.
     */
    #[Override]
    public function isEntityAssign()
    {
        return true;
    }

    #[Override]
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/inappnotification/front/scheduled_all.php';
    }
}
