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

// Pure, framework-agnostic helpers of the bell.
// `t` is the i18n lookup (key -> translated label), injected by the caller.

// Source families, as sent by the server (`source_group`), in tab order.
export const SOURCE_GROUP_ORDER = ['ticket', 'savedsearch', 'project', 'reminder', 'sla', 'crontask', 'other'];

const GROUP_LABEL_KEYS = {
    ticket: 'Ticket',
    savedsearch: 'Search',
    project: 'Project',
    reminder: 'Reminder',
    sla: 'SLA',
    crontask: 'Automatic action',
    other: 'Other',
};

export function getGroupLabel(t, group) {
    return t(GROUP_LABEL_KEYS[group] || GROUP_LABEL_KEYS.other);
}

// Fills the `%d` placeholder of a translated label.
export function formatCount(label, count) {
    return label.replace('%d', String(count));
}

export function badgeLabel(value) {
    return Number(value) > 99 ? '99+' : String(value);
}

export function getItemSourceLabel(t, item) {
    return item.source_group === 'savedsearch' ? t('Search') : item.source_label;
}

export function computeNewUnreadChanges(previousItems, nextItems) {
    const previousUnreadById = new Map();

    previousItems.forEach((item) => {
        const id = Number(item && item.id ? item.id : 0);
        if (id <= 0) {
            return;
        }
        previousUnreadById.set(id, Math.max(0, Number(item.unread_count || 0)));
    });

    let delta = 0;
    const items = [];
    nextItems.forEach((item) => {
        const id = Number(item && item.id ? item.id : 0);
        if (id <= 0) {
            return;
        }

        const nextUnread = Math.max(0, Number(item.unread_count || 0));
        const previousUnread = Number(previousUnreadById.get(id) || 0);

        if (nextUnread > previousUnread) {
            const added = nextUnread - previousUnread;
            delta += added;
            items.push({ item, added });
        }
    });

    return { delta, items };
}

export function sanitizeToastUrl(value) {
    const raw = String(value || '').trim();
    if (raw === '' || raw.startsWith('#')) {
        return '';
    }
    if (raw.startsWith('/')) {
        return raw;
    }
    try {
        const parsed = new URL(raw, window.location.origin);
        if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
            return parsed.href;
        }
    } catch (e) {
        // Ignore invalid URLs.
    }
    return '';
}

// Only needed to build the toast HTML string passed to glpi_toast_info().
// All Vue templates auto-escape, so escaping is not needed anywhere else.
export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}
