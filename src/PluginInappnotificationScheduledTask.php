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

use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;

/**
 * GLPI cron task that materializes the "upcoming notifications" global table off the user
 * request path. Without it, the upcoming pages recompute everything on every page view.
 */
class PluginInappnotificationScheduledTask extends CommonGLPI
{
    /**
     * Description shown in the GLPI CronTask UI.
     *
     * @param string $name
     * @return array{description: string}
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Refresh upcoming in-app notifications', 'inappnotification'),
        ];
    }

    /**
     * Rebuild the global upcoming-notifications table.
     *
     * @return int 1 if rows were materialized, 0 otherwise (GLPI cron return convention).
     */
    public static function cronRefreshUpcoming(CronTask $task): int
    {
        $count = ScheduledNotificationStore::refreshGlobal();
        $task->setVolume($count);

        return $count > 0 ? 1 : 0;
    }
}
