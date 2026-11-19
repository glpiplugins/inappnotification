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

Session::checkRight(Config::$rightname, UPDATE);

if (!empty($_POST['test_inapp_send'])) {
    if (PluginInappnotificationNotificationInapp::testNotification()) {
        Session::addMessageAfterRedirect(__('Test notification sent. It appears in your notification bell.', 'inappnotification'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(
            __('The test notification was not stored. Check that tickets are captured in the plugin settings.', 'inappnotification'),
            true,
            ERROR,
        );
    }

    Html::back();
}

PluginInappnotificationNotificationInappSetting::displayFullPageForItem(
    Config::getConfigIDForContext('core'),
    ['config', 'notification', PluginInappnotificationNotificationInappSetting::class],
);
