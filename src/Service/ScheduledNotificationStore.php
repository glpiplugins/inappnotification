<?php

/**
 * -------------------------------------------------------------------------
 * InAppNotification plugin for GLPI
 *
 * https://github.com/glpiplugins/inappnotification
 *
 * @copyright 2026 Roy Brannath
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of InAppNotification.
 *
 * InAppNotification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * InAppNotification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with InAppNotification. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Inappnotification\Service;

use Alert;
use CommonDBChild;
use CommonDBTM;
use CommonITILObject;
use CommonITILTask;
use Config;
use Dropdown;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Inappnotification\Config\Settings;
use Html;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use PendingReason;
use PendingReason_Item;
use PlanningRecall;

/**
 * Upcoming notifications: future planning reminders, pending-reason follow-ups and resolutions,
 * and scheduled alerts.
 *
 * The all-users table is materialized by the cron task (and rebuilt on view when stale); each
 * user's list is projected from it. Both tables store language-neutral values: `kind` and
 * `detail` are translated when displayed (see getKindLabels() and formatDetail()).
 */
class ScheduledNotificationStore
{
    public const TABLE = 'glpi_plugin_inappnotification_scheduled';

    public const GLOBAL_TABLE = 'glpi_plugin_inappnotification_scheduled_global';

    /** Configuration key (plugin settings context) recording the last global materialization time. */
    public const REFRESHED_AT_KEY = 'scheduled_refreshed_at';

    public const KIND_PLANNING_RECALL = 'planning_recall';

    public const KIND_PENDING_AUTOREMINDER = 'pending_autoreminder';

    public const KIND_PENDING_AUTORESOLVE = 'pending_autoresolve';

    public const KIND_FUTURE_ALERT = 'future_alert';

    /** Maximum number of rows of the all-users table. */
    private const int GLOBAL_LIMIT = 1000;

    /** Maximum number of rows of a user's list. */
    private const int USER_LIMIT = 300;

    /** Maximum number of rows read from each source table. */
    private const int SOURCE_QUERY_LIMIT = 1000;

    /** How long the global table is considered fresh; longer than the cron frequency (1 hour). */
    private const GLOBAL_FRESH_MAX_AGE = 2 * HOUR_TIMESTAMP;

    /** @var array<string, CommonDBTM|null> */
    private static array $item_cache = [];

    /**
     * Translated labels of the kinds of upcoming notifications.
     *
     * @return array<string, string>
     */
    public static function getKindLabels(): array
    {
        return [
            self::KIND_PLANNING_RECALL      => __('Planning reminder'),
            self::KIND_PENDING_AUTOREMINDER => __('Automatic reminder'),
            self::KIND_PENDING_AUTORESOLVE  => __('Automatic resolution', 'inappnotification'),
            self::KIND_FUTURE_ALERT         => __('Scheduled alert', 'inappnotification'),
        ];
    }

    /**
     * Translated description of an upcoming notification from its language-neutral detail: the
     * reminder lead time in seconds, the pending reason name or the alert type.
     */
    public static function formatDetail(string $kind, string $detail): string
    {
        return match ($kind) {
            self::KIND_PLANNING_RECALL => (int) $detail > 0
                ? sprintf(__('Reminder %s before start', 'inappnotification'), Html::timestampToString((int) $detail, false))
                : __('Reminder at start time', 'inappnotification'),
            self::KIND_PENDING_AUTOREMINDER => $detail !== ''
                ? sprintf(__('Pending reason: %s', 'inappnotification'), $detail)
                : __('Pending reason reminder', 'inappnotification'),
            self::KIND_PENDING_AUTORESOLVE => $detail !== ''
                ? sprintf(__('Pending reason: %s', 'inappnotification'), $detail)
                : __('Pending reason resolution', 'inappnotification'),
            self::KIND_FUTURE_ALERT => match ((int) $detail) {
                Alert::THRESHOLD   => __('Threshold', 'inappnotification'),
                Alert::END         => __('End'),
                Alert::NOTICE      => __('Notice'),
                Alert::NOTCLOSED   => __('Not closed'),
                Alert::ACTION      => __('Action'),
                Alert::PERIODICITY => __('Periodicity'),
                default            => '',
            },
            default => $detail,
        };
    }

    /**
     * Rebuild the all-users table from the GLPI data. Called by the cron task.
     *
     * @return int Number of rows written.
     */
    public static function refreshGlobal(): int
    {
        global $DB;

        $rows = self::getUpcoming();
        $now = $_SESSION['glpi_currenttime'];

        $DB->delete(self::GLOBAL_TABLE, [new QueryExpression('1=1')]);

        $count = 0;
        foreach ($rows as $row) {
            $entity_label = (string) Dropdown::getDropdownName('glpi_entities', $row['entities_id']);

            // Fan out one global row per actual recipient. Items whose recipients cannot be
            // resolved get a single recipient_users_id=0 row that is only shown in the all-users
            // overview — it is never projected into a personal upcoming list.
            $recipients = $row['recipients'] !== [] ? $row['recipients'] : [0 => ''];

            foreach ($recipients as $recipient_users_id => $recipient_label) {
                $DB->insert(self::GLOBAL_TABLE, [
                    'recipient_users_id' => $recipient_users_id,
                    'recipient_label' => $recipient_label,
                    'kind' => $row['kind'],
                    'scheduled_at' => $row['scheduled_at'],
                    'itemtype' => $row['itemtype'],
                    'items_id' => $row['items_id'],
                    'item_label' => $row['item_label'],
                    'item_url' => $row['item_url'],
                    'detail' => $row['detail'],
                    'entities_id' => $row['entities_id'],
                    'entity_label' => $entity_label,
                    'fingerprint' => self::buildFingerprint($row, $recipient_users_id),
                    'date_creation' => $now,
                    'date_mod' => $now,
                ]);
                $count++;
            }
        }

        // Record the materialization time so views can cheaply check freshness.
        Config::setConfigurationValues(Settings::CONTEXT, [self::REFRESHED_AT_KEY => $now]);

        return $count;
    }

    /**
     * Project the user's upcoming notifications from the materialized global table into the
     * per-user table. Only rows where the user is an actual recipient are taken (the global
     * recipient_users_id=0 "unresolved recipients" rows stay out of personal lists). Cheap: a
     * SELECT + INSERT, no source queries or object hydration. Ensures the global table is fresh
     * first (a no-op when the cron keeps it up to date).
     */
    public static function refreshForUser(int $users_id): void
    {
        global $DB;

        if ($users_id <= 0) {
            return;
        }

        self::ensureGlobalFresh();

        $now = $_SESSION['glpi_currenttime'];
        $iterator = $DB->request([
            'FROM'  => self::GLOBAL_TABLE,
            'WHERE' => ['recipient_users_id' => $users_id],
            'ORDER' => ['scheduled_at ASC', 'id ASC'],
            'LIMIT' => self::USER_LIMIT,
        ]);

        $DB->delete(self::TABLE, ['users_id' => $users_id]);

        foreach ($iterator as $row) {
            $DB->insert(self::TABLE, [
                'users_id' => $users_id,
                'recipient_label' => $row['recipient_label'],
                'kind' => $row['kind'],
                'scheduled_at' => $row['scheduled_at'],
                'itemtype' => $row['itemtype'],
                'items_id' => $row['items_id'],
                'item_label' => $row['item_label'],
                'item_url' => $row['item_url'],
                'detail' => $row['detail'],
                'entities_id' => $row['entities_id'],
                'entity_label' => $row['entity_label'],
                // Unique per user: the global fingerprint also covers the recipient, who is this user.
                'fingerprint' => $row['fingerprint'],
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
        }
    }

    /**
     * Rebuild the global table only when it was never built or is older than GLOBAL_FRESH_MAX_AGE.
     * The cron normally keeps it fresh, so this is a no-op on most page views.
     */
    public static function ensureGlobalFresh(): void
    {
        if (!self::isGlobalFresh()) {
            self::refreshGlobal();
        }
    }

    private static function isGlobalFresh(): bool
    {
        // The timestamp (set by refreshGlobal even when 0 rows materialize) is the authoritative
        // freshness marker. Row count is intentionally not used: "nothing upcoming" is still fresh.
        $values = Config::getConfigurationValues(Settings::CONTEXT, [self::REFRESHED_AT_KEY]);
        $refreshed_ts = strtotime((string) ($values[self::REFRESHED_AT_KEY] ?? ''));
        if ($refreshed_ts === false) {
            return false;
        }

        return (strtotime($_SESSION['glpi_currenttime']) - $refreshed_ts) <= self::GLOBAL_FRESH_MAX_AGE;
    }

    /**
     * @return list<array{
     *   kind:string,
     *   scheduled_at:string,
     *   itemtype:string,
     *   items_id:int,
     *   item_label:string,
     *   item_url:string,
     *   detail:string,
     *   entities_id:int,
     *   recipients:array<int, string>
     * }>
     */
    private static function getUpcoming(): array
    {
        self::$item_cache = [];

        $rows = array_merge(
            self::collectPlanningRecalls(),
            self::collectPendingReasonSchedules(),
            self::collectFutureAlerts(),
        );

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp($a['scheduled_at'], $b['scheduled_at']),
        );

        return array_slice($rows, 0, self::GLOBAL_LIMIT);
    }

    /**
     * @param array{kind:string, itemtype:string, items_id:int, scheduled_at:string, detail:string} $row
     */
    private static function buildFingerprint(array $row, int $recipient_users_id): string
    {
        return sha1(implode('|', [
            $row['kind'],
            $row['itemtype'],
            (string) $row['items_id'],
            $row['scheduled_at'],
            $row['detail'],
            (string) $recipient_users_id,
        ]));
    }

    private static function collectPlanningRecalls(): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'users_id', 'when', 'before_time'],
            'FROM'   => PlanningRecall::getTable(),
            'WHERE'  => [
                'NOT' => ['when' => null],
                'when' => ['>', $_SESSION['glpi_currenttime']],
            ],
            'ORDER'  => ['when ASC', 'id ASC'],
            'LIMIT'  => self::SOURCE_QUERY_LIMIT,
        ]);

        foreach ($iterator as $row) {
            $item = self::resolvePlanningRecallTargetItem((string) $row['itemtype'], (int) $row['items_id']);
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            $rows[] = self::buildRow(
                self::KIND_PLANNING_RECALL,
                (string) $row['when'],
                $item,
                (string) max(0, (int) $row['before_time']),
                self::singleRecipient((int) $row['users_id']),
            );
        }

        return $rows;
    }

    private static function collectPendingReasonSchedules(): array
    {
        global $DB;

        $rows = [];
        $now = $_SESSION['glpi_currenttime'];

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => PendingReason_Item::getTable(),
            'WHERE'  => [
                'pendingreasons_id' => ['>', 0],
                'followup_frequency' => ['>', 0],
            ],
            'ORDER'  => ['id ASC'],
            'LIMIT'  => self::SOURCE_QUERY_LIMIT,
        ]);

        foreach ($iterator as $row) {
            $pending_item = PendingReason_Item::getById((int) $row['id']);
            if (!$pending_item instanceof PendingReason_Item) {
                continue;
            }

            $item = self::loadItemForView(
                (string) $pending_item->fields['itemtype'],
                (int) $pending_item->fields['items_id'],
            );
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            if ($item instanceof CommonITILObject && (int) $item->fields['status'] !== CommonITILObject::WAITING) {
                continue;
            }

            $reason_name = '';
            $pending_reason = PendingReason::getById((int) $pending_item->fields['pendingreasons_id']);
            if ($pending_reason instanceof PendingReason) {
                $reason_name = trim((string) $pending_reason->fields['name']);
            }

            $next_bump = (string) ($pending_item->getNextFollowupDate() ?: '');
            if ($next_bump !== '' && $next_bump > $now) {
                $rows[] = self::buildRow(
                    self::KIND_PENDING_AUTOREMINDER,
                    $next_bump,
                    $item,
                    $reason_name,
                    self::resolveNotificationRecipientUsers($item, 'auto_reminder'),
                );
            }

            $next_resolve = (string) ($pending_item->getAutoResolvedate() ?: '');
            if ($next_resolve !== '' && $next_resolve > $now) {
                $rows[] = self::buildRow(
                    self::KIND_PENDING_AUTORESOLVE,
                    $next_resolve,
                    $item,
                    $reason_name,
                    self::resolveNotificationRecipientUsers($item, 'pendingreason_close'),
                );
            }
        }

        return $rows;
    }

    private static function collectFutureAlerts(): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id', 'type', 'date'],
            'FROM'   => Alert::getTable(),
            'WHERE'  => [
                'NOT' => ['date' => null],
                'date' => ['>', $_SESSION['glpi_currenttime']],
            ],
            'ORDER'  => ['date ASC', 'id ASC'],
            'LIMIT'  => self::SOURCE_QUERY_LIMIT,
        ]);

        foreach ($iterator as $row) {
            $item = self::resolveAlertTargetItem((string) $row['itemtype'], (int) $row['items_id']);
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            // Recipients are not resolvable for alert events: all-users view only.
            $rows[] = self::buildRow(self::KIND_FUTURE_ALERT, (string) $row['date'], $item, (string) (int) $row['type'], []);
        }

        return $rows;
    }

    /**
     * @param array<int, string> $recipients users_id => display label
     */
    private static function buildRow(string $kind, string $scheduled_at, CommonDBTM $item, string $detail, array $recipients): array
    {
        return [
            'kind' => $kind,
            'scheduled_at' => $scheduled_at,
            'itemtype' => $item::class,
            'items_id' => (int) $item->getID(),
            'item_label' => sprintf('%s (%d)', trim((string) $item->getName()) ?: $item::getTypeName(1), $item->getID()),
            'item_url' => $item->getLinkURL() ?: $item::getFormURLWithID($item->getID()),
            'detail' => $detail,
            'entities_id' => $item->isEntityAssign() ? (int) $item->getEntityID() : 0,
            'recipients' => $recipients,
        ];
    }

    private static function resolvePlanningRecallTargetItem(string $itemtype, int $items_id): ?CommonDBTM
    {
        $item = self::loadItemForView($itemtype, $items_id);
        if (!$item instanceof CommonDBTM) {
            return null;
        }

        if ($item instanceof CommonDBChild || $item instanceof CommonITILTask) {
            $parent = $item->getItem();
            if ($parent instanceof CommonDBTM) {
                return $parent->canViewItem() ? $parent : null;
            }
        }

        return $item;
    }

    private static function resolveAlertTargetItem(string $itemtype, int $items_id): ?CommonDBTM
    {
        if ($itemtype === PlanningRecall::class) {
            $planning_recall = PlanningRecall::getById($items_id);
            if ($planning_recall instanceof PlanningRecall) {
                return self::resolvePlanningRecallTargetItem(
                    (string) $planning_recall->fields['itemtype'],
                    (int) $planning_recall->fields['items_id'],
                );
            }

            return null;
        }

        return self::loadItemForView($itemtype, $items_id);
    }

    private static function loadItemForView(string $itemtype, int $items_id): ?CommonDBTM
    {
        $itemtype = ltrim(trim($itemtype), '\\');
        if ($itemtype === '' || $items_id <= 0) {
            return null;
        }

        $cache_key = strtolower($itemtype) . ':' . $items_id;
        if (!array_key_exists($cache_key, self::$item_cache)) {
            $item = getItemForItemtype($itemtype);
            self::$item_cache[$cache_key] = $item instanceof CommonDBTM
                && $item->getFromDB($items_id)
                && $item->canViewItem()
                ? $item
                : null;
        }

        return self::$item_cache[$cache_key];
    }

    /**
     * Single-recipient map (users_id => label), or an empty map when no user is given.
     *
     * @return array<int, string>
     */
    private static function singleRecipient(int $users_id): array
    {
        return $users_id > 0 ? [$users_id => self::getRecipientLabel($users_id)] : [];
    }

    private static function getRecipientLabel(int $users_id): string
    {
        $name = trim((string) getUserName($users_id, 0, true));

        return $name !== '' ? $name : sprintf('%s #%d', __('User'), $users_id);
    }

    /**
     * Resolve in-app recipients configured for a GLPI notification event.
     *
     * @return array<int, string> users_id => display label
     */
    private static function resolveNotificationRecipientUsers(CommonDBTM $item, string $event): array
    {
        static $cache = [];

        $cache_key = strtolower($item::class) . ':' . $item->getID() . ':' . $event;
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $target = NotificationTarget::getInstance($item, $event);
        if (!$target instanceof NotificationTarget) {
            $cache[$cache_key] = [];
            return [];
        }

        $target->addAdditionnalInfosForTarget();
        $recipients = [];

        $notifications = Notification::getNotificationsByEventAndType($event, $item::class, $target->getEntity());

        foreach ($notifications as $data) {
            if ($data['mode'] !== PLUGIN_INAPPNOTIFICATION_MODE) {
                continue;
            }

            $notification = Notification::getById((int) $data['id']);
            if (!$notification instanceof Notification || !$notification->itemMatchFilter($item)) {
                continue;
            }

            $target->clearAddressesList();
            $target->setMode(PLUGIN_INAPPNOTIFICATION_MODE);
            $target->setAllowResponse((bool) $data['allow_response']);
            $target->setEvent(Notification_NotificationTemplate::getModeClass(PLUGIN_INAPPNOTIFICATION_MODE, 'event'));

            foreach (getAllDataFromTable(NotificationTarget::getTable(), ['notifications_id' => (int) $data['id']]) as $target_data) {
                $target->addForTarget($target_data, []);
            }

            foreach ($target->getTargets() as $users_infos) {
                $users_id = (int) ($users_infos['users_id'] ?? 0);
                if ($users_id > 0 && $target->validateSendTo($event, $users_infos, true)) {
                    $recipients[$users_id] = self::getRecipientLabel($users_id);
                }
            }
        }

        asort($recipients, SORT_NATURAL | SORT_FLAG_CASE);

        $cache[$cache_key] = $recipients;
        return $recipients;
    }
}
