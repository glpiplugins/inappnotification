<template>
    <a
        :href="item.target_url || '#'"
        class="dropdown-item inappnotification-item"
        :class="unread ? 'is-unread' : 'is-read'"
        :title="titleTooltip"
        @click="onOpen"
    >
        <div class="inappnotification-item-content">
            <div class="inappnotification-item-line inappnotification-item-line-top">
                <span class="inappnotification-item-select-wrap" :title="t('Select item')" @click.stop>
                    <input
                        type="checkbox"
                        class="form-check-input inappnotification-item-select"
                        :aria-label="t('Select item')"
                        :checked="selected"
                        @change="onSelect"
                        @click.stop
                    >
                </span>
                <div class="inappnotification-item-title" :title="titleTooltip">{{ displayTitle }}</div>
                <span class="inappnotification-item-count badge bg-secondary text-secondary-fg">{{ occurrenceLabel }}</span>
            </div>
            <div class="inappnotification-item-line inappnotification-item-line-bottom">
                <span class="badge bg-azure-lt text-azure-fg inappnotification-item-type-chip">{{ sourceLabel }}</span>
                <span
                    ref="wrapRef"
                    class="inappnotification-item-actions"
                    :class="{ 'is-open': menuOpen, 'is-open-up': menuUp }"
                >
                    <span
                        class="inappnotification-item-menu-trigger"
                        role="button"
                        tabindex="0"
                        :aria-expanded="menuOpen ? 'true' : 'false'"
                        :title="t('Actions')"
                        @click.stop.prevent="onMenuToggle"
                        @keydown="onKeyActivate($event, onMenuToggle)"
                    >
                        <i class="ti ti-dots-vertical"></i>
                        <span>{{ t('Actions') }}</span>
                    </span>
                    <span ref="menuRef" class="inappnotification-item-menu" :style="menuStyle" @click.stop>
                        <span
                            class="inappnotification-item-menu-action"
                            role="button"
                            tabindex="0"
                            @click.stop.prevent="onAction(toggleAction)"
                            @keydown="onKeyActivate($event, () => onAction(toggleAction))"
                        >
                            <i class="ti ti-check"></i>{{ toggleLabel }}
                        </span>
                        <span
                            class="inappnotification-item-menu-action"
                            role="button"
                            tabindex="0"
                            @click.stop.prevent="onAction('snooze', 1440)"
                            @keydown="onKeyActivate($event, () => onAction('snooze', 1440))"
                        >
                            <i class="ti ti-clock"></i>{{ t('Snooze 1 day') }}
                        </span>
                        <span
                            class="inappnotification-item-menu-action is-warning"
                            role="button"
                            tabindex="0"
                            @click.stop.prevent="onAction('suppress')"
                            @keydown="onKeyActivate($event, () => onAction('suppress'))"
                        >
                            <i class="ti ti-bell-off"></i>{{ t('Suppress') }}
                        </span>
                    </span>
                </span>
            </div>
        </div>
    </a>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { normalizeGroup, getGroupLabel, getDisplayTitle } from './helpers.js';
import { useMenuPosition } from './composables/useMenuPosition.js';

const props = defineProps({
    item: { type: Object, required: true },
    t: { type: Function, required: true },
    selected: { type: Boolean, default: false },
    menuOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'request-menu', 'action', 'open']);

const wrapRef = ref(null);
const menuRef = ref(null);
const { menuUp, menuStyle, position, reset } = useMenuPosition();

const itemId = computed(() => Number(props.item.id || 0));
const unread = computed(() => Number(props.item.unread_count || 0) > 0);
const toggleAction = computed(() => (unread.value ? 'mark-read' : 'mark-unread'));
const toggleLabel = computed(() => (unread.value ? props.t('Mark as read') : props.t('Mark as unread')));
const occurrenceLabel = computed(() => {
    const occurrences = Math.max(1, Number(props.item.occurrence_count || 0));
    return occurrences > 99 ? '99+' : String(occurrences);
});
const sourceGroup = computed(() => normalizeGroup(props.item.source_group));
const sourceLabel = computed(() => (
    sourceGroup.value === 'savedsearch'
        ? props.t('Search')
        : String(props.item.source_label || getGroupLabel(props.t, sourceGroup.value) || props.t('Other'))
));
const displayTitle = computed(() => getDisplayTitle(props.t, props.item));
const titleTooltip = computed(() => (displayTitle.value !== '' ? displayTitle.value : sourceLabel.value));

watch(() => props.menuOpen, (open) => {
    if (open) {
        position(menuRef.value, wrapRef.value);
    } else {
        reset();
    }
});

function onSelect(event) {
    emit('toggle-select', itemId.value, Boolean(event.target.checked));
}

function onMenuToggle() {
    emit('request-menu', props.menuOpen ? 0 : itemId.value);
}

function onAction(name, minutes) {
    emit('action', { name, id: itemId.value, minutes });
}

function onOpen(event) {
    if (event.target.closest('.inappnotification-item-actions, .inappnotification-item-select-wrap')) {
        return;
    }
    emit('open', props.item, event);
}

function onKeyActivate(event, fn) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        fn();
    }
}
</script>
