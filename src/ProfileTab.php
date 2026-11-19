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

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Tab on the profile form to grant the plugin's right (see Access).
 */
class ProfileTab extends CommonGLPI
{
    public static string $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('InAppNotification', 'inappnotification');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($withtemplate || !$item instanceof Profile || !Profile::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($withtemplate || !$item instanceof Profile || !Profile::canView()) {
            return false;
        }

        $can_edit = Session::haveRightsOr(Profile::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='asset'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "' data-track-changes='true'>";
            echo Html::hidden('id', ['value' => (int) $item->getID()]);
        }

        echo "<div class='card-body p-0'>";
        $item->displayRightsChoiceMatrix(
            [[
                'rights' => [
                    READ => __('Read'),
                ],
                'label' => __('View all users upcoming notifications', 'inappnotification'),
                'field' => Access::RIGHT_UPCOMING_ALL,
                'scope' => 'global',
            ]],
            [
                'canedit' => $can_edit,
                'title'   => self::getTypeName(),
            ],
        );
        echo "</div>";

        if ($can_edit) {
            echo "<div class='card-body mx-n2 border-top d-flex flex-row-reverse align-items-start flex-wrap'>";
            echo Html::submit(_x('button', 'Save'), [
                'name'  => 'update',
                'class' => 'btn btn-primary',
                'icon'  => 'ti ti-device-floppy',
            ]);
            echo "</div>";
            echo "</form>";
        }

        echo "</div>";

        return true;
    }
}
