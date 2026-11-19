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

namespace GlpiPlugin\Inappnotification\Dashboard;

use GlpiPlugin\Inappnotification\Service\AlertStore;
use Session;

class CardProvider
{
    public static function unreadTotal(array $params = []): array
    {
        $users_id = (int) Session::getLoginUserID();
        $number = AlertStore::getUnreadTotal($users_id);

        return self::makeBigNumber($number, (string) ($params['label'] ?? __('Unread notifications', 'inappnotification')));
    }

    public static function unreadByGroup(string $group = 'other', array $params = []): array
    {
        $users_id = (int) Session::getLoginUserID();
        $number = AlertStore::getUnreadCountByGroup($users_id, $group);

        $fallback = match ($group) {
            'savedsearch' => __('Unread saved searches', 'inappnotification'),
            'ticket' => __('Unread tickets', 'inappnotification'),
            'project' => __('Unread projects', 'inappnotification'),
            'reminder' => __('Unread reminders', 'inappnotification'),
            'sla' => __('Unread SLA', 'inappnotification'),
            'crontask' => __('Unread automatic actions', 'inappnotification'),
            default => __('Unread notifications', 'inappnotification'),
        };

        return self::makeBigNumber($number, (string) ($params['label'] ?? $fallback));
    }

    private static function makeBigNumber(int $number, string $label): array
    {
        global $CFG_GLPI;

        return [
            'number' => $number,
            'label'  => $label,
            'icon'   => 'ti ti-bell-ringing',
            'url'    => $CFG_GLPI['root_doc'] . '/plugins/inappnotification/front/center.php',
        ];
    }
}
