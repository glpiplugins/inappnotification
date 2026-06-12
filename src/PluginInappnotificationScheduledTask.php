<?php

use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * GLPI cron task that materializes the "upcoming notifications" global table off the user
 * request path. Without it, the upcoming pages recompute everything on every page view.
 */
class PluginInappnotificationScheduledTask extends CommonGLPI
{
    /**
     * Description shown in the GLPI CronTask UI.
     *
     * @param string $name
     * @return array{description: string}
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Refresh upcoming in-app notifications', 'inappnotification'),
        ];
    }

    /**
     * Rebuild the global upcoming-notifications table.
     *
     * @return int 1 if rows were materialized, 0 otherwise (GLPI cron return convention).
     */
    public static function cronRefreshUpcoming(CronTask $task): int
    {
        if (!class_exists(ScheduledNotificationStore::class)) {
            require_once __DIR__ . '/Service/ScheduledNotificationStore.php';
        }

        $count = ScheduledNotificationStore::refreshGlobal(ScheduledNotificationStore::MAX_LIMIT);
        $task->setVolume($count);

        return $count > 0 ? 1 : 0;
    }
}
