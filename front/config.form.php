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
use GlpiPlugin\Inappnotification\Config\Settings;

Session::checkRight(Config::$rightname, UPDATE);

global $CFG_GLPI;
$form_url = $CFG_GLPI['root_doc'] . '/plugins/inappnotification/front/config.form.php';

if (isset($_POST['update'])) {
    Settings::saveFromRequest($_POST);
    Session::addMessageAfterRedirect(__('Settings saved.', 'inappnotification'), true, INFO);
    Html::redirect($form_url);
}

Html::header(__('InAppNotification', 'inappnotification'), '', 'config', 'plugin');
TemplateRenderer::getInstance()->display('@inappnotification/config.html.twig', [
    'form_url'      => $form_url,
    'settings'      => Settings::get(),
    'source_fields' => [
        'capture_savedsearch' => __('Saved searches', 'inappnotification'),
        'capture_ticket'      => __('Tickets', 'inappnotification'),
        'capture_project'     => __('Projects', 'inappnotification'),
        'capture_reminder'    => __('Reminders', 'inappnotification'),
        'capture_sla'         => __('SLA / SLM', 'inappnotification'),
        'capture_crontask'    => __('Automatic actions', 'inappnotification'),
        'capture_other'       => __('Other alert sources', 'inappnotification'),
    ],
]);
Html::footer();
