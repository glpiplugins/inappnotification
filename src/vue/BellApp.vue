<template>
    <div class="inappnotification-bell-wrap">
        <a
            href="#"
            class="nav-link px-2 text-reset inappnotification-bell-toggle"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-label="Notifications"
            @click="onUnlock"
        >
            <i class="ti ti-bell"></i>
            <span v-if="unreadTotal > 0" class="badge bg-danger inappnotification-badge">{{ badgeLabel(unreadTotal) }}</span>
        </a>

        <div
            class="dropdown-menu dropdown-menu-end mt-1 dropdown-menu-arrow inappnotification-dropdown animate__animated animate__fadeInRight"
            @click="onDropdownClick"
        >
            <div class="inappnotification-dropdown-header">
                <div class="inappnotification-header-title">
                    <i class="ti ti-bell-ringing text-primary"></i>
                    <div>
                        <div class="inappnotification-header-main">{{ t('Notification Center') }}</div>
                        <div class="inappnotification-header-sub text-muted">{{ headerSub }}</div>
                    </div>
                </div>
                <div class="inappnotification-header-actions">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary inappnotification-sound-toggle"
                        :title="soundToggleTitle"
                        :aria-pressed="soundMuted ? 'true' : 'false'"
                        @click.prevent="onToggleSound"
                    >
                        <i class="ti me-1" :class="soundToggleIcon"></i>{{ soundToggleLabel }}
                    </button>
                    <button
                        v-if="unreadTotal > 0"
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        @click.prevent="onMarkAllRead"
                    >
                        {{ t('Mark all as read') }}
                    </button>
                </div>
            </div>

            <div class="inappnotification-tabs" role="tablist" :aria-label="t('Notification types')">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="inappnotification-tab"
                    :class="{ 'is-active': tab.key === activeTab }"
                    :title="tab.label"
                    @click.prevent="setTab(tab.key)"
                >
                    <span class="inappnotification-tab-label">{{ tab.label }}</span>
                    <span class="inappnotification-tab-chip">{{ badgeLabel(tab.count) }}</span>
                </button>
            </div>

            <div v-if="filteredItems.length > 0" class="inappnotification-bulkbar">
                <label class="inappnotification-select-all">
                    <span class="inappnotification-item-select-wrap inappnotification-select-all-wrap">
                        <input
                            ref="selectAllRef"
                            type="checkbox"
                            class="form-check-input"
                            :aria-label="t('Select all')"
                            :checked="allVisibleSelected"
                            @change="onSelectAll"
                            @click.stop
                        >
                    </span>
                    <span>{{ t('Select all') }}</span>
                </label>
                <span class="inappnotification-bulk-actions">
                    <span
                        ref="bulkWrapRef"
                        class="inappnotification-item-menu-trigger inappnotification-bulk-menu-trigger"
                        :class="{ 'is-disabled': selectedVisibleCount === 0 }"
                        role="button"
                        tabindex="0"
                        :aria-expanded="bulkMenuOpen ? 'true' : 'false'"
                        :aria-disabled="selectedVisibleCount > 0 ? 'false' : 'true'"
                        :title="t('Actions')"
                        @click.stop.prevent="onToggleBulkMenu"
                        @keydown="onKeyActivate($event, onToggleBulkMenu)"
                    >
                        <i class="ti ti-dots-vertical"></i>
                        <span>{{ t('Actions') }}</span>
                    </span>
                    <span
                        ref="bulkMenuRef"
                        class="inappnotification-item-menu inappnotification-bulk-menu"
                        :class="{ 'is-open': bulkMenuOpen, 'is-open-up': bulkMenuUp }"
                        :style="bulkMenuStyle"
                        @click.stop
                    >
                        <span
                            class="inappnotification-item-menu-action"
                            :class="{ 'is-disabled': selectedVisibleCount === 0 }"
                            role="button"
                            :tabindex="selectedVisibleCount > 0 ? '0' : '-1'"
                            :aria-disabled="selectedVisibleCount > 0 ? 'false' : 'true'"
                            @click.stop.prevent="onBulkAction('mark-read')"
                        >
                            <i class="ti ti-check"></i>{{ t('Mark as read') }}
                        </span>
                        <span
                            class="inappnotification-item-menu-action"
                            :class="{ 'is-disabled': selectedVisibleCount === 0 }"
                            role="button"
                            :tabindex="selectedVisibleCount > 0 ? '0' : '-1'"
                            :aria-disabled="selectedVisibleCount > 0 ? 'false' : 'true'"
                            @click.stop.prevent="onBulkAction('snooze')"
                        >
                            <i class="ti ti-clock"></i>{{ t('Snooze 1 day') }}
                        </span>
                        <span
                            class="inappnotification-item-menu-action is-warning"
                            :class="{ 'is-disabled': selectedVisibleCount === 0 }"
                            role="button"
                            :tabindex="selectedVisibleCount > 0 ? '0' : '-1'"
                            :aria-disabled="selectedVisibleCount > 0 ? 'false' : 'true'"
                            @click.stop.prevent="onBulkAction('suppress')"
                        >
                            <i class="ti ti-bell-off"></i>{{ t('Suppress') }}
                        </span>
                    </span>
                </span>
            </div>

            <div v-if="loading" class="inappnotification-state text-muted small">{{ t('Loading...') }}</div>
            <div v-else-if="items.length === 0" class="inappnotification-state">
                <i class="ti ti-bell-off text-muted"></i>
                <div class="text-muted small">{{ t('No notifications yet') }}</div>
            </div>
            <div v-else-if="filteredItems.length === 0" class="inappnotification-state">
                <i class="ti ti-filter text-muted"></i>
                <div class="text-muted small">{{ t('No notifications in this category') }}</div>
            </div>
            <div v-else class="inappnotification-list">
                <template v-if="unreadItems.length > 0">
                    <div class="inappnotification-section-label is-unread">{{ t('Unread') }} ({{ unreadItems.length }})</div>
                    <NotificationItem
                        v-for="entry in unreadItems"
                        :key="entry.id"
                        :item="entry"
                        :t="t"
                        :selected="isSelected(entry.id)"
                        :menu-open="openMenuId === Number(entry.id)"
                        @toggle-select="onToggleSelect"
                        @request-menu="onRequestMenu"
                        @action="onItemAction"
                        @open="onItemOpen"
                    />
                </template>
                <template v-if="readItems.length > 0">
                    <div class="inappnotification-section-label is-read">{{ t('Recently read') }}</div>
                    <NotificationItem
                        v-for="entry in readItems"
                        :key="entry.id"
                        :item="entry"
                        :t="t"
                        :selected="isSelected(entry.id)"
                        :menu-open="openMenuId === Number(entry.id)"
                        @toggle-select="onToggleSelect"
                        @request-menu="onRequestMenu"
                        @action="onItemAction"
                        @open="onItemOpen"
                    />
                </template>
            </div>

            <div class="dropdown-divider my-1"></div>
            <div class="px-2 pb-2">
                <div class="inappnotification-footer-actions">
                    <a
                        :href="config.centerUrl"
                        class="btn btn-sm btn-outline-secondary inappnotification-footer-btn inappnotification-open-list"
                    >
                        <i class="ti ti-list-details me-1"></i>{{ t('Open list') }}
                    </a>
                    <a
                        :href="config.mutedUrl"
                        class="btn btn-sm btn-outline-secondary inappnotification-footer-btn inappnotification-open-muted"
                    >
                        <i class="ti ti-bell-off me-1"></i>{{ t('Muted items') }}
                        <span
                            v-if="mutedTotal > 0"
                            class="badge bg-secondary text-secondary-fg ms-1 inappnotification-footer-badge"
                        >{{ badgeLabel(mutedTotal) }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import NotificationItem from './NotificationItem.vue';
import { useNotifications } from './composables/useNotifications.js';
import { useSound } from './composables/useSound.js';
import { useMenuPosition } from './composables/useMenuPosition.js';
import {
    SOURCE_GROUP_ORDER,
    normalizeGroup,
    getGroupLabel,
    getDisplayTitle,
    getItemSourceLabel,
    badgeLabel,
    computeNewUnreadChanges,
    sanitizeToastUrl,
    escapeHtml,
} from './helpers.js';

const props = defineProps({
    config: { type: Object, required: true },
    labels: { type: Object, required: true },
});

const t = (key) => (Object.prototype.hasOwnProperty.call(props.labels, key) ? props.labels[key] : key);

const { list, action } = useNotifications(props.config);
const {
    soundMuted,
    setSoundMuted,
    playNotificationSound,
    unlockAudioContext,
    bindAudioOwnership,
    bindUnlockHandlers,
    dispose: disposeSound,
} = useSound();

// --- reactive state ---
const items = ref([]);
const unreadTotal = ref(0);
const mutedTotal = ref(0);
const loading = ref(false);
const activeTab = ref('all');
const hasFetchedOnce = ref(false);
const selectedIds = ref(new Set());
const openMenuId = ref(0);
const bulkMenuOpen = ref(false);

const selectAllRef = ref(null);
const bulkWrapRef = ref(null);
const bulkMenuRef = ref(null);
const { menuUp: bulkMenuUp, menuStyle: bulkMenuStyle, position: positionBulk, reset: resetBulk } = useMenuPosition();

let pollTimer = 0;

// --- computed ---
const filteredItems = computed(() => (
    activeTab.value === 'all'
        ? items.value
        : items.value.filter((item) => normalizeGroup(item.source_group) === activeTab.value)
));

const unreadItems = computed(() => filteredItems.value.filter((item) => Number(item.unread_count || 0) > 0));
const readItems = computed(() => filteredItems.value.filter((item) => Number(item.unread_count || 0) <= 0));

const tabs = computed(() => {
    const presentGroups = {};
    const unreadCounts = {};
    items.value.forEach((item) => {
        const group = normalizeGroup(item.source_group);
        presentGroups[group] = true;
        unreadCounts[group] = Number(unreadCounts[group] || 0) + Math.max(0, Number(item.unread_count || 0));
    });

    const list_ = [{ key: 'all', label: t('All'), count: Math.max(0, Number(unreadTotal.value || 0)) }];
    SOURCE_GROUP_ORDER.forEach((group) => {
        if (presentGroups[group]) {
            list_.push({ key: group, label: getGroupLabel(t, group), count: Number(unreadCounts[group] || 0) });
        }
    });
    Object.keys(presentGroups)
        .filter((group) => !SOURCE_GROUP_ORDER.includes(group))
        .sort()
        .forEach((group) => {
            list_.push({ key: group, label: getGroupLabel(t, group), count: Number(unreadCounts[group] || 0) });
        });
    return list_;
});

const selectedVisibleIds = computed(() => filteredItems.value
    .map((item) => Number(item && item.id ? item.id : 0))
    .filter((id) => id > 0 && selectedIds.value.has(id)));
const selectedVisibleCount = computed(() => selectedVisibleIds.value.length);
const allVisibleSelected = computed(() => filteredItems.value.length > 0
    && selectedVisibleCount.value === filteredItems.value.length);

const headerSub = computed(() => (unreadTotal.value > 0
    ? `${unreadTotal.value} ${t('unread')}`
    : t('All caught up')));
const soundToggleLabel = computed(() => (soundMuted.value ? t('Sound off') : t('Sound on')));
const soundToggleTitle = computed(() => (soundMuted.value ? t('Enable notification sound') : t('Mute notification sound')));
const soundToggleIcon = computed(() => (soundMuted.value ? 'ti-volume-off' : 'ti-volume'));

// Keep the "select all" tri-state in sync (cannot be bound declaratively).
watch([selectedVisibleCount, allVisibleSelected], () => {
    if (selectAllRef.value) {
        selectAllRef.value.indeterminate = selectedVisibleCount.value > 0 && !allVisibleSelected.value;
    }
});

watch(bulkMenuOpen, (open) => {
    if (open) {
        positionBulk(bulkMenuRef.value, bulkWrapRef.value);
    } else {
        resetBulk();
    }
});

// Activate tab falls back to 'all' when its group disappears.
watch(tabs, (next) => {
    if (!next.some((tab) => tab.key === activeTab.value)) {
        activeTab.value = 'all';
    }
});

// --- helpers ---
function isSelected(id) {
    return selectedIds.value.has(Number(id));
}

function syncSelectedIds() {
    const validIds = new Set(items.value.map((item) => Number(item && item.id ? item.id : 0)).filter((id) => id > 0));
    selectedIds.value.forEach((id) => {
        if (!validIds.has(id)) {
            selectedIds.value.delete(id);
        }
    });
}

function applyData(data) {
    if (!data) {
        return;
    }
    if (Array.isArray(data.items)) {
        items.value = data.items;
    }
    unreadTotal.value = Number(data.unread_total || 0);
    mutedTotal.value = Number(data.muted_total != null ? data.muted_total : mutedTotal.value);
    syncSelectedIds();
}

function closeMenus() {
    openMenuId.value = 0;
    bulkMenuOpen.value = false;
}

// --- data flow ---
async function fetchList() {
    const previousItems = Array.isArray(items.value) ? items.value : [];
    loading.value = true;
    try {
        const data = await list(15);
        if (data) {
            const incoming = Array.isArray(data.items) ? data.items : [];
            const unreadChanges = hasFetchedOnce.value
                ? computeNewUnreadChanges(previousItems, incoming)
                : { delta: 0, items: [] };

            items.value = incoming;
            unreadTotal.value = Number(data.unread_total || 0);
            mutedTotal.value = Number(data.muted_total || 0);
            syncSelectedIds();

            if (hasFetchedOnce.value) {
                showNewNotificationsToast(unreadChanges.items, unreadChanges.delta);
                if (unreadChanges.delta > 0) {
                    playNotificationSound();
                }
            } else {
                hasFetchedOnce.value = true;
            }
        }
    } finally {
        loading.value = false;
    }
}

async function doAction(name, body = {}) {
    const data = await action(name, body);
    if (data) {
        applyData(data);
        return true;
    }
    return false;
}

// --- UI handlers ---
function onUnlock() {
    unlockAudioContext();
}

function onDropdownClick(event) {
    unlockAudioContext();
    if (event.target.closest('.inappnotification-item-menu, .inappnotification-item-menu-trigger, .inappnotification-bulk-menu, .inappnotification-bulk-menu-trigger')) {
        return;
    }
    closeMenus();
}

function setTab(key) {
    activeTab.value = key && key !== '' ? key : 'all';
}

function onToggleSound() {
    setSoundMuted(!soundMuted.value);
}

async function onMarkAllRead() {
    await doAction('mark_all_read');
    selectedIds.value.clear();
}

function onToggleSelect(id, checked) {
    const numericId = Number(id);
    if (numericId <= 0) {
        return;
    }
    if (checked) {
        selectedIds.value.add(numericId);
    } else {
        selectedIds.value.delete(numericId);
    }
}

function onSelectAll(event) {
    const shouldSelect = Boolean(event.target.checked);
    filteredItems.value.forEach((item) => {
        const id = Number(item && item.id ? item.id : 0);
        if (id <= 0) {
            return;
        }
        if (shouldSelect) {
            selectedIds.value.add(id);
        } else {
            selectedIds.value.delete(id);
        }
    });
}

function onRequestMenu(id) {
    bulkMenuOpen.value = false;
    openMenuId.value = Number(id) || 0;
}

function onToggleBulkMenu() {
    if (selectedVisibleCount.value === 0) {
        return;
    }
    openMenuId.value = 0;
    bulkMenuOpen.value = !bulkMenuOpen.value;
}

async function onBulkAction(actionName) {
    const ids = selectedVisibleIds.value.slice();
    if (ids.length === 0) {
        return;
    }
    if (actionName === 'mark-read') {
        await doAction('mark_read', { ids });
    } else if (actionName === 'snooze') {
        await doAction('snooze', { ids, minutes: 1440 });
    } else if (actionName === 'suppress') {
        await doAction('suppress', { ids });
    }
    ids.forEach((id) => selectedIds.value.delete(id));
    closeMenus();
}

async function onItemAction({ name, id, minutes }) {
    closeMenus();
    const numericId = Number(id) || 0;
    if (numericId <= 0) {
        return;
    }
    if (name === 'mark-read') {
        await doAction('mark_read', { id: numericId });
        selectedIds.value.delete(numericId);
    } else if (name === 'mark-unread') {
        await doAction('mark_unread', { id: numericId });
    } else if (name === 'snooze') {
        await doAction('snooze', { id: numericId, minutes: Number(minutes) || 1440 });
        selectedIds.value.delete(numericId);
    } else if (name === 'suppress') {
        await doAction('suppress', { id: numericId });
        selectedIds.value.delete(numericId);
    }
}

async function onItemOpen(item, event) {
    const id = Number(item.id || 0);
    if (Number(item.unread_count || 0) > 0) {
        event.preventDefault();
        await doAction('mark_read', { id });
        window.location.href = item.target_url || props.config.centerUrl;
    }
}

function onKeyActivate(event, fn) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        fn();
    }
}

function showNewNotificationsToast(changes, newCount) {
    const count = Number(newCount || 0);
    if (count <= 0) {
        return;
    }
    const toastInfoFn = (typeof window.glpi_toast_info === 'function') ? window.glpi_toast_info : null;
    if (typeof toastInfoFn !== 'function') {
        return;
    }

    const changesList = Array.isArray(changes) ? changes : [];
    const visible = changesList.slice(0, 3);
    const entries = visible.map((entry) => {
        const item = entry && entry.item ? entry.item : {};
        const added = Math.max(1, Number(entry && entry.added ? entry.added : 1));
        const sourceLabel = getItemSourceLabel(t, item);
        const title = String(getDisplayTitle(t, item) || t('Notification')).trim();
        const addedLabel = added > 1 ? ` (+${added})` : '';
        const text = `${sourceLabel}: ${title}${addedLabel}`;
        const safeUrl = sanitizeToastUrl(item.target_url);
        return safeUrl !== ''
            ? `<div><a href="${escapeHtml(safeUrl)}">${escapeHtml(text)}</a></div>`
            : `<div>${escapeHtml(text)}</div>`;
    });

    if (changesList.length > visible.length) {
        entries.push(`<div class="text-muted">+${changesList.length - visible.length} ${escapeHtml(t('more notifications'))}</div>`);
    }
    entries.push(`<div><a href="${escapeHtml(props.config.centerUrl)}">${escapeHtml(t('Open notification list'))}</a></div>`);

    toastInfoFn(entries.join(''), t('Notification Center'), { delay: 7000 });
}

// --- lifecycle ---
onMounted(() => {
    bindUnlockHandlers();
    bindAudioOwnership();
    fetchList();
    pollTimer = window.setInterval(fetchList, Number(props.config.pollMs) || 10000);
});

onUnmounted(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = 0;
    }
    disposeSound();
});
</script>
