<?php

namespace GlpiPlugin\Inappnotification;

use ProfileRight;
use Session;

class Access
{
    public const RIGHT_UPCOMING_ALL = 'plugin_inappnotification_upcoming_all';
    private const RIGHTMASK_READ = 1;

    public static function canViewAllUpcoming(): bool
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return false;
        }

        return Session::haveRight(self::RIGHT_UPCOMING_ALL, self::RIGHTMASK_READ);
    }

    public static function profileCanViewAllUpcoming(int $profiles_id): bool
    {
        if ($profiles_id <= 0) {
            return false;
        }

        $rights = ProfileRight::getProfileRights($profiles_id, [self::RIGHT_UPCOMING_ALL]);
        $mask = (int) ($rights[self::RIGHT_UPCOMING_ALL] ?? 0);

        return ($mask & self::RIGHTMASK_READ) === self::RIGHTMASK_READ;
    }

    public static function setProfileCanViewAllUpcoming(int $profiles_id, bool $allow): void
    {
        if ($profiles_id <= 0) {
            return;
        }

        ProfileRight::updateProfileRights($profiles_id, [
            self::RIGHT_UPCOMING_ALL => $allow ? self::RIGHTMASK_READ : 0,
        ]);
    }

    public static function ensureProfileRightDefinition(): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['name' => self::RIGHT_UPCOMING_ALL],
            'LIMIT'  => 1,
        ]);

        if ($iterator->count() === 0) {
            ProfileRight::addProfileRights([self::RIGHT_UPCOMING_ALL]);
        }
    }

    public static function deleteProfileRightDefinition(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_UPCOMING_ALL]);
    }
}
