<?php

namespace GlpiPlugin\Inappnotification;

use CommonGLPI;
use Session;

class NotificationCenter extends CommonGLPI
{
    public static $rightname = 'bookmark_public';

    public static function getTypeName($nb = 0): string
    {
        return _n('Search notification', 'Search notifications', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-bell';
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public static function getMenuName(): string
    {
        return __('Search notifications', 'inappnotification');
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/inappnotification/front/center.php',
            'icon'  => self::getIcon(),
        ];
    }
}
