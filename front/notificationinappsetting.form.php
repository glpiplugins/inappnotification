<?php

use Glpi\Event;

Session::checkRight('config', UPDATE);

if (!empty($_POST['update'])) {
    $config = new Config();
    $config->update($_POST);
    Event::log(0, 'system', 3, 'setup', sprintf(
        __('%1$s edited the InAppNotification in-app notifications configuration'),
        $_SESSION['glpiname'] ?? __('Unknown')
    ));
    Html::back();
}

$menus = ['config', 'notification', 'config'];
$config_id = Config::getConfigIDForContext('core');
PluginInappnotificationNotificationInappSetting::displayFullPageForItem($config_id, $menus);
