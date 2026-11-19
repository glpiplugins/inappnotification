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

use GlpiPlugin\Inappnotification\Service\AlertStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AlertStoreHelpersTest extends TestCase
{
    public function testResolveSourceGroupClassifiesItemtypes(): void
    {
        self::assertSame('savedsearch', $this->call('resolveSourceGroup', ['SavedSearch_Alert']));
        self::assertSame('savedsearch', $this->call('resolveSourceGroup', ['Glpi\\Foo\\MySavedSearchThing']));
        self::assertSame('ticket', $this->call('resolveSourceGroup', ['ITILFollowup']));
        self::assertSame('ticket', $this->call('resolveSourceGroup', ['Ticket']));
        self::assertSame('project', $this->call('resolveSourceGroup', ['Project']));
        self::assertSame('reminder', $this->call('resolveSourceGroup', ['Reminder']));
        self::assertSame('sla', $this->call('resolveSourceGroup', ['SLA']));
        self::assertSame('sla', $this->call('resolveSourceGroup', ['SLM']));
        self::assertSame('crontask', $this->call('resolveSourceGroup', ['CronTask']));
        self::assertSame('other', $this->call('resolveSourceGroup', ['Computer']));
    }

    public function testMatchesItemtypeIsCaseAndLeadingSlashInsensitive(): void
    {
        self::assertTrue($this->call('matchesItemtype', ['\\Ticket', 'Ticket']));
        self::assertTrue($this->call('matchesItemtype', ['ticket', 'Ticket']));
        self::assertFalse($this->call('matchesItemtype', ['Ticket', 'Computer']));
    }

    public function testIsFormUrl(): void
    {
        self::assertTrue($this->call('isFormUrl', ['/front/ticket.form.php?id=1', 'ticket.form']));
        self::assertTrue($this->call('isFormUrl', ['/FRONT/TICKET.FORM.PHP', 'ticket.form']));
        self::assertFalse($this->call('isFormUrl', ['/front/computer.form.php', 'ticket.form']));
    }

    public function testBuildModeFingerprintIsDeterministic(): void
    {
        $a = $this->call('buildModeFingerprint', ['Ticket', 5, 'new', 'Subject']);
        $b = $this->call('buildModeFingerprint', ['Ticket', 5, 'new', 'Subject']);
        $c = $this->call('buildModeFingerprint', ['Ticket', 6, 'new', 'Subject']);

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertStringStartsWith('mode-inapp:', $a);

        // An empty itemtype is normalised to 'Unknown' before hashing.
        self::assertSame(
            $this->call('buildModeFingerprint', ['', 1, 'e', 's']),
            $this->call('buildModeFingerprint', ['Unknown', 1, 'e', 's']),
        );
    }

    public function testExtractUserIdsAcceptsScalarsAndArraysAndDedupes(): void
    {
        self::assertSame([5], $this->call('extractUserIds', [5]));
        self::assertSame([7], $this->call('extractUserIds', ['7']));
        self::assertSame([], $this->call('extractUserIds', [0]));
        self::assertSame([], $this->call('extractUserIds', ['abc']));
        self::assertSame([], $this->call('extractUserIds', [null]));
        self::assertSame([1, 2, 3], $this->call('extractUserIds', [[1, 2, 2, '3', 0, -1, 'x']]));
    }

    public function testItemtypeAllowedByFiltersWhitelistAndBlacklist(): void
    {
        self::assertTrue($this->call('itemtypeAllowedByFilters', [[], 'Ticket']));
        // Empty itemtype is always allowed.
        self::assertTrue($this->call('itemtypeAllowedByFilters', [['exclude_itemtypes' => 'Ticket'], '']));
        // include_itemtypes acts as a case-insensitive whitelist.
        self::assertTrue($this->call('itemtypeAllowedByFilters', [['include_itemtypes' => 'ticket,project'], 'Ticket']));
        self::assertFalse($this->call('itemtypeAllowedByFilters', [['include_itemtypes' => 'project'], 'Ticket']));
        // exclude_itemtypes blacklists.
        self::assertFalse($this->call('itemtypeAllowedByFilters', [['exclude_itemtypes' => 'Ticket'], 'ticket']));
    }

    public function testTicketAllowedByFiltersEarlyReturns(): void
    {
        // open-only disabled => always allowed (no DB access on this path).
        self::assertTrue($this->call('ticketAllowedByFilters', [['ticket_open_only' => 0], 123]));
        // open-only enabled but no id => allowed.
        self::assertTrue($this->call('ticketAllowedByFilters', [['ticket_open_only' => 1], 0]));
    }

    public function testIsFutureDate(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-01-01 00:00:00';
        try {
            self::assertTrue($this->call('isFutureDate', ['2026-01-02 00:00:00']));
            self::assertFalse($this->call('isFutureDate', ['2025-12-31 00:00:00']));
            self::assertFalse($this->call('isFutureDate', ['']));
            self::assertFalse($this->call('isFutureDate', ['not a date!!!']));
        } finally {
            unset($_SESSION['glpi_currenttime']);
        }
    }

    public function testNormalizeSnoozeMinutesClampsToBounds(): void
    {
        self::assertSame(1440, $this->call('normalizeSnoozeMinutes', [0]));      // default (24h)
        self::assertSame(1440, $this->call('normalizeSnoozeMinutes', [-5]));     // default
        self::assertSame(30, $this->call('normalizeSnoozeMinutes', [30]));
        self::assertSame(525600, $this->call('normalizeSnoozeMinutes', [99999999])); // capped (365d)
    }

    public function testEverySourceGroupHasALabel(): void
    {
        $labels = AlertStore::getSourceGroupLabels();
        foreach (['SavedSearch_Alert', 'Ticket', 'Project', 'Reminder', 'SLA', 'CronTask', 'Computer', ''] as $itemtype) {
            self::assertArrayHasKey($this->call('resolveSourceGroup', [$itemtype]), $labels, $itemtype);
        }
    }

    public function testNormalizeTextStripsTagsAndCollapsesWhitespace(): void
    {
        self::assertSame('Hi there world', $this->call('normalizeText', ["<b>Hi</b>  there\n\nworld"]));
        self::assertSame('', $this->call('normalizeText', ['   ']));
    }

    public function testShortTextTruncatesWithEllipsis(): void
    {
        self::assertSame('hello', $this->call('shortText', ['hello', 10]));
        self::assertSame('', $this->call('shortText', ['', 5]));
        self::assertSame('', $this->call('shortText', ['abc', 0]));
        self::assertSame('abc…', $this->call('shortText', ['abcdef', 4]));
    }

    /**
     * @param array<int, mixed> $args
     */
    private function call(string $method, array $args = []): mixed
    {
        // private methods are invokable via reflection without setAccessible() since PHP 8.1
        return (new ReflectionMethod(AlertStore::class, $method))->invoke(null, ...$args);
    }
}
