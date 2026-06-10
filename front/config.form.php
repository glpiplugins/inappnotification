<?php

use GlpiPlugin\Inappnotification\Config\Settings;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(Settings::class)) {
    require_once __DIR__ . '/../src/Config/Settings.php';
}

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Settings::saveFromRequest($_POST);
    Session::addMessageAfterRedirect(__('Configuration updated'), true, INFO);
    Html::redirect($_SERVER['PHP_SELF']);
}

$settings = Settings::get();

$yes_no_options = [
    0 => __('No'),
    1 => __('Yes'),
];

Html::header(__('InAppNotification', 'inappnotification'), '', 'config', 'plugins');

echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . htmlescape(__('InAppNotification settings', 'inappnotification')) . "</h3></div>";
echo "<div class='card-body'>";
echo "<form method='post' class='d-grid gap-3'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='row g-3'>";

echo "<div class='col-12'><h4 class='mb-1'>" . htmlescape(__('Capture sources', 'inappnotification')) . "</h4>";
echo "<div class='text-muted small'>" . htmlescape(__('Choose which source families are persisted as in-app notifications.', 'inappnotification')) . "</div></div>";

$source_fields = [
    'capture_savedsearch' => __('Saved searches', 'inappnotification'),
    'capture_ticket' => __('Tickets', 'inappnotification'),
    'capture_project' => __('Projects', 'inappnotification'),
    'capture_reminder' => __('Reminders', 'inappnotification'),
    'capture_sla' => __('SLA / SLM', 'inappnotification'),
    'capture_crontask' => __('Scheduled tasks', 'inappnotification'),
    'capture_other' => __('Other alert sources', 'inappnotification'),
];

foreach ($source_fields as $name => $label) {
    echo "<div class='col-md-6'>";
    echo "<label class='form-label' for='inappnotification_" . htmlescape($name) . "'>" . htmlescape($label) . "</label>";
    echo Dropdown::showFromArray($name, $yes_no_options, [
        'value' => (int) ($settings[$name] ?? 1),
        'display' => false,
        'id' => 'inappnotification_' . $name,
        'class' => 'form-select',
    ]);
    echo "</div>";
}

echo "<div class='col-12'><hr class='my-2'></div>";

echo "<div class='col-12'><h4 class='mb-1'>" . htmlescape(__('Ticket filter', 'inappnotification')) . "</h4>";
echo "<div class='text-muted small'>" . htmlescape(__('Optional ticket-specific rule for captured ticket alerts.', 'inappnotification')) . "</div></div>";

echo "<div class='col-md-6'>";
echo "<label class='form-label' for='inappnotification_ticket_open_only'>" . htmlescape(__('Capture only open tickets', 'inappnotification')) . "</label>";
echo Dropdown::showFromArray('ticket_open_only', $yes_no_options, [
    'value' => (int) ($settings['ticket_open_only'] ?? 0),
    'display' => false,
    'id' => 'inappnotification_ticket_open_only',
    'class' => 'form-select',
]);
echo "</div>";

echo "<div class='col-12'><hr class='my-2'></div>";

echo "<div class='col-12'><h4 class='mb-1'>" . htmlescape(__('Itemtype filters', 'inappnotification')) . "</h4>";
echo "<div class='text-muted small'>" . htmlescape(__('Optional CSV filters by GLPI class/itemtype names.', 'inappnotification')) . "</div></div>";

echo "<div class='col-md-6'>";
echo "<label class='form-label' for='inappnotification_include_itemtypes'>" . htmlescape(__('Include only itemtypes (CSV)', 'inappnotification')) . "</label>";
echo "<input type='text' class='form-control' id='inappnotification_include_itemtypes' name='include_itemtypes'"
    . " value='" . htmlescape((string) ($settings['include_itemtypes'] ?? '')) . "'"
    . " placeholder='Ticket,Project,SavedSearch_Alert'>";
echo "</div>";

echo "<div class='col-md-6'>";
echo "<label class='form-label' for='inappnotification_exclude_itemtypes'>" . htmlescape(__('Exclude itemtypes (CSV)', 'inappnotification')) . "</label>";
echo "<input type='text' class='form-control' id='inappnotification_exclude_itemtypes' name='exclude_itemtypes'"
    . " value='" . htmlescape((string) ($settings['exclude_itemtypes'] ?? '')) . "'"
    . " placeholder='Change,Problem'>";
echo "</div>";

echo "</div>";
echo "<button type='submit' class='btn btn-primary mt-2'>" . htmlescape(__('Save')) . "</button>";
echo "</form>";
echo "</div>";
echo "</div>";

Html::footer();
