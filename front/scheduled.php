<?php

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Inappnotification\ScheduledEntry;
use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;

if (!class_exists(ScheduledEntry::class)) {
    require_once __DIR__ . '/../src/ScheduledEntry.php';
}

if (!class_exists(ScheduledNotificationStore::class)) {
    require_once __DIR__ . '/../src/Service/ScheduledNotificationStore.php';
}

Session::checkLoginUser();

if (!ScheduledEntry::canView()) {
    throw new AccessDeniedHttpException();
}

$users_id = (int) Session::getLoginUserID();
$limit = (int) ($_GET['limit'] ?? 300);
if ($limit <= 0 || $limit > 1000) {
    $limit = 300;
}

ScheduledNotificationStore::refreshForUser($users_id, $limit);
$table_ready = ScheduledNotificationStore::tableReady();

$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$center_url = $root_doc . '/plugins/inappnotification/front/center.php';

Html::header(__('Upcoming notifications', 'inappnotification'), '', 'tools', 'inappnotification');

echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
echo "<h2 class='mb-0'>" . htmlescape(__('Upcoming notifications', 'inappnotification')) . "</h2>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($center_url) . "'>";
echo "<i class='ti ti-arrow-left me-1'></i>" . htmlescape(__('Back to notifications', 'inappnotification'));
echo "</a>";
echo "</div>";

if (!$table_ready) {
    echo "<div class='alert alert-warning'>";
    echo htmlescape(__('Upcoming notifications storage is not available yet. Please run the plugin update from GLPI plugins page.', 'inappnotification'));
    echo "</div>";
} else {
    Search::show(ScheduledEntry::class);
}

Html::footer();
