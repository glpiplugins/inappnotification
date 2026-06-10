<?php

use GlpiPlugin\Inappnotification\Service\AlertStore;

class PluginInappnotificationNotificationInapp implements NotificationInterface
{
    public static function check($value, $options = [])
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_numeric($entry) && (int) $entry > 0) {
                    return true;
                }
            }
            return false;
        }

        return is_numeric($value) && (int) $value > 0;
    }

    public static function testNotification()
    {
        $instance = new self();

        return $instance->sendNotification([
            '_itemtype'                 => Ticket::class,
            '_items_id'                 => 0,
            '_notificationtemplates_id' => 0,
            '_entities_id'              => 0,
            'subject'                   => __('InAppNotification test', 'inappnotification'),
            'content_text'              => __('This is a test in-app notification from InAppNotification.', 'inappnotification'),
            'to'                        => Session::getLoginUserID(),
            'event'                     => 'test_inappnotification_inapp',
            'mode'                      => 'inapp',
        ]);
    }

    public function sendNotification($options = [])
    {
        if (!class_exists(AlertStore::class)) {
            require_once __DIR__ . '/../Service/AlertStore.php';
        }

        return AlertStore::captureFromNotificationMode($options);
    }
}
