<?php

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Inappnotification\MutedAlertEntry;

if (!class_exists(MutedAlertEntry::class)) {
    require_once __DIR__ . '/../src/MutedAlertEntry.php';
}

Session::checkLoginUser();

if (!MutedAlertEntry::canView()) {
    throw new AccessDeniedHttpException();
}

global $DB;

$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$center_url = $root_doc . '/plugins/inappnotification/front/center.php';
$table = MutedAlertEntry::getTable();
$mute_fields_ready = $DB->tableExists($table)
    && $DB->fieldExists($table, 'is_suppressed')
    && $DB->fieldExists($table, 'snoozed_until');

Html::header(__('Muted in-app notifications', 'inappnotification'), '', 'tools', 'inappnotification');

echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
echo "<h2 class='mb-0'>" . htmlescape(__('Muted in-app notifications', 'inappnotification')) . "</h2>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($center_url) . "'>";
echo "<i class='ti ti-arrow-left me-1'></i>" . htmlescape(__('Back to notifications', 'inappnotification'));
echo "</a>";
echo "</div>";

if (!$mute_fields_ready) {
    echo "<div class='alert alert-warning'>";
    echo htmlescape(__('Muted notifications are not available yet. Please run the plugin update from GLPI plugins page.', 'inappnotification'));
    echo "</div>";
} else {
    // Muted filter, columns and sorting come from MutedAlertEntry (search restriction + defaults).
    Search::show(MutedAlertEntry::class);
}

Html::footer();
