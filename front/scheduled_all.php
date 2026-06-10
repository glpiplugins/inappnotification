<?php

use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\ScheduledGlobalEntry;
use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(Access::class)) {
    require_once __DIR__ . '/../src/Access.php';
}
if (!class_exists(ScheduledGlobalEntry::class)) {
    require_once __DIR__ . '/../src/ScheduledGlobalEntry.php';
}
if (!class_exists(ScheduledNotificationStore::class)) {
    require_once __DIR__ . '/../src/Service/ScheduledNotificationStore.php';
}

Session::checkLoginUser();

if (!Access::canViewAllUpcoming()) {
    Html::displayRightError();
}

$limit = (int) ($_GET['limit'] ?? 500);
if ($limit <= 0 || $limit > 1000) {
    $limit = 500;
}

ScheduledNotificationStore::refreshGlobal($limit);
$table_ready = ScheduledNotificationStore::tableReadyGlobal();

$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$center_url = $root_doc . '/plugins/inappnotification/front/center.php';

Html::header(__('Upcoming notifications (all users)', 'inappnotification'), '', 'tools', 'inappnotification');

echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
echo "<h2 class='mb-0'>" . htmlescape(__('Upcoming notifications (all users)', 'inappnotification')) . "</h2>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($center_url) . "'>";
echo "<i class='ti ti-arrow-left me-1'></i>" . htmlescape(__('Back to notifications', 'inappnotification'));
echo "</a>";
echo "</div>";

if (!$table_ready) {
    echo "<div class='alert alert-warning'>";
    echo htmlescape(__('Upcoming notifications global storage is not available yet. Please run the plugin update from GLPI plugins page.', 'inappnotification'));
    echo "</div>";
} else {
    Search::show(
        ScheduledGlobalEntry::class,
        [
            'reset' => 'reset',
            'sort'  => 1,
            'order' => 'ASC',
        ],
        [1, 2, 7, 3, 4, 5, 6]
    );
}

Html::footer();
