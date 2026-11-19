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

use Override;

/**
 * The muted (suppressed or still snoozed) subset of AlertEntry as its own search itemtype.
 *
 * Same table and per-user scope as AlertEntry. The muted filter itself is not a search criterion
 * but part of the search restriction (plugin_inappnotification_restriction_where()), so it cannot
 * be removed in the search form. Being a separate itemtype also gives the list its own columns and
 * its own search session, so filtering it does not change the main notification list.
 */
class MutedAlertEntry extends AlertEntry
{
    public static function getTypeName($nb = 0): string
    {
        return _n('Muted notification', 'Muted notifications', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-bell-off';
    }

    #[Override]
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        // Keep sorting, paging and the search form on the muted page.
        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/inappnotification/front/muted.php';
    }

    #[Override]
    public static function getDefaultSearchRequest(): array
    {
        return [
            'forcetoview' => [2, 3, 7, 4, 6, 9, 10],
            'sort'        => 6, // last_triggered_at
            'order'       => 'DESC',
        ];
    }
}
