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

use GlpiPlugin\Inappnotification\Service\AlertStore;

/**
 * The "inapp" notification channel: stores each notification sent through it for the recipients'
 * notification bell. Resolved by GLPI through Notification_NotificationTemplate::getModeClass().
 */
class PluginInappnotificationNotificationInapp implements NotificationInterface
{
    public static function check($value, $options = [])
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_numeric($entry) && (int) $entry > 0) {
                    return true;
                }
            }

            return false;
        }

        return is_numeric($value) && (int) $value > 0;
    }

    public static function testNotification()
    {
        $instance = new self();

        return $instance->sendNotification([
            '_itemtype'                 => Ticket::class,
            '_items_id'                 => 0,
            '_notificationtemplates_id' => 0,
            '_entities_id'              => 0,
            'subject'                   => __('InAppNotification test', 'inappnotification'),
            'content_text'              => __('This is a test in-app notification from InAppNotification.', 'inappnotification'),
            'to'                        => Session::getLoginUserID(),
            'event'                     => 'test_inappnotification_inapp',
            'mode'                      => PLUGIN_INAPPNOTIFICATION_MODE,
        ]);
    }

    public function sendNotification($options = [])
    {
        return AlertStore::captureFromNotificationMode($options);
    }
}
