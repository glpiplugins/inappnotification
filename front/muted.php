<?php

use GlpiPlugin\Inappnotification\AlertEntry;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(AlertEntry::class)) {
    require_once __DIR__ . '/../src/AlertEntry.php';
}

Session::checkLoginUser();

if (!AlertEntry::canView()) {
    Html::displayRightError();
}

$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$center_url = $root_doc . '/plugins/inappnotification/front/center.php';
$table = AlertEntry::getTable();
$db = $GLOBALS['DB'] ?? null;
if (
    !is_object($db)
    && class_exists('DBConnection')
    && method_exists('DBConnection', 'getReadConnection')
) {
    $db = DBConnection::getReadConnection();
}

$mute_fields_ready = is_object($db)
    && method_exists($db, 'tableExists')
    && method_exists($db, 'fieldExists')
    && $db->tableExists($table)
    && $db->fieldExists($table, 'is_suppressed')
    && $db->fieldExists($table, 'snoozed_until');

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
    Search::show(
        AlertEntry::class,
        [
            'reset' => 'reset',
            'sort'  => 6,
            'order' => 'DESC',
            'criteria' => [
                [
                    'field'      => 9,
                    'searchtype' => 'equals',
                    'value'      => 1,
                ],
                [
                    'link'       => 'OR',
                    'field'      => 10,
                    'searchtype' => 'morethan',
                    'value'      => 'NOW',
                ],
            ],
        ],
        [2, 3, 7, 4, 6, 9, 10]
    );
}

Html::footer();
