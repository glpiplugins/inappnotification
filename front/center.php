<?php

use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\Access;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(AlertEntry::class)) {
    require_once __DIR__ . '/../src/AlertEntry.php';
}
if (!class_exists(Access::class)) {
    require_once __DIR__ . '/../src/Access.php';
}

Session::checkLoginUser();

if (!AlertEntry::canView()) {
    Html::displayRightError();
}

$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');

Html::header(__('Search notifications', 'inappnotification'), '', 'tools', 'inappnotification');

echo "<div class='mb-3 d-flex justify-content-end gap-2'>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($root_doc . "/plugins/inappnotification/front/scheduled.php") . "'>";
echo "<i class='ti ti-calendar-time me-1'></i>" . htmlescape(__('Upcoming notifications', 'inappnotification'));
echo "</a>";
if (Access::canViewAllUpcoming()) {
    echo "<a class='btn btn-outline-secondary' href='" . htmlescape($root_doc . "/plugins/inappnotification/front/scheduled_all.php") . "'>";
    echo "<i class='ti ti-users me-1'></i>" . htmlescape(__('All users upcoming', 'inappnotification'));
    echo "</a>";
}
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($root_doc . "/plugins/inappnotification/front/muted.php") . "'>";
echo "<i class='ti ti-bell-off me-1'></i>" . htmlescape(__('Manage snoozed/suppressed', 'inappnotification'));
echo "</a>";
echo "</div>";

Search::show(
    AlertEntry::class,
    [
        'sort'  => 6,
        'order' => 'DESC',
    ],
    [2, 3, 7, 4, 5, 6]
);

Html::footer();
