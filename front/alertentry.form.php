<?php

use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\Service\AlertStore;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(AlertStore::class)) {
    require_once __DIR__ . '/../src/Service/AlertStore.php';
}

if (!class_exists(AlertEntry::class)) {
    require_once __DIR__ . '/../src/AlertEntry.php';
}

Session::checkLoginUser();

$id = (int) ($_GET['id'] ?? 0);
$users_id = (int) Session::getLoginUserID();
$root_doc = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$center_url = $root_doc . '/plugins/inappnotification/front/center.php';

if ($id > 0 && $users_id > 0) {
    $entry = new AlertEntry();
    if (
        $entry->getFromDB($id)
        && (int) ($entry->fields['users_id'] ?? 0) === $users_id
    ) {
        $target_url = AlertStore::resolveEntryTargetUrl(
            (string) ($entry->fields['source_itemtype'] ?? ''),
            (int) ($entry->fields['source_items_id'] ?? 0),
            (string) ($entry->fields['target_url'] ?? '')
        );

        if ($target_url !== '') {
            Html::redirect($target_url);
        }
    }
}

Html::redirect($center_url);
