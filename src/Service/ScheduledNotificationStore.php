<?php

namespace GlpiPlugin\Inappnotification\Service;

use Alert;
use CommonDBChild;
use CommonDBTM;
use CommonITILObject;
use CommonITILTask;
use Config;
use Dropdown;
use Glpi\DBAL\QueryExpression;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use PendingReason;
use PendingReason_Item;
use PlanningRecall;

class ScheduledNotificationStore
{
    public const TABLE = 'glpi_plugin_inappnotification_scheduled';
    public const GLOBAL_TABLE = 'glpi_plugin_inappnotification_scheduled_global';
    public const MAX_LIMIT = 1000;
    private const DEFAULT_LIMIT = 300;
    private const SOURCE_QUERY_LIMIT = 1000;

    /** Config context + key recording the last global materialization time. */
    private const CONFIG_CONTEXT = 'plugin:inappnotification';
    private const REFRESHED_AT_KEY = 'scheduled_refreshed_at';

    /** How long the global table is considered fresh; should be >= the cron frequency (1h). */
    private const GLOBAL_FRESH_MAX_AGE = 7200;

    /** @var array<string, CommonDBTM|null> */
    private static array $item_cache = [];

    /**
     * @return array<int, array{
     *   kind:string,
     *   kind_label:string,
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
    public static function getUpcoming(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        self::$item_cache = [];

        $rows = array_merge(
            self::collectPlanningRecalls(),
            self::collectPendingReasonSchedules(),
            self::collectFutureAlerts()
        );

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at'])
        );

        return array_slice($rows, 0, $limit);
    }

    public static function tableReady(): bool
    {
        global $DB;

        return $DB->tableExists(self::TABLE)
            && $DB->fieldExists(self::TABLE, 'users_id')
            && $DB->fieldExists(self::TABLE, 'scheduled_at')
            && $DB->fieldExists(self::TABLE, 'recipient_label');
    }

    public static function tableReadyGlobal(): bool
    {
        global $DB;

        return $DB->tableExists(self::GLOBAL_TABLE)
            && $DB->fieldExists(self::GLOBAL_TABLE, 'recipient_users_id')
            && $DB->fieldExists(self::GLOBAL_TABLE, 'recipient_label')
            && $DB->fieldExists(self::GLOBAL_TABLE, 'scheduled_at');
    }

    public static function refreshGlobal(int $limit = self::DEFAULT_LIMIT): int
    {
        global $DB;

        if (!self::tableReadyGlobal()) {
            return 0;
        }

        $rows = self::getUpcoming($limit);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->delete(self::GLOBAL_TABLE, [new QueryExpression('1=1')]);

        $count = 0;
        foreach ($rows as $row) {
            $scheduled_at = trim($row['scheduled_at']);
            if ($scheduled_at === '') {
                continue;
            }

            $kind = trim($row['kind']);
            $itemtype = trim($row['itemtype']);
            $items_id = $row['items_id'];
            $detail = trim($row['detail']);
            $entities_id = $row['entities_id'];
            $entity_label = self::resolveEntityLabel($entities_id);

            // Fan out one global row per actual recipient. Items whose recipients cannot be
            // resolved get a single recipient_users_id=0 row that is only shown in the all-users
            // overview — it is never projected into a personal upcoming list.
            $recipients = $row['recipients'];
            if ($recipients === []) {
                $recipients = [0 => __('Recipients from notification targets', 'inappnotification')];
            }

            foreach ($recipients as $recipient_users_id => $recipient_label) {
                $recipient_users_id = (int) $recipient_users_id;
                $recipient_label = trim((string) $recipient_label);
                if ($recipient_label === '') {
                    $recipient_label = $recipient_users_id > 0
                        ? self::resolveRecipientLabel($recipient_users_id)
                        : __('Recipients from notification targets', 'inappnotification');
                }

                $fingerprint = sha1(implode('|', [
                    $kind,
                    $itemtype,
                    (string) $items_id,
                    $scheduled_at,
                    $detail,
                    (string) $recipient_users_id,
                ]));

                $DB->insert(self::GLOBAL_TABLE, [
                    'recipient_users_id' => $recipient_users_id,
                    'recipient_label' => $recipient_label,
                    'kind' => $kind,
                    'kind_label' => $row['kind_label'],
                    'scheduled_at' => $scheduled_at,
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                    'item_label' => $row['item_label'],
                    'item_url' => $row['item_url'],
                    'detail' => $detail,
                    'entities_id' => $entities_id,
                    'entity_label' => $entity_label,
                    'fingerprint' => $fingerprint,
                    'date_creation' => $now,
                    'date_mod' => $now,
                ]);
                $count++;
            }
        }

        // Record the materialization time so views can cheaply check freshness.
        self::markGlobalFresh($now);

        return $count;
    }

    /**
     * Mark the global table as freshly materialized "now" (or at $when). Used by refreshGlobal()
     * and by the dev test panel after seeding rows, so on-view rebuilds do not wipe them.
     */
    public static function markGlobalFresh(?string $when = null): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::REFRESHED_AT_KEY => $when ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    /**
     * Project the user's upcoming notifications from the materialized global table into the
     * per-user table. Only rows where the user is an actual recipient are taken (the global
     * recipient_users_id=0 "unresolved recipients" rows stay out of personal lists). Cheap: a
     * SELECT + INSERT, no source queries or object hydration. Ensures the global table is fresh
     * first (a no-op when the cron keeps it up to date).
     */
    public static function refreshForUser(int $users_id, int $limit = self::DEFAULT_LIMIT): void
    {
        global $DB;

        if ($users_id <= 0 || !self::tableReady()) {
            return;
        }

        self::ensureGlobalFresh(max($limit, self::DEFAULT_LIMIT));

        if (!self::tableReadyGlobal()) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $fallback_recipient = trim((string) getUserName($users_id, 0, true));
        if ($fallback_recipient === '') {
            $fallback_recipient = sprintf('%s #%d', __('User'), $users_id);
        }

        $iterator = $DB->request([
            'FROM'  => self::GLOBAL_TABLE,
            'WHERE' => ['recipient_users_id' => $users_id],
            'ORDER' => ['scheduled_at ASC', 'id ASC'],
            'LIMIT' => max(1, min(self::MAX_LIMIT, $limit)),
        ]);

        $DB->delete(self::TABLE, ['users_id' => $users_id]);

        $seen = [];
        foreach ($iterator as $row) {
            $scheduled_at = trim((string) ($row['scheduled_at'] ?? ''));
            if ($scheduled_at === '') {
                continue;
            }

            $kind = trim((string) ($row['kind'] ?? ''));
            $itemtype = trim((string) ($row['itemtype'] ?? ''));
            $items_id = (int) ($row['items_id'] ?? 0);
            $detail = trim((string) ($row['detail'] ?? ''));

            // Per-user fingerprint excludes the recipient, so global rows that differ only by
            // recipient collapse to one here (avoids the (users_id, fingerprint) unique clash).
            $fingerprint = sha1(implode('|', [$kind, $itemtype, (string) $items_id, $scheduled_at, $detail]));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $recipient_label = trim((string) ($row['recipient_label'] ?? ''));
            if ($recipient_label === '') {
                $recipient_label = $fallback_recipient;
            }

            $DB->insert(self::TABLE, [
                'users_id' => $users_id,
                'recipient_label' => $recipient_label,
                'kind' => $kind,
                'kind_label' => (string) ($row['kind_label'] ?? ''),
                'scheduled_at' => $scheduled_at,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'item_label' => (string) ($row['item_label'] ?? ''),
                'item_url' => (string) ($row['item_url'] ?? ''),
                'detail' => $detail,
                'entities_id' => (int) ($row['entities_id'] ?? 0),
                'entity_label' => (string) ($row['entity_label'] ?? ''),
                'fingerprint' => $fingerprint,
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
        }
    }

    /**
     * Rebuild the global table only when it is empty or older than $maxAgeSeconds.
     * The cron normally keeps it fresh, so this is a no-op on most page views.
     */
    public static function ensureGlobalFresh(
        int $limit = self::DEFAULT_LIMIT,
        int $maxAgeSeconds = self::GLOBAL_FRESH_MAX_AGE
    ): void {
        if (self::isGlobalFresh($maxAgeSeconds)) {
            return;
        }

        self::refreshGlobal($limit);
    }

    public static function isGlobalFresh(int $maxAgeSeconds): bool
    {
        if (!self::tableReadyGlobal()) {
            return false;
        }

        // The timestamp (set by refreshGlobal even when 0 rows materialize) is the authoritative
        // freshness marker. Row count is intentionally not used: "nothing upcoming" is still fresh.
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::REFRESHED_AT_KEY]);
        $refreshed_at = trim((string) ($values[self::REFRESHED_AT_KEY] ?? ''));
        if ($refreshed_at === '') {
            return false;
        }

        $refreshed_ts = strtotime($refreshed_at);
        if ($refreshed_ts === false) {
            return false;
        }

        $now_ts = strtotime((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))) ?: time();

        return ($now_ts - $refreshed_ts) <= $maxAgeSeconds;
    }

    private static function collectPlanningRecalls(): array
    {
        global $DB;

        $rows = [];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $iterator = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'users_id', 'when', 'before_time'],
            'FROM'   => PlanningRecall::getTable(),
            'WHERE'  => [
                'NOT' => ['when' => null],
                'when' => ['>', $now],
            ],
            'ORDER'  => ['when ASC', 'id ASC'],
            'LIMIT'  => self::SOURCE_QUERY_LIMIT,
        ]);

        foreach ($iterator as $row) {
            $item = self::resolvePlanningRecallTargetItem((string) ($row['itemtype'] ?? ''), (int) ($row['items_id'] ?? 0));
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            $before_seconds = max(0, (int) ($row['before_time'] ?? 0));
            $detail = $before_seconds > 0
                ? sprintf(
                    __('Reminder %s before start', 'inappnotification'),
                    self::formatDuration($before_seconds)
                )
                : __('Reminder at start time', 'inappnotification');

            $rows[] = [
                'kind' => 'planning_recall',
                'kind_label' => __('Planning reminder'),
                'scheduled_at' => (string) ($row['when'] ?? ''),
                'itemtype' => $item::getType(),
                'items_id' => (int) $item->getID(),
                'item_label' => self::buildItemLabel($item),
                'item_url' => self::buildItemUrl($item),
                'detail' => $detail,
                'entities_id' => self::resolveEntityId($item),
                'recipients' => self::singleRecipient((int) ($row['users_id'] ?? 0)),
            ];
        }

        return $rows;
    }

    private static function collectPendingReasonSchedules(): array
    {
        global $DB;

        $rows = [];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

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
            $pending_item = PendingReason_Item::getById((int) ($row['id'] ?? 0));
            if (!$pending_item instanceof PendingReason_Item) {
                continue;
            }

            $item = self::loadItemForView(
                (string) ($pending_item->fields['itemtype'] ?? ''),
                (int) ($pending_item->fields['items_id'] ?? 0)
            );
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            $status = (int) ($item->fields['status'] ?? 0);
            if ($item instanceof CommonITILObject && $status !== CommonITILObject::WAITING) {
                continue;
            }

            $reason_name = '';
            $pending_reason = PendingReason::getById((int) ($pending_item->fields['pendingreasons_id'] ?? 0));
            if ($pending_reason instanceof PendingReason) {
                $reason_name = trim((string) ($pending_reason->fields['name'] ?? ''));
            }

            $next_bump = (string) ($pending_item->getNextFollowupDate() ?: '');
            if ($next_bump !== '' && $next_bump > $now) {
                $auto_reminder_recipients = self::resolveNotificationRecipientUsers($item, 'auto_reminder');
                $rows[] = [
                    'kind' => 'pending_autoreminder',
                    'kind_label' => __('Automatic reminder'),
                    'scheduled_at' => $next_bump,
                    'itemtype' => $item::getType(),
                    'items_id' => (int) $item->getID(),
                    'item_label' => self::buildItemLabel($item),
                    'item_url' => self::buildItemUrl($item),
                    'detail' => $reason_name !== ''
                        ? sprintf(__('Pending reason: %s', 'inappnotification'), $reason_name)
                        : __('Pending reason reminder', 'inappnotification'),
                    'entities_id' => self::resolveEntityId($item),
                    'recipients' => $auto_reminder_recipients,
                ];
            }

            $next_resolve = (string) ($pending_item->getAutoResolvedate() ?: '');
            if ($next_resolve !== '' && $next_resolve > $now) {
                $auto_resolve_recipients = self::resolveNotificationRecipientUsers($item, 'pendingreason_close');
                $rows[] = [
                    'kind' => 'pending_autoresolve',
                    'kind_label' => __('Automatic resolution', 'inappnotification'),
                    'scheduled_at' => $next_resolve,
                    'itemtype' => $item::getType(),
                    'items_id' => (int) $item->getID(),
                    'item_label' => self::buildItemLabel($item),
                    'item_url' => self::buildItemUrl($item),
                    'detail' => $reason_name !== ''
                        ? sprintf(__('Pending reason: %s', 'inappnotification'), $reason_name)
                        : __('Pending reason resolution', 'inappnotification'),
                    'entities_id' => self::resolveEntityId($item),
                    'recipients' => $auto_resolve_recipients,
                ];
            }
        }

        return $rows;
    }

    private static function collectFutureAlerts(): array
    {
        global $DB;

        $rows = [];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id', 'type', 'date'],
            'FROM'   => Alert::getTable(),
            'WHERE'  => [
                'NOT' => ['date' => null],
                'date' => ['>', $now],
            ],
            'ORDER'  => ['date ASC', 'id ASC'],
            'LIMIT'  => self::SOURCE_QUERY_LIMIT,
        ]);

        foreach ($iterator as $row) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $items_id = (int) ($row['items_id'] ?? 0);
            $item = self::resolveAlertTargetItem($itemtype, $items_id);
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            $rows[] = [
                'kind' => 'future_alert',
                'kind_label' => __('Scheduled alert', 'inappnotification'),
                'scheduled_at' => (string) ($row['date'] ?? ''),
                'itemtype' => $item::getType(),
                'items_id' => (int) $item->getID(),
                'item_label' => self::buildItemLabel($item),
                'item_url' => self::buildItemUrl($item),
                'detail' => self::getAlertTypeLabel((int) ($row['type'] ?? 0)),
                'entities_id' => self::resolveEntityId($item),
                'recipients' => [], // recipients not resolvable for Alert events -> all-users view only
            ];
        }

        return $rows;
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
                if (!$parent->canViewItem()) {
                    return null;
                }
                return $parent;
            }
        }

        return $item;
    }

    private static function resolveAlertTargetItem(string $itemtype, int $items_id): ?CommonDBTM
    {
        if ($itemtype === PlanningRecall::class || $itemtype === PlanningRecall::getType()) {
            $planning_recall = PlanningRecall::getById($items_id);
            if ($planning_recall instanceof PlanningRecall) {
                return self::resolvePlanningRecallTargetItem(
                    (string) ($planning_recall->fields['itemtype'] ?? ''),
                    (int) ($planning_recall->fields['items_id'] ?? 0)
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
        if (array_key_exists($cache_key, self::$item_cache)) {
            return self::$item_cache[$cache_key];
        }

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM) {
            self::$item_cache[$cache_key] = null;
            return null;
        }

        if (!$item->getFromDB($items_id)) {
            self::$item_cache[$cache_key] = null;
            return null;
        }

        if (!$item->canViewItem()) {
            self::$item_cache[$cache_key] = null;
            return null;
        }

        self::$item_cache[$cache_key] = $item;
        return $item;
    }

    private static function buildItemLabel(CommonDBTM $item): string
    {
        $name = trim((string) $item->getName());
        if ($name === '') {
            $name = (string) $item::getTypeName(1);
        }

        return sprintf('%s (%d)', $name, (int) $item->getID());
    }

    private static function buildItemUrl(CommonDBTM $item): string
    {
        $url = trim((string) $item->getLinkURL());
        if ($url !== '') {
            return $url;
        }

        $itemtype = $item::getType();
        if (method_exists($itemtype, 'getFormURLWithID')) {
            return (string) $itemtype::getFormURLWithID((int) $item->getID());
        }

        return '';
    }

    private static function resolveEntityId(CommonDBTM $item): int
    {
        if ($item->isEntityAssign()) {
            return (int) $item->getEntityID();
        }

        return 0;
    }

    private static function resolveEntityLabel(int $entities_id): string
    {
        if ($entities_id <= 0) {
            return __('All entities');
        }

        $name = Dropdown::getDropdownName('glpi_entities', $entities_id);
        return trim((string) $name) !== '' ? (string) $name : __('All entities');
    }

    private static function resolveRecipientLabel(int $users_id): string
    {
        if ($users_id <= 0) {
            return '';
        }

        $name = trim((string) getUserName($users_id, 0, true));
        if ($name !== '') {
            return $name;
        }

        return sprintf('%s #%d', __('User'), $users_id);
    }

    /**
     * Build a single-recipient map (users_id => label), or an empty map when no user is given.
     *
     * @return array<int, string>
     */
    private static function singleRecipient(int $users_id): array
    {
        return $users_id > 0 ? [$users_id => self::resolveRecipientLabel($users_id)] : [];
    }

    /**
     * Resolve in-app recipients configured for a GLPI notification event.
     *
     * @return array<int, string> users_id => display label
     */
    private static function resolveNotificationRecipientUsers(CommonDBTM $item, string $event): array
    {
        static $cache = [];

        $event = trim($event);
        if ($event === '') {
            return [];
        }

        $cache_key = strtolower($item::getType()) . ':' . (int) $item->getID() . ':' . strtolower($event);
        if (array_key_exists($cache_key, $cache)) {
            /** @var array<int, string> */
            return $cache[$cache_key];
        }

        $target = NotificationTarget::getInstance($item, $event);
        if (!$target instanceof NotificationTarget) {
            $cache[$cache_key] = [];
            return [];
        }

        $target->addAdditionnalInfosForTarget();
        $recipients = [];

        $notifications = Notification::getNotificationsByEventAndType(
            $event,
            $item::getType(),
            $target->getEntity()
        );

        foreach ($notifications as $data) {
            if ((string) ($data['mode'] ?? '') !== 'inapp') {
                continue;
            }

            $notification = Notification::getById((int) ($data['id'] ?? 0));
            if (
                !$notification instanceof Notification
                || !$notification->itemMatchFilter($item)
            ) {
                continue;
            }

            $target->clearAddressesList();
            $target->setMode('inapp');
            $target->setAllowResponse((bool) ($data['allow_response'] ?? false));

            try {
                $target->setEvent(Notification_NotificationTemplate::getModeClass('inapp', 'event'));
            } catch (\Throwable $e) {
                continue;
            }

            $targets = getAllDataFromTable(
                NotificationTarget::getTable(),
                ['notifications_id' => (int) ($data['id'] ?? 0)]
            );

            foreach ($targets as $target_data) {
                $target->addForTarget($target_data, []);
            }

            foreach ($target->getTargets() as $users_infos) {
                $users_id = (int) ($users_infos['users_id'] ?? 0);
                if ($users_id <= 0) {
                    continue;
                }

                if (!$target->validateSendTo($event, $users_infos, true, null)) {
                    continue;
                }

                $recipients[$users_id] = self::resolveRecipientLabel($users_id);
            }
        }

        if ($recipients !== []) {
            asort($recipients, SORT_NATURAL | SORT_FLAG_CASE);
        }

        $cache[$cache_key] = $recipients;
        return $recipients;
    }

    private static function getAlertTypeLabel(int $type): string
    {
        return match ($type) {
            Alert::THRESHOLD => __('Threshold'),
            Alert::END => __('End'),
            Alert::NOTICE => __('Notice'),
            Alert::NOTCLOSED => __('Not closed'),
            Alert::ACTION => __('Action'),
            Alert::PERIODICITY => __('Periodicity'),
            default => __('Alert'),
        };
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return __('0 minutes');
        }

        $days = intdiv($seconds, DAY_TIMESTAMP);
        $seconds -= $days * DAY_TIMESTAMP;
        $hours = intdiv($seconds, HOUR_TIMESTAMP);
        $seconds -= $hours * HOUR_TIMESTAMP;
        $minutes = intdiv($seconds, MINUTE_TIMESTAMP);

        $parts = [];
        if ($days > 0) {
            $parts[] = sprintf(_n('%d day', '%d days', $days), $days);
        }
        if ($hours > 0) {
            $parts[] = sprintf(_n('%d hour', '%d hours', $hours), $hours);
        }
        if ($minutes > 0 || $parts === []) {
            $parts[] = sprintf(_n('%d minute', '%d minutes', $minutes), $minutes);
        }

        return implode(' ', $parts);
    }
}
