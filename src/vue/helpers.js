// Pure, framework-agnostic helpers ported from the legacy bell implementation.
// `t` is the i18n lookup (key -> translated label), injected by the caller.

export const SOURCE_GROUP_ORDER = ['ticket', 'savedsearch', 'project', 'reminder', 'sla', 'crontask', 'other'];

export function normalizeGroup(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return normalized !== '' ? normalized : 'other';
}

export function humanizeGroup(value) {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function getGroupLabel(t, group) {
    const key = normalizeGroup(group);
    const map = {
        ticket: 'Ticket',
        savedsearch: 'Search',
        project: 'Project',
        reminder: 'Reminder',
        sla: 'SLA',
        crontask: 'Scheduled task',
        other: 'Other',
    };
    if (map[key]) {
        return t(map[key]);
    }
    return humanizeGroup(key);
}

export function badgeLabel(value) {
    return Number(value) > 99 ? '99+' : String(value);
}

export function getDisplayTitle(t, item) {
    const explicitTitle = String(item.display_title || '').trim();
    if (explicitTitle !== '') {
        return explicitTitle;
    }

    const rawTitle = String(item.alert_name || '').trim();
    const group = normalizeGroup(item.source_group);
    const ticketId = Number(item.source_items_id || 0);

    if (group !== 'ticket' || ticketId <= 0) {
        return rawTitle;
    }

    const cleaned = rawTitle
        .replace(/^\[[^\]]*#0*\d+\]\s*/i, '')
        .replace(/^ticket\s*#\d+\s*[-:]*\s*/i, '')
        .replace(/\s*\(\d+\)\s*$/, '')
        .trim();
    const title = cleaned !== '' ? cleaned : t('Ticket');
    return `${title} (${ticketId})`;
}

export function getItemSourceLabel(t, item) {
    const sourceGroup = normalizeGroup(item && item.source_group);
    return sourceGroup === 'savedsearch'
        ? t('Search')
        : String((item && item.source_label) || getGroupLabel(t, sourceGroup) || t('Other'));
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
