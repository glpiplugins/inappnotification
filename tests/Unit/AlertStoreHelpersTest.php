<?php

declare(strict_types=1);

namespace GlpiPlugin\Inappnotification\Tests\Unit;

use GlpiPlugin\Inappnotification\Service\AlertStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AlertStoreHelpersTest extends TestCase
{
    public function testResolveSourceGroupClassifiesItemtypes(): void
    {
        self::assertSame('savedsearch', self::call('resolveSourceGroup', ['SavedSearch_Alert']));
        self::assertSame('savedsearch', self::call('resolveSourceGroup', ['Glpi\\Foo\\MySavedSearchThing']));
        self::assertSame('ticket', self::call('resolveSourceGroup', ['ITILFollowup']));
        self::assertSame('ticket', self::call('resolveSourceGroup', ['Ticket']));
        self::assertSame('project', self::call('resolveSourceGroup', ['Project']));
        self::assertSame('reminder', self::call('resolveSourceGroup', ['Reminder']));
        self::assertSame('sla', self::call('resolveSourceGroup', ['SLA']));
        self::assertSame('sla', self::call('resolveSourceGroup', ['SLM']));
        self::assertSame('crontask', self::call('resolveSourceGroup', ['CronTask']));
        self::assertSame('other', self::call('resolveSourceGroup', ['Computer']));
    }

    public function testMatchesItemtypeIsCaseAndLeadingSlashInsensitive(): void
    {
        self::assertTrue(self::call('matchesItemtype', ['\\Ticket', 'Ticket']));
        self::assertTrue(self::call('matchesItemtype', ['ticket', 'Ticket']));
        self::assertFalse(self::call('matchesItemtype', ['Ticket', 'Computer']));
    }

    public function testIsFormUrl(): void
    {
        self::assertTrue(self::call('isFormUrl', ['/front/ticket.form.php?id=1', 'ticket.form']));
        self::assertTrue(self::call('isFormUrl', ['/FRONT/TICKET.FORM.PHP', 'ticket.form']));
        self::assertFalse(self::call('isFormUrl', ['/front/computer.form.php', 'ticket.form']));
    }

    public function testBuildModeFingerprintIsDeterministic(): void
    {
        $a = self::call('buildModeFingerprint', ['Ticket', 5, 'new', 'Subject']);
        $b = self::call('buildModeFingerprint', ['Ticket', 5, 'new', 'Subject']);
        $c = self::call('buildModeFingerprint', ['Ticket', 6, 'new', 'Subject']);

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertStringStartsWith('mode-inapp:', $a);

        // An empty itemtype is normalised to 'Unknown' before hashing.
        self::assertSame(
            self::call('buildModeFingerprint', ['', 1, 'e', 's']),
            self::call('buildModeFingerprint', ['Unknown', 1, 'e', 's'])
        );
    }

    public function testExtractUserIdsAcceptsScalarsAndArraysAndDedupes(): void
    {
        self::assertSame([5], self::call('extractUserIds', [5]));
        self::assertSame([7], self::call('extractUserIds', ['7']));
        self::assertSame([], self::call('extractUserIds', [0]));
        self::assertSame([], self::call('extractUserIds', ['abc']));
        self::assertSame([], self::call('extractUserIds', [null]));
        self::assertSame([1, 2, 3], self::call('extractUserIds', [[1, 2, 2, '3', 0, -1, 'x']]));
    }

    public function testItemtypeAllowedByFiltersWhitelistAndBlacklist(): void
    {
        self::assertTrue(self::call('itemtypeAllowedByFilters', [[], 'Ticket']));
        // Empty itemtype is always allowed.
        self::assertTrue(self::call('itemtypeAllowedByFilters', [['exclude_itemtypes' => 'Ticket'], '']));
        // include_itemtypes acts as a case-insensitive whitelist.
        self::assertTrue(self::call('itemtypeAllowedByFilters', [['include_itemtypes' => 'ticket,project'], 'Ticket']));
        self::assertFalse(self::call('itemtypeAllowedByFilters', [['include_itemtypes' => 'project'], 'Ticket']));
        // exclude_itemtypes blacklists.
        self::assertFalse(self::call('itemtypeAllowedByFilters', [['exclude_itemtypes' => 'Ticket'], 'ticket']));
    }

    public function testTicketAllowedByFiltersEarlyReturns(): void
    {
        // open-only disabled => always allowed (no DB access on this path).
        self::assertTrue(self::call('ticketAllowedByFilters', [['ticket_open_only' => 0], 123]));
        // open-only enabled but no id => allowed.
        self::assertTrue(self::call('ticketAllowedByFilters', [['ticket_open_only' => 1], 0]));
    }

    public function testIsFutureDate(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-01-01 00:00:00';
        try {
            self::assertTrue(self::call('isFutureDate', ['2026-01-02 00:00:00']));
            self::assertFalse(self::call('isFutureDate', ['2025-12-31 00:00:00']));
            self::assertFalse(self::call('isFutureDate', ['']));
            self::assertFalse(self::call('isFutureDate', ['not a date!!!']));
        } finally {
            unset($_SESSION['glpi_currenttime']);
        }
    }

    public function testNormalizeSnoozeMinutesClampsToBounds(): void
    {
        self::assertSame(1440, self::call('normalizeSnoozeMinutes', [0]));      // default (24h)
        self::assertSame(1440, self::call('normalizeSnoozeMinutes', [-5]));     // default
        self::assertSame(30, self::call('normalizeSnoozeMinutes', [30]));
        self::assertSame(525600, self::call('normalizeSnoozeMinutes', [99999999])); // capped (365d)
    }

    public function testFormatDateNormalisesOrReturnsInputOnFailure(): void
    {
        self::assertSame('', self::call('formatDate', ['']));
        self::assertSame('2026-01-02 03:04:05', self::call('formatDate', ['2026-01-02 03:04:05']));
        self::assertSame('not a date!!!', self::call('formatDate', ['not a date!!!']));
    }

    public function testNormalizeTextStripsTagsAndCollapsesWhitespace(): void
    {
        self::assertSame('Hi there world', self::call('normalizeText', ["<b>Hi</b>  there\n\nworld"]));
        self::assertSame('', self::call('normalizeText', ['   ']));
    }

    public function testShortTextTruncatesWithEllipsis(): void
    {
        self::assertSame('hello', self::call('shortText', ['hello', 10]));
        self::assertSame('', self::call('shortText', ['', 5]));
        self::assertSame('', self::call('shortText', ['abc', 0]));
        self::assertSame('abc…', self::call('shortText', ['abcdef', 4]));
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function call(string $method, array $args = []): mixed
    {
        // private methods are invokable via reflection without setAccessible() since PHP 8.1
        return (new ReflectionMethod(AlertStore::class, $method))->invoke(null, ...$args);
    }
}
