<?php

use GlpiPlugin\Inappnotification\ScheduledEntry;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(ScheduledEntry::class)) {
    require_once __DIR__ . '/../src/ScheduledEntry.php';
}

Session::checkLoginUser();

$id = (int) ($_GET['id'] ?? 0);
$users_id = (int) Session::getLoginUserID();
$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$fallback_url = $root_doc . '/plugins/inappnotification/front/scheduled.php';

if ($id > 0 && $users_id > 0) {
    $entry = new ScheduledEntry();
    if (
        $entry->getFromDB($id)
        && (int) ($entry->fields['users_id'] ?? 0) === $users_id
    ) {
        $target_url = trim((string) ($entry->fields['item_url'] ?? ''));
        if ($target_url !== '') {
            Html::redirect($target_url);
        }
    }
}

Html::redirect($fallback_url);
