<?php

namespace GlpiPlugin\Inappnotification\Dashboard;

use GlpiPlugin\Inappnotification\Service\AlertStore;
use Session;

class CardProvider
{
    public static function unreadTotal(array $params = []): array
    {
        $users_id = (int) Session::getLoginUserID();
        $number = AlertStore::getUnreadTotal($users_id);

        return self::makeBigNumber($number, (string) ($params['label'] ?? __('Unread notifications', 'inappnotification')));
    }

    public static function unreadByGroup(string $group = 'other', array $params = []): array
    {
        $users_id = (int) Session::getLoginUserID();
        $number = AlertStore::getUnreadCountByGroup($users_id, (string) $group);

        $fallback = match ($group) {
            'savedsearch' => __('Unread saved searches', 'inappnotification'),
            'ticket' => __('Unread tickets', 'inappnotification'),
            'project' => __('Unread projects', 'inappnotification'),
            'reminder' => __('Unread reminders', 'inappnotification'),
            'sla' => __('Unread SLA', 'inappnotification'),
            'crontask' => __('Unread scheduled tasks', 'inappnotification'),
            default => __('Unread notifications', 'inappnotification'),
        };

        return self::makeBigNumber($number, (string) ($params['label'] ?? $fallback));
    }

    private static function makeBigNumber(int $number, string $label): array
    {
        global $CFG_GLPI;

        return [
            'number' => $number,
            'label'  => $label,
            'icon'   => 'ti ti-bell-ringing',
            'url'    => ($CFG_GLPI['root_doc'] ?? '') . '/plugins/inappnotification/front/center.php',
        ];
    }
}
