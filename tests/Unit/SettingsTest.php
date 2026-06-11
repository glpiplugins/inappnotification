<?php

declare(strict_types=1);

namespace GlpiPlugin\Inappnotification\Tests\Unit;

use GlpiPlugin\Inappnotification\Config\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SettingsTest extends TestCase
{
    public function testDefaultsShape(): void
    {
        $defaults = Settings::defaults();

        $expectedKeys = [
            'capture_savedsearch', 'capture_ticket', 'capture_project', 'capture_reminder',
            'capture_sla', 'capture_crontask', 'capture_other', 'ticket_open_only',
            'include_itemtypes', 'exclude_itemtypes',
        ];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $defaults);
        }

        self::assertSame(1, $defaults['capture_savedsearch']);
        self::assertSame(1, $defaults['capture_ticket']);
        self::assertSame(0, $defaults['ticket_open_only']);
        self::assertSame('', $defaults['include_itemtypes']);
        self::assertSame('', $defaults['exclude_itemtypes']);
    }

    public function testIsSourceEnabledMapsGroupsAndFallsBackToOther(): void
    {
        self::assertTrue(Settings::isSourceEnabled(['capture_ticket' => 1], 'ticket'));
        self::assertFalse(Settings::isSourceEnabled(['capture_ticket' => 0], 'ticket'));
        self::assertTrue(Settings::isSourceEnabled(['capture_savedsearch' => 1], 'savedsearch'));

        // Unknown groups map onto capture_other.
        self::assertTrue(Settings::isSourceEnabled(['capture_other' => 1], 'totally-unknown'));
        self::assertFalse(Settings::isSourceEnabled(['capture_other' => 0], 'totally-unknown'));

        // Missing key counts as disabled.
        self::assertFalse(Settings::isSourceEnabled([], 'ticket'));
    }

    public function testGetCsvValuesParsesTrimsAndFilters(): void
    {
        self::assertSame([], Settings::getCsvValues(['k' => ''], 'k'));
        self::assertSame([], Settings::getCsvValues([], 'k'));
        self::assertSame(['a', 'b', 'c'], Settings::getCsvValues(['k' => 'a,b,c'], 'k'));
        // Trims each part and drops empty segments.
        self::assertSame(['a', 'b', 'c'], Settings::getCsvValues(['k' => ' a , b ,, c '], 'k'));
    }

    public function testNormalizeCoercesIntsTrimsStringsAndIgnoresUnknownKeys(): void
    {
        $out = self::call('normalize', [[
            'capture_ticket'    => '5',     // > 0 => 1
            'capture_project'   => '0',     // => 0
            'capture_sla'       => -3,      // <= 0 => 0
            'include_itemtypes' => '  Ticket ',
            'unknown_key'       => 'x',
        ]]);

        self::assertSame(1, $out['capture_ticket']);
        self::assertSame(0, $out['capture_project']);
        self::assertSame(0, $out['capture_sla']);
        self::assertSame('Ticket', $out['include_itemtypes']);
        self::assertArrayNotHasKey('unknown_key', $out);
        // Keys not present in the raw input keep their default.
        self::assertSame(1, $out['capture_other']);
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function call(string $method, array $args = []): mixed
    {
        // private methods are invokable via reflection without setAccessible() since PHP 8.1
        return (new ReflectionMethod(Settings::class, $method))->invoke(null, ...$args);
    }
}
