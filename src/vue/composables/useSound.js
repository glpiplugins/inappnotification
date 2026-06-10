import { ref } from 'vue';

// Cross-tab notification sound: only one tab (the "audio owner") plays the chime,
// elected via localStorage + heartbeat. Mute state is shared across tabs.
// Ported 1:1 from the legacy vanilla implementation.
const SOUND_MUTE_STORAGE_KEY = 'inappnotification_sound_muted';
const AUDIO_OWNER_STORAGE_KEY = 'inappnotification_audio_owner';
const AUDIO_OWNER_HEARTBEAT_MS = 3000;
const AUDIO_OWNER_STALE_MS = 10000;

function readStoredSoundMuted() {
    try {
        return window.localStorage.getItem(SOUND_MUTE_STORAGE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function writeStoredSoundMuted(muted) {
    try {
        window.localStorage.setItem(SOUND_MUTE_STORAGE_KEY, muted ? '1' : '0');
    } catch (e) {
        // Ignore storage errors.
    }
}

function hasUsableLocalStorage() {
    try {
        const probeKey = '__np_storage_probe__';
        window.localStorage.setItem(probeKey, '1');
        window.localStorage.removeItem(probeKey);
        return true;
    } catch (e) {
        return false;
    }
}

function buildTabId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return `np-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

export function useSound() {
    const soundMuted = ref(readStoredSoundMuted());
    const STORAGE_AVAILABLE = hasUsableLocalStorage();

    const audioState = {
        context: null,
        unlockBound: false,
        ownershipBound: false,
        heartbeatTimer: 0,
        isOwner: false,
        tabId: buildTabId(),
    };

    const readAudioOwnerRecord = function () {
        if (!STORAGE_AVAILABLE) {
            return null;
        }
        try {
            const raw = window.localStorage.getItem(AUDIO_OWNER_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            const tabId = String(parsed.tabId || '').trim();
            const ts = Number(parsed.ts || 0);
            const priority = Number(parsed.priority || 0);
            if (tabId === '' || !Number.isFinite(ts)) {
                return null;
            }
            return { tabId, ts, priority: Number.isFinite(priority) ? priority : 0 };
        } catch (e) {
            return null;
        }
    };

    const writeAudioOwnerRecord = function (record) {
        if (!STORAGE_AVAILABLE) {
            return;
        }
        try {
            window.localStorage.setItem(AUDIO_OWNER_STORAGE_KEY, JSON.stringify(record));
        } catch (e) {
            // Ignore storage errors.
        }
    };

    const removeAudioOwnerRecord = function () {
        if (!STORAGE_AVAILABLE) {
            return;
        }
        try {
            window.localStorage.removeItem(AUDIO_OWNER_STORAGE_KEY);
        } catch (e) {
            // Ignore storage errors.
        }
    };

    const getAudioOwnerPriority = function () {
        if (document.visibilityState !== 'visible') {
            return 0;
        }
        if (typeof document.hasFocus === 'function' && document.hasFocus()) {
            return 2;
        }
        return 1;
    };

    const isAudioOwnerRecordStale = function (record, now = Date.now()) {
        if (!record || !Number.isFinite(Number(record.ts))) {
            return true;
        }
        return (now - Number(record.ts)) > AUDIO_OWNER_STALE_MS;
    };

    const updateOwnAudioOwnerRecord = function (now = Date.now()) {
        writeAudioOwnerRecord({ tabId: audioState.tabId, ts: now, priority: getAudioOwnerPriority() });
    };

    const releaseAudioOwnership = function () {
        if (!STORAGE_AVAILABLE) {
            audioState.isOwner = false;
            return;
        }
        const current = readAudioOwnerRecord();
        if (current && current.tabId === audioState.tabId) {
            removeAudioOwnerRecord();
        }
        audioState.isOwner = false;
    };

    const claimAudioOwnership = function (force = false) {
        if (soundMuted.value) {
            audioState.isOwner = false;
            return false;
        }
        if (!STORAGE_AVAILABLE) {
            audioState.isOwner = true;
            return true;
        }

        const now = Date.now();
        const current = readAudioOwnerRecord();
        const currentId = current ? String(current.tabId || '') : '';
        const currentPriority = current ? Number(current.priority || 0) : -1;
        const stale = isAudioOwnerRecordStale(current, now);
        const myPriority = getAudioOwnerPriority();

        let shouldTake = false;
        if (!current || stale || currentId === audioState.tabId) {
            shouldTake = true;
        } else if (force && myPriority > 0) {
            shouldTake = true;
        } else if (myPriority > currentPriority && myPriority > 0) {
            shouldTake = true;
        }

        if (shouldTake) {
            updateOwnAudioOwnerRecord(now);
        }

        const latest = readAudioOwnerRecord();
        audioState.isOwner = Boolean(
            latest && latest.tabId === audioState.tabId && !isAudioOwnerRecordStale(latest, now)
        );
        return audioState.isOwner;
    };

    const refreshAudioOwnership = function (force = false) {
        if (soundMuted.value) {
            releaseAudioOwnership();
            return false;
        }
        if (audioState.isOwner) {
            updateOwnAudioOwnerRecord();
            return claimAudioOwnership(force);
        }
        return claimAudioOwnership(force);
    };

    const shouldPlaySoundInThisTab = function () {
        if (soundMuted.value) {
            return false;
        }
        const isFocusedTab = (
            document.visibilityState === 'visible'
            && (typeof document.hasFocus !== 'function' || document.hasFocus())
        );
        return refreshAudioOwnership(isFocusedTab);
    };

    const getAudioContext = function () {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return null;
        }
        if (!audioState.context || audioState.context.state === 'closed') {
            try {
                audioState.context = new AudioContextClass();
            } catch (e) {
                return null;
            }
        }
        return audioState.context;
    };

    const unlockAudioContext = async function () {
        if (soundMuted.value) {
            return false;
        }
        const context = getAudioContext();
        if (!context) {
            return false;
        }
        if (context.state === 'suspended') {
            try {
                await context.resume();
            } catch (e) {
                return false;
            }
        }
        return context.state === 'running' || context.state === 'interrupted';
    };

    const playNotificationSound = async function () {
        if (!shouldPlaySoundInThisTab()) {
            return;
        }
        const context = getAudioContext();
        if (!context) {
            return;
        }
        try {
            if (context.state === 'suspended') {
                await context.resume();
            }
            if (context.state !== 'running' && context.state !== 'interrupted') {
                return;
            }

            const now = context.currentTime;
            const mainOscillator = context.createOscillator();
            const mainGain = context.createGain();
            const accentOscillator = context.createOscillator();
            const accentGain = context.createGain();

            // Brighter, concise chime without harsh high-pitch beeping.
            mainOscillator.type = 'triangle';
            mainOscillator.frequency.setValueAtTime(987.77, now);
            mainOscillator.frequency.exponentialRampToValueAtTime(830.61, now + 0.18);
            mainGain.gain.setValueAtTime(0.0001, now);
            mainGain.gain.exponentialRampToValueAtTime(0.05, now + 0.02);
            mainGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.24);

            accentOscillator.type = 'sine';
            accentOscillator.frequency.setValueAtTime(1318.51, now);
            accentOscillator.frequency.exponentialRampToValueAtTime(1046.5, now + 0.1);
            accentGain.gain.setValueAtTime(0.0001, now);
            accentGain.gain.exponentialRampToValueAtTime(0.015, now + 0.01);
            accentGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.12);

            mainOscillator.connect(mainGain);
            accentOscillator.connect(accentGain);
            mainGain.connect(context.destination);
            accentGain.connect(context.destination);

            mainOscillator.start(now);
            accentOscillator.start(now);
            mainOscillator.stop(now + 0.26);
            accentOscillator.stop(now + 0.14);
        } catch (e) {
            // Ignore audio playback errors (autoplay restrictions, etc.).
        }
    };

    const setSoundMuted = function (muted) {
        soundMuted.value = Boolean(muted);
        writeStoredSoundMuted(soundMuted.value);
        if (soundMuted.value) {
            releaseAudioOwnership();
        } else {
            refreshAudioOwnership(true);
            unlockAudioContext();
        }
    };

    // Named handler references so they can be removed again in dispose().
    let onVisibilityChange = null;
    let onWindowFocus = null;
    let onBeforeUnload = null;
    let onStorage = null;
    let onUnlock = null;

    const bindAudioOwnership = function () {
        if (audioState.ownershipBound) {
            return;
        }
        audioState.ownershipBound = true;

        refreshAudioOwnership(true);

        audioState.heartbeatTimer = window.setInterval(() => {
            refreshAudioOwnership(false);
        }, AUDIO_OWNER_HEARTBEAT_MS);

        onVisibilityChange = () => {
            refreshAudioOwnership(document.visibilityState === 'visible');
        };
        onWindowFocus = () => {
            refreshAudioOwnership(true);
        };
        onBeforeUnload = () => {
            releaseAudioOwnership();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', onWindowFocus);
        window.addEventListener('beforeunload', onBeforeUnload);

        if (STORAGE_AVAILABLE) {
            onStorage = (event) => {
                if (event.key === SOUND_MUTE_STORAGE_KEY) {
                    // Reactive ref update re-renders the toggle automatically.
                    soundMuted.value = readStoredSoundMuted();
                    if (soundMuted.value) {
                        releaseAudioOwnership();
                    } else {
                        refreshAudioOwnership(true);
                        unlockAudioContext();
                    }
                    return;
                }
                if (event.key === AUDIO_OWNER_STORAGE_KEY && !soundMuted.value) {
                    refreshAudioOwnership(false);
                }
            };
            window.addEventListener('storage', onStorage);
        }
    };

    const bindUnlockHandlers = function () {
        if (audioState.unlockBound) {
            return;
        }
        audioState.unlockBound = true;
        onUnlock = () => {
            unlockAudioContext();
        };
        document.addEventListener('pointerdown', onUnlock, { passive: true });
        document.addEventListener('keydown', onUnlock);
    };

    // Remove every global listener / timer this composable installed and release ownership.
    // Called from the component's onUnmounted so nothing leaks if the bell is ever torn down.
    const dispose = function () {
        if (audioState.heartbeatTimer) {
            window.clearInterval(audioState.heartbeatTimer);
            audioState.heartbeatTimer = 0;
        }
        if (onVisibilityChange) {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            onVisibilityChange = null;
        }
        if (onWindowFocus) {
            window.removeEventListener('focus', onWindowFocus);
            onWindowFocus = null;
        }
        if (onBeforeUnload) {
            window.removeEventListener('beforeunload', onBeforeUnload);
            onBeforeUnload = null;
        }
        if (onStorage) {
            window.removeEventListener('storage', onStorage);
            onStorage = null;
        }
        if (onUnlock) {
            document.removeEventListener('pointerdown', onUnlock);
            document.removeEventListener('keydown', onUnlock);
            onUnlock = null;
        }
        releaseAudioOwnership();
        audioState.ownershipBound = false;
        audioState.unlockBound = false;
    };

    return {
        soundMuted,
        setSoundMuted,
        playNotificationSound,
        unlockAudioContext,
        bindAudioOwnership,
        bindUnlockHandlers,
        dispose,
    };
}
