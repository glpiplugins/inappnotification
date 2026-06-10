<?php

use GlpiPlugin\Inappnotification\Access;
use GlpiPlugin\Inappnotification\AlertEntry;
use GlpiPlugin\Inappnotification\ScheduledEntry;
use GlpiPlugin\Inappnotification\ScheduledGlobalEntry;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Search restriction (WHERE) that keeps a plugin itemtype's rows visible only to the
 * current user. Shared by the two hooks below.
 *
 * Returns:
 *  - the WHERE criteria array for one of the plugin's itemtypes,
 *  - an empty array when the itemtype is ours but no restriction applies
 *    (ScheduledGlobalEntry with the "view all upcoming" right),
 *  - null when the itemtype is not handled by this plugin.
 *
 * @return array<string,int>|null
 */
function plugin_inappnotification_restriction_where(string $itemtype): ?array
{
    if ($itemtype === AlertEntry::class) {
        $table = AlertEntry::getTable();
    } elseif ($itemtype === ScheduledEntry::class) {
        $table = ScheduledEntry::getTable();
    } elseif ($itemtype === ScheduledGlobalEntry::class) {
        // The "all users upcoming" list is gated by a dedicated right, not by ownership.
        return Access::canViewAllUpcoming()
            ? []
            : [ScheduledGlobalEntry::getTable() . '.id' => 0];
    } else {
        return null;
    }

    $users_id = (int) Session::getLoginUserID();
    return $users_id > 0
        ? [$table . '.users_id' => $users_id]
        : [$table . '.id' => 0];
}

/**
 * Primary guard: GLPI's Search SQL provider calls plugin_<plugin>_addDefaultWhere() by name
 * convention (Hooks::AUTO_ADD_DEFAULT_WHERE) for the plugin's own itemtypes. This is what keeps
 * notification rows scoped to the current user; do not remove it (search data leak otherwise).
 *
 * @return array<string,int>
 */
function plugin_inappnotification_addDefaultWhere(string $itemtype): array
{
    return plugin_inappnotification_restriction_where($itemtype) ?? [];
}

/**
 * Secondary safety net: registered on Hooks::ADD_DEFAULT_WHERE (runs for every search). It
 * appends the same per-user restriction for the plugin's itemtypes and leaves other itemtypes
 * untouched.
 *
 * @param array{0:string,1:mixed} $input
 *
 * @return array{0:string,1:mixed}
 */
function plugin_inappnotification_add_default_where(array $input): array
{
    [$itemtype, $criteria] = $input;

    $where = plugin_inappnotification_restriction_where($itemtype);
    if ($where === null || $where === []) {
        // Not ours, or ours but unrestricted: leave the query unchanged.
        return $input;
    }

    if (is_array($criteria) && count($criteria) > 0) {
        $criteria[] = $where;
    } else {
        $criteria = $where;
    }

    return [$itemtype, $criteria];
}
