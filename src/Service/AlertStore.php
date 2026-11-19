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

use CommonGLPI;
use DateTime;
use GlpiPlugin\Inappnotification\Config\Settings;
use ITILFollowup;
use SavedSearch_Alert;
use Throwable;
use Ticket;

class AlertStore
{
    public const TABLE = 'glpi_plugin_inappnotification_alerts';

    private const DEFAULT_SNOOZE_MINUTES = 24 * 60;

    private const MAX_SNOOZE_MINUTES = 365 * 24 * 60;

    public static function captureFromNotificationMode(array $options): bool
    {
        $users_ids = self::extractUserIds($options['to'] ?? 0);
        if (count($users_ids) === 0) {
            return false;
        }

        $source = self::resolveCanonicalSource(
            (string) ($options['itemtype_trigger'] ?? $options['_itemtype'] ?? ''),
            (int) ($options['items_id_trigger'] ?? $options['_items_id'] ?? 0),
        );
        $itemtype = $source['itemtype'];
        $items_id = $source['items_id'];
        $event = trim((string) ($options['event'] ?? ''));
        $source_group = self::resolveSourceGroup($itemtype);
        $settings = Settings::get();

        if (!Settings::isSourceEnabled($settings, $source_group)) {
            return false;
        }

        if (!self::itemtypeAllowedByFilters($settings, $itemtype)) {
            return false;
        }

        if ($source_group === 'ticket' && !self::ticketAllowedByFilters($settings, $items_id)) {
            return false;
        }

        $subject = trim((string) ($options['subject'] ?? ''));
        $message = trim((string) ($options['content_text'] ?? $options['content_html'] ?? ''));
        $message = self::normalizeText($message);

        $source_label = self::resolveSourceLabel($itemtype, $source_group);
        $now = $_SESSION['glpi_currenttime'];
        $fingerprint = self::buildModeFingerprint($itemtype, $items_id, $event, $subject);
        $payload = [
            'entities_id' => (int) ($options['_entities_id'] ?? 0),
            'alert_name' => $subject !== ''
                ? $subject
                : sprintf(__('%s notification', 'inappnotification'), $source_label),
            'alert_message' => $message !== ''
                ? self::shortText($message, 600)
                : sprintf(__('%s alert triggered.', 'inappnotification'), $source_label),
            'target_url' => self::resolveTargetUrl($itemtype, $items_id),
            'source_group' => $source_group,
            'source_itemtype' => $itemtype,
            'source_items_id' => $items_id,
            'last_triggered_at' => $now,
            'date_mod' => $now,
        ];

        foreach ($users_ids as $users_id) {
            self::upsertNotification($users_id, $fingerprint, $payload);
        }

        return true;
    }

    public static function getForUser(int $users_id, int $limit = 15): array
    {
        global $DB;

        if ($users_id <= 0) {
            return [
                'items' => [],
                'unread_total' => 0,
                'muted_total' => 0,
            ];
        }

        // A snooze is "remind me later": once it expires, surface the notification as unread
        // again (badge + the new-unread toast/sound) instead of letting it reappear as read.
        self::reactivateExpiredSnoozes($users_id);

        $items = [];
        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => self::getVisibleWhere($users_id),
            'ORDER' => ['last_triggered_at DESC', 'id DESC'],
            'LIMIT' => $limit,
        ]);

        foreach ($iterator as $row) {
            $items[] = self::buildEntryPayload($row);
        }

        return [
            'items' => $items,
            'unread_total' => self::getUnreadTotal($users_id),
            'muted_total' => self::getMutedTotal($users_id),
        ];
    }

    public static function getMutedTotal(int $users_id): int
    {
        global $DB;

        if ($users_id <= 0) {
            return 0;
        }

        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => self::getMutedWhere($users_id),
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }

    public static function snooze(int $users_id, int $id, int $minutes): void
    {
        global $DB;

        if ($users_id <= 0 || $id <= 0) {
            return;
        }

        $minutes = self::normalizeSnoozeMinutes($minutes);
        $now = $_SESSION['glpi_currenttime'];
        $date = new DateTime($now);
        $date->modify('+' . $minutes . ' minutes');

        $DB->update(
            self::TABLE,
            [
                'is_suppressed' => 0,
                'snoozed_until' => $date->format('Y-m-d H:i:s'),
                'is_read' => 1,
                'unread_count' => 0,
                'date_mod' => $now,
            ],
            [
                'id' => $id,
                'users_id' => $users_id,
            ],
        );
    }

    public static function suppress(int $users_id, int $id): void
    {
        global $DB;

        if ($users_id <= 0 || $id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'];

        $DB->update(
            self::TABLE,
            [
                'is_suppressed' => 1,
                'snoozed_until' => null,
                'is_read' => 1,
                'unread_count' => 0,
                'date_mod' => $now,
            ],
            [
                'id' => $id,
                'users_id' => $users_id,
            ],
        );
    }

    public static function reactivate(int $users_id, int $id): void
    {
        global $DB;

        if ($users_id <= 0 || $id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'];

        $DB->update(
            self::TABLE,
            [
                'is_suppressed' => 0,
                'snoozed_until' => null,
                'date_mod' => $now,
            ],
            [
                'id' => $id,
                'users_id' => $users_id,
            ],
        );
    }

    /**
     * Flip the user's expired snoozes (not suppressed, snoozed_until in the past) back to unread,
     * so a "snooze 1 day" actually re-notifies once it lapses. Suppressed entries are left muted.
     * Indexed by idx_user_muted (users_id, is_suppressed, snoozed_until).
     */
    private static function reactivateExpiredSnoozes(int $users_id): void
    {
        global $DB;

        if ($users_id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'];

        $DB->update(
            self::TABLE,
            [
                'snoozed_until' => null,
                'is_read' => 0,
                'unread_count' => 1,
                'last_triggered_at' => $now,
                'date_mod' => $now,
            ],
            [
                'users_id' => $users_id,
                'is_suppressed' => 0,
                'snoozed_until' => ['<=', $now],
            ],
        );
    }

    public static function markAsRead(int $users_id, int $id): void
    {
        global $DB;

        if ($users_id <= 0 || $id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'];
        $DB->update(
            self::TABLE,
            [
                'is_read' => 1,
                'unread_count' => 0,
                'date_mod' => $now,
            ],
            [
                'id' => $id,
                'users_id' => $users_id,
            ],
        );
    }

    public static function markAllAsRead(int $users_id): void
    {
        global $DB;

        if ($users_id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'];
        $DB->update(
            self::TABLE,
            [
                'is_read' => 1,
                'unread_count' => 0,
                'date_mod' => $now,
            ],
            [
                'users_id' => $users_id,
                'is_read' => 0,
            ],
        );
    }

    public static function markAsUnread(int $users_id, int $id): void
    {
        global $DB;

        if ($users_id <= 0 || $id <= 0) {
            return;
        }

        $row = $DB->request([
            'SELECT' => ['unread_count', 'is_suppressed', 'snoozed_until'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'id' => $id,
                'users_id' => $users_id,
            ],
            'LIMIT'  => 1,
        ])->current();

        if (!is_array($row)) {
            return;
        }

        if (self::isRowMuted($row)) {
            return;
        }

        $unread_count = max(1, (int) ($row['unread_count'] ?? 0));
        $now = $_SESSION['glpi_currenttime'];

        $DB->update(
            self::TABLE,
            [
                'is_read' => 0,
                'unread_count' => $unread_count,
                'date_mod' => $now,
            ],
            [
                'id' => $id,
                'users_id' => $users_id,
            ],
        );
    }

    public static function getUnreadTotal(int $users_id): int
    {
        global $DB;

        if ($users_id <= 0) {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['SUM' => 'unread_count AS total'],
            'FROM'   => self::TABLE,
            'WHERE'  => self::getVisibleWhere($users_id) + ['is_read' => 0],
        ])->current();

        return (int) ($row['total'] ?? 0);
    }

    public static function getUnreadCountByGroup(int $users_id, string $group): int
    {
        global $DB;

        if ($users_id <= 0 || $group === '') {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['SUM' => 'unread_count AS total'],
            'FROM'   => self::TABLE,
            'WHERE'  => self::getVisibleWhere($users_id) + [
                'is_read' => 0,
                'source_group' => $group,
            ],
        ])->current();

        return (int) ($row['total'] ?? 0);
    }

    private static function upsertNotification(int $users_id, string $fingerprint, array $data): void
    {
        global $DB;

        if ($users_id <= 0 || $fingerprint === '') {
            return;
        }

        $existing = $DB->request([
            'SELECT' => ['id', 'occurrence_count', 'unread_count', 'is_suppressed', 'snoozed_until'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'users_id' => $users_id,
                'alert_fingerprint' => $fingerprint,
            ],
            'LIMIT'  => 1,
        ])->current();

        if (is_array($existing) && isset($existing['id'])) {
            $is_muted = self::isRowMuted($existing);
            $DB->update(
                self::TABLE,
                $data + [
                    'occurrence_count' => (int) $existing['occurrence_count'] + 1,
                    'unread_count' => $is_muted
                        ? 0
                        : ((int) $existing['unread_count'] + 1),
                    'is_read' => $is_muted ? 1 : 0,
                ],
                ['id' => (int) $existing['id']],
            );
            return;
        }

        $now = $_SESSION['glpi_currenttime'];
        $insert = $data + [
            'users_id' => $users_id,
            'alert_fingerprint' => $fingerprint,
            'occurrence_count' => 1,
            'unread_count' => 1,
            'is_read' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ];

        $DB->insert(self::TABLE, $insert);
    }

    /**
     * Entry as sent to the notification bell.
     *
     * @return array{id:int, display_title:string, target_url:string, source_group:string,
     *               source_label:string, occurrence_count:int, unread_count:int}
     */
    private static function buildEntryPayload(array $row): array
    {
        $source = self::resolveCanonicalSource((string) $row['source_itemtype'], (int) $row['source_items_id']);
        $source_group = self::resolveSourceGroup($source['itemtype']);

        return [
            'id' => (int) $row['id'],
            'display_title' => self::resolveDisplayTitle($source['itemtype'], $source['items_id'], (string) $row['alert_name']),
            'target_url' => self::resolveEntryTargetUrl(
                (string) $row['source_itemtype'],
                (int) $row['source_items_id'],
                (string) $row['target_url'],
            ),
            'source_group' => $source_group,
            'source_label' => self::resolveSourceLabel($source['itemtype'], $source_group),
            'occurrence_count' => (int) $row['occurrence_count'],
            'unread_count' => (int) $row['unread_count'],
        ];
    }

    /**
     * Translated labels of the source families (values of the `source_group` column).
     *
     * @return array<string, string>
     */
    public static function getSourceGroupLabels(): array
    {
        return [
            'savedsearch' => __('Saved search', 'inappnotification'),
            'ticket'      => __('Ticket', 'inappnotification'),
            'project'     => __('Project', 'inappnotification'),
            'reminder'    => __('Reminder', 'inappnotification'),
            'sla'         => __('SLA', 'inappnotification'),
            'crontask'    => __('Automatic action', 'inappnotification'),
            'other'       => __('Other', 'inappnotification'),
        ];
    }

    private static function resolveSourceGroup(string $itemtype): string
    {
        $name = strtolower(ltrim($itemtype, '\\'));

        if (str_contains($name, 'savedsearch')) {
            return 'savedsearch';
        }

        if (self::matchesItemtype($itemtype, ITILFollowup::class)) {
            return 'ticket';
        }

        if (str_contains($name, 'ticket')) {
            return 'ticket';
        }

        if (str_contains($name, 'project')) {
            return 'project';
        }

        if (str_contains($name, 'reminder')) {
            return 'reminder';
        }

        if (str_contains($name, 'sla') || str_contains($name, 'slm')) {
            return 'sla';
        }

        if (str_contains($name, 'crontask') || str_contains($name, 'cron')) {
            return 'crontask';
        }

        return 'other';
    }

    private static function resolveSourceLabel(string $itemtype, string $source_group): string
    {
        if (self::matchesItemtype($itemtype, ITILFollowup::class)) {
            return Ticket::getTypeName(1);
        }

        if (is_a($itemtype, CommonGLPI::class, true)) {
            return $itemtype::getTypeName(1);
        }

        return self::getSourceGroupLabels()[$source_group];
    }

    private static function resolveDisplayTitle(string $source_itemtype, int $source_items_id, string $fallback): string
    {
        $fallback = trim($fallback);
        if ($source_items_id <= 0) {
            return $fallback;
        }

        if (self::resolveSourceGroup($source_itemtype) !== 'ticket') {
            return $fallback;
        }

        $title = self::resolveTicketTitle($source_items_id);
        if ($title === '') {
            $title = $fallback !== '' ? $fallback : Ticket::getTypeName(1);
        }

        return sprintf('%s (%d)', $title, $source_items_id);
    }

    private static function resolveTicketTitle(int $tickets_id): string
    {
        static $ticket_titles = [];

        if ($tickets_id <= 0) {
            return '';
        }

        if (array_key_exists($tickets_id, $ticket_titles)) {
            return $ticket_titles[$tickets_id];
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            $ticket_titles[$tickets_id] = '';
            return '';
        }

        $title = trim((string) ($ticket->fields['name'] ?? ''));
        $ticket_titles[$tickets_id] = $title;

        return $title;
    }

    /**
     * Normalize technical trigger itemtypes to their business source.
     * Example: ITILFollowup -> parent item (Ticket/Change/Problem...).
     *
     * @return array{itemtype:string,items_id:int}
     */
    private static function resolveCanonicalSource(string $itemtype, int $items_id): array
    {
        $itemtype = trim($itemtype);

        if ($itemtype === '' || $items_id <= 0) {
            return [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        if (!self::matchesItemtype($itemtype, ITILFollowup::class)) {
            return [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        $followup = new ITILFollowup();
        if (!$followup->getFromDB($items_id)) {
            return [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        $parent_itemtype = trim((string) ($followup->fields['itemtype'] ?? ''));
        $parent_items_id = (int) ($followup->fields['items_id'] ?? 0);

        if ($parent_itemtype === '' || $parent_items_id <= 0) {
            return [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ];
        }

        return [
            'itemtype' => $parent_itemtype,
            'items_id' => $parent_items_id,
        ];
    }

    public static function resolveEntryTargetUrl(
        string $source_itemtype,
        int $source_items_id,
        string $target_url = '',
    ): string {
        $source_itemtype = trim($source_itemtype);
        $target_url = trim($target_url);

        if ($source_itemtype === '' || $source_items_id <= 0) {
            return $target_url;
        }

        if (self::matchesItemtype($source_itemtype, ITILFollowup::class)) {
            $resolved = self::resolveITILFollowupTargetUrl($source_items_id);
            return $resolved !== '' ? $resolved : $target_url;
        }

        if (
            self::matchesItemtype($source_itemtype, SavedSearch_Alert::class)
            && (
                $target_url === ''
                || self::isFormUrl($target_url, 'savedsearch_alert.form.php')
            )
        ) {
            $resolved = self::resolveSavedSearchAlertTargetUrl($source_items_id);
            return $resolved !== '' ? $resolved : $target_url;
        }

        if ($target_url === '') {
            return self::resolveTargetUrl($source_itemtype, $source_items_id);
        }

        return $target_url;
    }

    private static function resolveTargetUrl(string $itemtype, int $items_id): string
    {
        if ($items_id <= 0 || trim($itemtype) === '') {
            return '';
        }

        if (self::matchesItemtype($itemtype, ITILFollowup::class)) {
            return self::resolveITILFollowupTargetUrl($items_id);
        }

        if (self::matchesItemtype($itemtype, SavedSearch_Alert::class)) {
            return self::resolveSavedSearchAlertTargetUrl($items_id);
        }

        return self::resolveGenericTargetUrl($itemtype, $items_id);
    }

    private static function resolveGenericTargetUrl(string $itemtype, int $items_id): string
    {
        if ($items_id <= 0 || trim($itemtype) === '') {
            return '';
        }

        if (!class_exists($itemtype) || !method_exists($itemtype, 'getFormURLWithID')) {
            return '';
        }

        return (string) $itemtype::getFormURLWithID($items_id);
    }

    private static function resolveITILFollowupTargetUrl(int $followups_id): string
    {
        if ($followups_id <= 0) {
            return '';
        }

        $followup = new ITILFollowup();
        if (!$followup->getFromDB($followups_id)) {
            return '';
        }

        $itemtype = (string) ($followup->fields['itemtype'] ?? '');
        $items_id = (int) ($followup->fields['items_id'] ?? 0);

        return self::resolveGenericTargetUrl($itemtype, $items_id);
    }

    private static function resolveSavedSearchAlertTargetUrl(int $savedsearch_alert_id): string
    {
        if ($savedsearch_alert_id <= 0) {
            return '';
        }

        $savedsearch_alert = new SavedSearch_Alert();
        if (!$savedsearch_alert->getFromDB($savedsearch_alert_id)) {
            return self::resolveGenericTargetUrl(SavedSearch_Alert::class, $savedsearch_alert_id);
        }

        $savedsearches_id = (int) ($savedsearch_alert->fields['savedsearches_id'] ?? 0);
        if ($savedsearches_id <= 0) {
            return self::resolveGenericTargetUrl(SavedSearch_Alert::class, $savedsearch_alert_id);
        }

        // Open the *executed* saved search: front/savedsearch.php?action=load redirects to the
        // search results (SavedSearch::load()), instead of the saved-search edit form.
        global $CFG_GLPI;
        $root = $CFG_GLPI['root_doc'];
        return $root . '/front/savedsearch.php?action=load&id=' . $savedsearches_id;
    }

    private static function matchesItemtype(string $itemtype, string $expected): bool
    {
        return strtolower(ltrim($itemtype, '\\')) === strtolower(ltrim($expected, '\\'));
    }

    private static function isFormUrl(string $url, string $needle): bool
    {
        return str_contains(strtolower($url), strtolower($needle));
    }

    private static function buildModeFingerprint(string $itemtype, int $items_id, string $event, string $subject): string
    {
        return 'mode-inapp:' . sha1(implode('|', [
            $itemtype !== '' ? $itemtype : 'Unknown',
            (string) $items_id,
            $event,
            $subject,
        ]));
    }

    private static function extractUserIds($value): array
    {
        $users_ids = [];

        if (is_numeric($value)) {
            $id = (int) $value;
            if ($id > 0) {
                $users_ids[] = $id;
            }

            return $users_ids;
        }

        if (!is_array($value)) {
            return [];
        }

        foreach ($value as $entry) {
            if (is_numeric($entry)) {
                $id = (int) $entry;
                if ($id > 0 && !in_array($id, $users_ids, true)) {
                    $users_ids[] = $id;
                }
            }
        }

        return $users_ids;
    }

    private static function itemtypeAllowedByFilters(array $settings, string $itemtype): bool
    {
        $itemtype_lc = strtolower(trim($itemtype));
        if ($itemtype_lc === '') {
            return true;
        }

        $includes = array_map(
            strtolower(...),
            Settings::getCsvValues($settings, 'include_itemtypes'),
        );
        $excludes = array_map(
            strtolower(...),
            Settings::getCsvValues($settings, 'exclude_itemtypes'),
        );

        if ($includes !== [] && !in_array($itemtype_lc, $includes, true)) {
            return false;
        }

        return !in_array($itemtype_lc, $excludes, true);
    }

    private static function ticketAllowedByFilters(array $settings, int $items_id): bool
    {
        if ((int) ($settings['ticket_open_only'] ?? 0) !== 1) {
            return true;
        }

        if ($items_id <= 0) {
            return true;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($items_id)) {
            return true;
        }

        $status = (int) ($ticket->fields['status'] ?? 0);
        return !in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true);
    }

    private static function getVisibleWhere(int $users_id): array
    {
        return [
            'users_id' => $users_id,
            'is_suppressed' => 0,
            [
                'OR' => [
                    ['snoozed_until' => null],
                    ['snoozed_until' => ['<=', $_SESSION['glpi_currenttime']]],
                ],
            ],
        ];
    }

    private static function getMutedWhere(int $users_id): array
    {
        return [
            'users_id' => $users_id,
            self::getMutedCriteria(),
        ];
    }

    /**
     * WHERE criteria for a muted entry: suppressed, or snoozed until a future time. Shared with the
     * muted search list (plugin_inappnotification_restriction_where()), so the list and the bell's
     * muted count agree.
     *
     * @param string $table Table name to qualify the columns with ('' = unqualified).
     *
     * @return array{OR: array<int, array<string, mixed>>}
     */
    public static function getMutedCriteria(string $table = ''): array
    {
        $prefix = $table !== '' ? $table . '.' : '';
        $now = $_SESSION['glpi_currenttime'];

        return [
            'OR' => [
                [$prefix . 'is_suppressed' => 1],
                [$prefix . 'snoozed_until' => ['>', $now]],
            ],
        ];
    }

    private static function isRowMuted(array $row): bool
    {
        if ((int) ($row['is_suppressed'] ?? 0) === 1) {
            return true;
        }

        return self::isFutureDate((string) ($row['snoozed_until'] ?? ''));
    }

    private static function isFutureDate(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime($_SESSION['glpi_currenttime']);
            return $date > $now;
        } catch (Throwable) {
            return false;
        }
    }

    private static function normalizeSnoozeMinutes(int $minutes): int
    {
        if ($minutes <= 0) {
            return self::DEFAULT_SNOOZE_MINUTES;
        }

        return min(self::MAX_SNOOZE_MINUTES, $minutes);
    }

    private static function normalizeText(string $value): string
    {
        $text = trim(strip_tags($value));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function shortText(string $value, int $max_len): string
    {
        if ($value === '' || $max_len <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $max_len) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $max_len - 1))) . '…';
    }
}
