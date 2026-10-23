import { reactive } from 'vue';
import { computeNewUnreadChanges } from '../helpers.js';

// Coordinates the bell across all tabs of one browser (same GLPI instance and user).
//
// - Poller role: exactly one tab polls the server; every server response that reaches any tab
//   (poll, first load, action result) is shared with the other tabs over a BroadcastChannel.
// - New notifications are detected once: the tab that receives the server data compares it with
//   a baseline shared via localStorage and updates it. Tabs that only get the shared snapshot
//   just render it (and show the toast), so they never detect the same change again.
// - Sound role: exactly one tab plays the chime, the first tab whose audio was unlocked by a user
//   gesture (browsers block audio before that). The detecting tab hands the chime to that tab;
//   if no tab holds the role, it tries itself.
//
// - A visible tab does not depend on a poller in a background tab, whose timers the browser may
//   throttle (Chrome: about once a minute after five hidden minutes) or suspend: when the shared
//   data is stale, the visible tab fetches itself.
// - Responses are ordered by the time their request started: one that started before the data
//   already shown (e.g. a poll overtaken by an action in another tab) is dropped, so it can
//   neither undo the action on screen nor be announced as new.
//
// Roles are held with the Web Locks API: atomic, and released by the browser when a tab closes.
// Pages served over plain HTTP are no secure context and lack Web Locks; there a localStorage
// lease with heartbeat is used, so a role moves on within a few seconds after its tab is gone.
// Without BroadcastChannel (or without both locks and localStorage) every tab polls and detects on
// its own; a short-lived localStorage mark keeps the other tabs from chiming for the same change.

const LEASE_TTL_MS = 6000;
const LEASE_HEARTBEAT_MS = 2000;
const LEASE_CONFIRM_MS = 150;
// A baseline older than this is ignored: whatever arrived while no GLPI tab was open is not
// announced again on the next page load.
const BASELINE_MAX_AGE_MS = 5 * 60 * 1000;
// Two tabs fetching at the same moment can detect the same change; one chime is enough.
const CHIME_MIN_GAP_MS = 2000;
// A visible tab fetches itself when the newest data is older than this many poll intervals.
const STALE_AFTER_POLLS = 1.5;
// Standalone mode: tabs detect the same change up to one poll interval apart.
const STANDALONE_CHIME_DEDUP_MS = 30000;

function buildTabId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return `tab-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

function createStorage() {
    try {
        const probeKey = '__inappnotification_probe__';
        window.localStorage.setItem(probeKey, '1');
        window.localStorage.removeItem(probeKey);
    } catch (e) {
        return null;
    }
    return {
        read(key) {
            try {
                return JSON.parse(window.localStorage.getItem(key) || 'null');
            } catch (e) {
                return null;
            }
        },
        write(key, value) {
            try {
                window.localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                // Quota or privacy mode: coordination degrades, the bell keeps working.
            }
        },
        remove(key) {
            try {
                window.localStorage.removeItem(key);
            } catch (e) {
                // Ignore.
            }
        },
    };
}

// Role held via the Web Locks API. The lock request queues until the role is free.
function createLockRole(name, { onAcquire, onLose }) {
    const controller = new AbortController();
    let held = false;
    let releaseLock = null;

    navigator.locks.request(name, { signal: controller.signal }, () => new Promise((resolve) => {
        held = true;
        releaseLock = resolve;
        onAcquire();
    })).catch(() => {
        // Aborted by release() before the lock was granted.
    });

    return {
        isHeld: () => held,
        release() {
            controller.abort();
            if (releaseLock) {
                releaseLock();
                releaseLock = null;
            }
            if (held) {
                held = false;
                onLose();
            }
        },
    };
}

// Role held via a localStorage lease that the holder renews; used without Web Locks.
function createLeaseRole(key, tabId, storage, { onAcquire, onLose }) {
    let held = false;
    let stopped = false;

    const takenByOther = (record, now) => Boolean(
        record && record.id !== tabId && Number(record.until) > now
    );

    const tick = () => {
        if (stopped) {
            return;
        }
        const now = Date.now();
        const current = storage.read(key);
        if (held) {
            if (takenByOther(current, now)) {
                // Our renewals were too late (e.g. timer throttling) and another tab took over.
                held = false;
                onLose();
                return;
            }
            storage.write(key, { id: tabId, until: now + LEASE_TTL_MS });
            return;
        }
        if (takenByOther(current, now)) {
            return;
        }
        storage.write(key, { id: tabId, until: now + LEASE_TTL_MS });
        // Several tabs may claim at once; the last write wins once storage has settled.
        window.setTimeout(() => {
            const confirmed = storage.read(key);
            if (!stopped && !held && confirmed && confirmed.id === tabId) {
                held = true;
                onAcquire();
            }
        }, LEASE_CONFIRM_MS + Math.floor(Math.random() * LEASE_CONFIRM_MS));
    };

    const timer = window.setInterval(tick, LEASE_HEARTBEAT_MS);
    tick();

    return {
        isHeld: () => held,
        release() {
            stopped = true;
            window.clearInterval(timer);
            const current = storage.read(key);
            if (current && current.id === tabId) {
                // Free the role right away instead of letting the lease expire.
                storage.remove(key);
            }
            if (held) {
                held = false;
                onLose();
            }
        },
    };
}

/**
 * @param {object} options
 * @param {string} options.namespace  Unique per GLPI instance and user.
 * @param {number} options.pollMs
 * @param {() => void} options.onPoll  Fetch the list (poller tab, or a visible tab with stale data).
 * @param {(message: {data: object, changes: object}) => void} options.onSnapshot  Data shared by another tab.
 * @param {() => Promise<boolean>|boolean} options.onChime  Play the chime; false if the audio is blocked.
 * @param {() => boolean} options.canChime  Whether this tab's audio can play right now.
 * @param {() => boolean} options.isMuted
 */
export function useTabCoordinator(options) {
    const { namespace, pollMs, onPoll, onSnapshot, onChime, canChime, isMuted } = options;
    const tabId = buildTabId();
    const storage = createStorage();
    const locksAvailable = Boolean(window.isSecureContext && navigator.locks && typeof navigator.locks.request === 'function');
    const channel = typeof window.BroadcastChannel === 'function' ? new window.BroadcastChannel(namespace) : null;
    const coordinated = Boolean(channel) && (locksAvailable || Boolean(storage));
    // Only coordinated tabs share the baseline: in standalone mode every tab detects on its own.
    const sharedBaseline = coordinated && Boolean(storage);
    const baselineKey = `${namespace}:baseline`;
    const chimeKey = `${namespace}:chime`;

    // Exposed for the template (data attributes) and for debugging.
    const state = reactive({
        mode: coordinated ? (locksAvailable ? 'locks' : 'lease') : 'standalone',
        poller: false,
        sound: false,
    });

    let started = false;
    // Audio was unlocked before start() (e.g. on mount after a navigation): claim in start().
    let soundWanted = false;
    let pollerRole = null;
    let soundRole = null;
    let pollTimer = 0;
    let watchdogTimer = 0;
    let lastDataAt = 0;
    // Request start time of the data shown in this tab (see the ordering note above).
    let shownRequestedAt = 0;
    let lastChimeAt = 0;
    let memoryBaseline = null;

    const isVisible = () => document.visibilityState === 'visible';

    const createRole = (role, handlers) => (locksAvailable
        ? createLockRole(`${namespace}:${role}`, handlers)
        : createLeaseRole(`${namespace}:${role}`, tabId, storage, handlers));

    const startPolling = () => {
        if (pollTimer) {
            return;
        }
        // A new poller (e.g. after the previous poller tab was closed) catches up at once.
        if (Date.now() - lastDataAt >= pollMs) {
            onPoll();
        }
        pollTimer = window.setInterval(onPoll, pollMs);
    };

    const stopPolling = () => {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = 0;
        }
    };

    // A visible non-poller tab fetches itself when the poller's data got stale.
    const watchdog = () => {
        if (!state.poller && isVisible() && Date.now() - lastDataAt > pollMs * STALE_AFTER_POLLS) {
            onPoll();
        }
    };

    const onVisibilityChange = () => {
        if (isVisible()) {
            watchdog();
        }
    };

    const readBaseline = () => (sharedBaseline ? storage.read(baselineKey) : memoryBaseline);

    const writeBaseline = (items, requestedAt) => {
        const unread = {};
        (Array.isArray(items) ? items : []).forEach((item) => {
            const id = Number(item && item.id ? item.id : 0);
            if (id > 0) {
                unread[id] = Math.max(0, Number(item.unread_count || 0));
            }
        });
        const next = { at: Date.now(), requestedAt, unread };
        if (sharedBaseline) {
            storage.write(baselineKey, next);
        } else {
            memoryBaseline = next;
        }
    };

    const playHere = async () => {
        const now = Date.now();
        if (now - lastChimeAt < CHIME_MIN_GAP_MS) {
            return;
        }
        lastChimeAt = now;
        const played = await onChime();
        if (!played && soundRole) {
            // Audio got blocked again (e.g. suspended context): let another unlocked tab take
            // over; this tab claims the role again on its next user gesture.
            releaseSound();
        }
    };

    const onMessage = (event) => {
        const message = event && event.data ? event.data : {};
        if (message.type === 'snapshot' && message.data) {
            const requestedAt = Number(message.requestedAt) || 0;
            if (requestedAt < shownRequestedAt) {
                return;
            }
            shownRequestedAt = requestedAt;
            lastDataAt = Date.now();
            if (!sharedBaseline) {
                writeBaseline(message.data.items, requestedAt);
            }
            onSnapshot({ data: message.data, changes: message.changes || { delta: 0, items: [] } });
        } else if (message.type === 'chime' && soundRole && soundRole.isHeld() && !isMuted()) {
            playHere();
        }
    };

    const onPageHide = () => {
        stop();
    };

    // A page restored from the back/forward cache was stopped on pagehide: take part again.
    const onPageShow = (event) => {
        if (event.persisted && !started) {
            start();
            watchdog();
        }
    };
    window.addEventListener('pageshow', onPageShow);

    function start() {
        if (started) {
            return;
        }
        started = true;
        if (!coordinated) {
            startPolling();
            return;
        }
        channel.addEventListener('message', onMessage);
        window.addEventListener('pagehide', onPageHide);
        document.addEventListener('visibilitychange', onVisibilityChange);
        watchdogTimer = window.setInterval(watchdog, pollMs);
        pollerRole = createRole('poller', {
            onAcquire: () => {
                state.poller = true;
                startPolling();
            },
            onLose: () => {
                state.poller = false;
                stopPolling();
            },
        });
        if (soundWanted) {
            soundWanted = false;
            claimSound();
        }
    }

    function stop() {
        started = false;
        stopPolling();
        if (watchdogTimer) {
            window.clearInterval(watchdogTimer);
            watchdogTimer = 0;
        }
        if (pollerRole) {
            pollerRole.release();
            pollerRole = null;
        }
        releaseSound();
        if (channel) {
            channel.removeEventListener('message', onMessage);
        }
        window.removeEventListener('pagehide', onPageHide);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    // stop() for good (component unmounted): also no restart from the back/forward cache.
    function destroy() {
        stop();
        window.removeEventListener('pageshow', onPageShow);
        if (channel) {
            channel.close();
        }
    }

    // Compete for the sound role; call once this tab's audio is unlocked.
    function claimSound() {
        if (!coordinated || soundRole) {
            return;
        }
        if (!started) {
            soundWanted = true;
            return;
        }
        soundRole = createRole('sound', {
            onAcquire: () => {
                state.sound = true;
            },
            onLose: () => {
                state.sound = false;
            },
        });
    }

    function releaseSound() {
        if (soundRole) {
            const role = soundRole;
            soundRole = null;
            role.release();
        }
        state.sound = false;
    }

    /**
     * Changes in `items` against the baseline (ignoring `ignoreIds`, i.e. what this tab just changed
     * itself), then store `items` as the new baseline. Returns null for a response whose request
     * started before the data already shown: the caller must drop it.
     */
    function detectChanges(items, ignoreIds = [], requestedAt = Date.now()) {
        const baseline = readBaseline();
        if (requestedAt < shownRequestedAt || (baseline && Number(baseline.requestedAt || 0) > requestedAt)) {
            return null;
        }
        let changes = { delta: 0, items: [] };
        if (baseline && baseline.unread && Date.now() - Number(baseline.at || 0) <= BASELINE_MAX_AGE_MS) {
            const ignored = new Set(ignoreIds.map((id) => Number(id)));
            const previous = Object.keys(baseline.unread).map((id) => ({
                id: Number(id),
                unread_count: Number(baseline.unread[id]) || 0,
            }));
            const candidates = (Array.isArray(items) ? items : [])
                .filter((item) => !ignored.has(Number(item && item.id ? item.id : 0)));
            changes = computeNewUnreadChanges(previous, candidates);
        }
        writeBaseline(items, requestedAt);
        shownRequestedAt = requestedAt;
        return changes;
    }

    // Share server data this tab received with the other tabs.
    function share(data, changes, requestedAt) {
        lastDataAt = Date.now();
        if (channel && coordinated) {
            try {
                channel.postMessage({ type: 'snapshot', data, changes, requestedAt });
            } catch (e) {
                // Not cloneable: other tabs simply keep their state until the next poll.
            }
        }
    }

    // Make exactly one tab play the chime for `changes` this tab detected.
    async function chime(changes) {
        if (isMuted()) {
            return;
        }
        if (!coordinated) {
            // A tab with blocked audio must not take the chime away from one that can play it.
            if (canChime() && claimStandaloneChime(changes)) {
                playHere();
            }
            return;
        }
        if (soundRole && soundRole.isHeld()) {
            playHere();
            return;
        }
        if (await isRoleHeldAnywhere('sound')) {
            channel.postMessage({ type: 'chime' });
            return;
        }
        // No tab has unlocked audio yet: try here (the browser may still block it).
        playHere();
    }

    // Standalone mode: the first tab that detects a change marks it; the others skip it.
    function claimStandaloneChime(changes) {
        if (!storage) {
            return true;
        }
        const signature = (changes && Array.isArray(changes.items) ? changes.items : [])
            .map((entry) => `${Number(entry.item && entry.item.id)}:${Number(entry.item && entry.item.unread_count)}`)
            .sort()
            .join(',');
        const now = Date.now();
        const last = storage.read(chimeKey);
        if (last && last.signature === signature && now - Number(last.at || 0) < STANDALONE_CHIME_DEDUP_MS) {
            return false;
        }
        storage.write(chimeKey, { signature, at: now });
        return true;
    }

    // Whether any tab (this one included) currently holds `role`.
    async function isRoleHeldAnywhere(role) {
        if (locksAvailable) {
            try {
                const lockState = await navigator.locks.query();
                return lockState.held.some((lock) => lock.name === `${namespace}:${role}`);
            } catch (e) {
                return false;
            }
        }
        const current = storage.read(`${namespace}:${role}`);
        return Boolean(current && Number(current.until) > Date.now());
    }

    return { state, start, stop, destroy, claimSound, releaseSound, detectChanges, share, chime };
}
