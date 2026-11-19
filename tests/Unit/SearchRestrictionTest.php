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

use CommonDBTM;
use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\MutedAlertEntry;
use GlpiPlugin\Inappnotification\ScheduledEntry;
use GlpiPlugin\Inappnotification\ScheduledGlobalEntry;
use PHPUnit\Framework\TestCase;

/**
 * The search restriction in hook.php is the only thing that keeps one user's notifications out of
 * another user's search results.
 */
final class SearchRestrictionTest extends TestCase
{
    private const string ALERTS = 'glpi_plugin_inappnotification_alerts';

    private const string NOW = '2026-10-22 12:00:00';

    /** @var array<string, mixed> */
    private array $savedSession = [];

    private mixed $savedDb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/hook.php';
    }

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedDb = $GLOBALS['DB'] ?? null;
        // Session::haveRight() asks the DB whether it is a read-only replica.
        $GLOBALS['DB'] = new class {
            public function isReplica(): bool
            {
                return false;
            }
        };
        $_SESSION = [
            'glpiID' => 7,
            'glpi_currenttime' => self::NOW,
            'glpiactiveprofile' => [],
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $GLOBALS['DB'] = $this->savedDb;
    }

    public function testAlertEntryIsRestrictedToTheCurrentUser(): void
    {
        self::assertSame(
            [self::ALERTS . '.users_id' => 7],
            plugin_inappnotification_restriction_where(AlertEntry::class),
        );
    }

    public function testMutedAlertEntryAddsTheFixedMutedFilter(): void
    {
        self::assertSame(
            [
                self::ALERTS . '.users_id' => 7,
                [
                    'OR' => [
                        [self::ALERTS . '.is_suppressed' => 1],
                        [self::ALERTS . '.snoozed_until' => ['>', self::NOW]],
                    ],
                ],
            ],
            plugin_inappnotification_restriction_where(MutedAlertEntry::class),
        );
    }

    public function testScheduledEntryIsRestrictedToTheCurrentUser(): void
    {
        self::assertSame(
            ['glpi_plugin_inappnotification_scheduled.users_id' => 7],
            plugin_inappnotification_restriction_where(ScheduledEntry::class),
        );
    }

    public function testAllUsersUpcomingNeedsTheDedicatedRight(): void
    {
        self::assertSame(
            ['glpi_plugin_inappnotification_scheduled_global.id' => 0],
            plugin_inappnotification_restriction_where(ScheduledGlobalEntry::class),
        );

        $_SESSION['glpiactiveprofile'][Access::RIGHT_UPCOMING_ALL] = READ;
        self::assertSame([], plugin_inappnotification_restriction_where(ScheduledGlobalEntry::class));
    }

    public function testLoggedOutUsersSeeNothing(): void
    {
        unset($_SESSION['glpiID']);

        self::assertSame([self::ALERTS . '.id' => 0], plugin_inappnotification_restriction_where(AlertEntry::class));
        self::assertSame([self::ALERTS . '.id' => 0], plugin_inappnotification_restriction_where(MutedAlertEntry::class));
        self::assertSame(
            ['glpi_plugin_inappnotification_scheduled.id' => 0],
            plugin_inappnotification_restriction_where(ScheduledEntry::class),
        );
    }

    public function testOtherItemtypesAreLeftAlone(): void
    {
        self::assertNull(plugin_inappnotification_restriction_where('Ticket'));
        self::assertSame(
            ['Ticket', ['glpi_tickets.is_deleted' => 0]],
            plugin_inappnotification_add_default_where(['Ticket', ['glpi_tickets.is_deleted' => 0]]),
        );
    }

    public function testDefaultWhereHookAppendsTheRestriction(): void
    {
        self::assertSame(
            [AlertEntry::class, ['x' => 1, [self::ALERTS . '.users_id' => 7]]],
            plugin_inappnotification_add_default_where([AlertEntry::class, ['x' => 1]]),
        );
        self::assertSame(
            [AlertEntry::class, [self::ALERTS . '.users_id' => 7]],
            plugin_inappnotification_add_default_where([AlertEntry::class, []]),
        );
    }

    /**
     * Personal lists must not depend on the active entity: the bell counts the user's entries
     * across entities, so the lists must show them all as well.
     */
    public function testPersonalListsAreNotEntityBound(): void
    {
        foreach ([AlertEntry::class, MutedAlertEntry::class, ScheduledEntry::class] as $itemtype) {
            self::assertFalse((new $itemtype())->isEntityAssign(), $itemtype . ' is filtered by the active entity');
        }
    }

    /**
     * The all-users list, unlike the personal ones, is limited to the active entities.
     */
    public function testAllUsersUpcomingIsEntityBound(): void
    {
        self::assertTrue((new ScheduledGlobalEntry())->isEntityAssign());
    }

    /**
     * A plugin itemtype missing from plugin_inappnotification_restriction_where() gets no
     * restriction at all, i.e. every user would see every row in GLPI search.
     */
    public function testEveryPluginItemtypeIsRestricted(): void
    {
        $itemtypes = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/*.php') ?: [] as $file) {
            $class = 'GlpiPlugin\\Inappnotification\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, CommonDBTM::class)) {
                $itemtypes[] = $class;
            }
        }

        self::assertContains(MutedAlertEntry::class, $itemtypes, 'itemtype discovery is broken');
        foreach ($itemtypes as $itemtype) {
            self::assertNotNull(
                plugin_inappnotification_restriction_where($itemtype),
                $itemtype . ' is not restricted to the current user in GLPI search',
            );
        }
    }
}
