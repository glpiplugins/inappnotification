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
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\MutedAlertEntry;

Session::checkLoginUser();

if (!MutedAlertEntry::canView()) {
    throw new AccessDeniedHttpException();
}

$title = __('Muted in-app notifications', 'inappnotification');
Html::header($title, '', 'tools', AlertEntry::class);
TemplateRenderer::getInstance()->display('@inappnotification/list_toolbar.html.twig', [
    'title' => $title,
    'links' => [
        [
            'url'   => AlertEntry::getSearchURL(),
            'icon'  => 'ti ti-arrow-left',
            'label' => __('Back to notifications', 'inappnotification'),
        ],
    ],
]);
// Muted filter, columns and sorting come from MutedAlertEntry (search restriction + defaults).
Search::show(MutedAlertEntry::class);
Html::footer();
