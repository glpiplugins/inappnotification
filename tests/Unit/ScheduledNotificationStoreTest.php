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

declare(strict_types=1);

namespace GlpiPlugin\Inappnotification\Tests\Unit;

use Alert;
use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;
use PHPUnit\Framework\TestCase;

/**
 * Upcoming notifications are stored language-neutral and translated when displayed.
 */
final class ScheduledNotificationStoreTest extends TestCase
{
    public function testEveryKindHasALabel(): void
    {
        self::assertSame(
            [
                ScheduledNotificationStore::KIND_PLANNING_RECALL,
                ScheduledNotificationStore::KIND_PENDING_AUTOREMINDER,
                ScheduledNotificationStore::KIND_PENDING_AUTORESOLVE,
                ScheduledNotificationStore::KIND_FUTURE_ALERT,
            ],
            array_keys(ScheduledNotificationStore::getKindLabels()),
        );
    }

    public function testPlanningRecallDetailIsTheLeadTime(): void
    {
        // The lead time itself is formatted by GLPI (Html::timestampToString()).
        $detail = ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_PLANNING_RECALL, '5400');
        self::assertStringStartsWith('Reminder 1 ', $detail);
        self::assertStringEndsWith(' 30 minutes before start', $detail);
        self::assertSame(
            'Reminder at start time',
            ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_PLANNING_RECALL, '0'),
        );
    }

    public function testPendingReasonDetailNamesTheReason(): void
    {
        self::assertSame(
            'Pending reason: Waiting for parts',
            ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_PENDING_AUTOREMINDER, 'Waiting for parts'),
        );
        self::assertSame(
            'Pending reason reminder',
            ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_PENDING_AUTOREMINDER, ''),
        );
        self::assertSame(
            'Pending reason resolution',
            ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_PENDING_AUTORESOLVE, ''),
        );
    }

    public function testFutureAlertDetailIsTheAlertType(): void
    {
        self::assertSame(
            'Threshold',
            ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_FUTURE_ALERT, (string) Alert::THRESHOLD),
        );
        self::assertSame('', ScheduledNotificationStore::formatDetail(ScheduledNotificationStore::KIND_FUTURE_ALERT, '99'));
    }
}
