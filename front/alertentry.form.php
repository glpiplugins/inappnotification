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

use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\Service\AlertStore;

// Opening a notification from the list: redirect to the item it is about.
Session::checkLoginUser();

$entry = new AlertEntry();
if (
    $entry->getFromDB((int) ($_GET['id'] ?? 0))
    && (int) $entry->fields['users_id'] === (int) Session::getLoginUserID()
) {
    $target_url = AlertStore::resolveEntryTargetUrl(
        (string) $entry->fields['source_itemtype'],
        (int) $entry->fields['source_items_id'],
        (string) $entry->fields['target_url'],
    );

    if ($target_url !== '') {
        Html::redirect($target_url);
    }
}

Html::redirect(AlertEntry::getSearchURL());
