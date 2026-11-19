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
use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\MutedAlertEntry;
use GlpiPlugin\Inappnotification\ScheduledEntry;
use GlpiPlugin\Inappnotification\ScheduledGlobalEntry;

Session::checkLoginUser();

if (!AlertEntry::canView()) {
    throw new AccessDeniedHttpException();
}

$links = [
    [
        'url'   => ScheduledEntry::getSearchURL(),
        'icon'  => ScheduledEntry::getIcon(),
        'label' => __('Upcoming notifications', 'inappnotification'),
    ],
];
if (Access::canViewAllUpcoming()) {
    $links[] = [
        'url'   => ScheduledGlobalEntry::getSearchURL(),
        'icon'  => ScheduledGlobalEntry::getIcon(),
        'label' => __('All users upcoming', 'inappnotification'),
    ];
}

$links[] = [
    'url'   => MutedAlertEntry::getSearchURL(),
    'icon'  => MutedAlertEntry::getIcon(),
    'label' => __('Manage snoozed/suppressed', 'inappnotification'),
];

$title = AlertEntry::getMenuName();
Html::header($title, '', 'tools', AlertEntry::class);
TemplateRenderer::getInstance()->display('@inappnotification/list_toolbar.html.twig', [
    'title' => $title,
    'links' => $links,
]);
Search::show(AlertEntry::class);
Html::footer();
