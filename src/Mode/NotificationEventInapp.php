<?php

class PluginInappnotificationNotificationEventInapp extends NotificationEventAbstract
{
    public static function getTargetFieldName()
    {
        return 'users_id';
    }

    public static function getTargetField(&$data)
    {
        $field = self::getTargetFieldName();

        if (!isset($data[$field])) {
            $data[$field] = null;
        }

        return $field;
    }

    public static function canCron()
    {
        return false;
    }

    public static function getAdminData()
    {
        return [];
    }

    public static function getEntityAdminsData($entity)
    {
        return [];
    }

    public static function send(array $data)
    {
        trigger_error(
            __METHOD__ . ' should not be called!',
            E_USER_WARNING
        );

        return false;
    }
}
