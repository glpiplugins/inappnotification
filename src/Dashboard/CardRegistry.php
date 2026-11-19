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

use GlpiPlugin\Inappnotification\AlertEntry;

class CardRegistry
{
    public const CARD_UNREAD_TOTAL = 'inappnotification_unread_total';

    public const CARD_UNREAD_SAVEDSEARCH = 'inappnotification_unread_savedsearch';

    public const CARD_UNREAD_TICKET = 'inappnotification_unread_ticket';

    public const CARD_UNREAD_PROJECT = 'inappnotification_unread_project';

    public const CARD_UNREAD_REMINDER = 'inappnotification_unread_reminder';

    public const CARD_UNREAD_SLA = 'inappnotification_unread_sla';

    public const CARD_UNREAD_CRONTASK = 'inappnotification_unread_crontask';

    public static function getCards(): array
    {
        $group = __('InAppNotification', 'inappnotification');
        $cards = [
            self::CARD_UNREAD_TOTAL => self::buildCard(
                __('Unread notifications', 'inappnotification'),
                'unreadTotal',
                [],
            ),
            self::CARD_UNREAD_SAVEDSEARCH => self::buildCard(
                __('Unread saved search notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'savedsearch'],
            ),
            self::CARD_UNREAD_TICKET => self::buildCard(
                __('Unread ticket notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'ticket'],
            ),
            self::CARD_UNREAD_PROJECT => self::buildCard(
                __('Unread project notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'project'],
            ),
            self::CARD_UNREAD_REMINDER => self::buildCard(
                __('Unread reminder notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'reminder'],
            ),
            self::CARD_UNREAD_SLA => self::buildCard(
                __('Unread SLA notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'sla'],
            ),
            self::CARD_UNREAD_CRONTASK => self::buildCard(
                __('Unread automatic action notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'crontask'],
            ),
        ];

        foreach ($cards as &$card) {
            $card['group'] = $group;
        }

        return $cards;
    }

    private static function buildCard(string $label, string $provider_method, array $args): array
    {
        return [
            'widgettype' => ['bigNumber'],
            'itemtype'   => AlertEntry::class,
            'label'      => $label,
            'provider'   => CardProvider::class . '::' . $provider_method,
            'args'       => $args,
            'filters'    => [],
        ];
    }
}
