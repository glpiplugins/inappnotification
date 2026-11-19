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

use Glpi\Application\View\TemplateRenderer;

/**
 * Settings page of the "inapp" notification mode (Setup > Notifications).
 */
class PluginInappnotificationNotificationInappSetting extends NotificationSetting
{
    #[Override]
    public static function getTypeName($nb = 0)
    {
        return __('InAppNotification in-app notifications', 'inappnotification');
    }

    #[Override]
    public function getEnableLabel()
    {
        return __('Enable InAppNotification in-app notifications', 'inappnotification');
    }

    #[Override]
    public static function getMode()
    {
        return PLUGIN_INAPPNOTIFICATION_MODE;
    }

    #[Override]
    protected function showFormConfig()
    {
        global $CFG_GLPI;

        TemplateRenderer::getInstance()->display('@inappnotification/notification_setting.html.twig', [
            'enabled'    => (bool) $CFG_GLPI['notifications_' . PLUGIN_INAPPNOTIFICATION_MODE],
            'form_url'   => static::getFormURL(),
            'config_url' => $CFG_GLPI['root_doc'] . '/plugins/inappnotification/front/config.form.php',
        ]);
    }

    #[Override]
    public static function getIcon()
    {
        return "ti ti-bell-ringing";
    }
}
