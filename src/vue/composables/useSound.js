import { ref } from 'vue';

// Notification sound of the bell: mute state (shared by all tabs via localStorage), unlocking the
// Web Audio context on a user gesture (browsers block audio before that) and the chime itself.
// Which tab plays the chime is decided by useTabCoordinator.
const SOUND_MUTE_STORAGE_KEY = 'inappnotification_sound_muted';
const PENDING_CHIME_MS = 2 * 60 * 1000;

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

export function useSound() {
    const soundMuted = ref(readStoredSoundMuted());

    const audioState = {
        context: null,
        unlockBound: false,
    };
    // A chime the browser blocked (no user gesture on this page yet): shown as a hint, and played
    // on the next click or key press if that comes within PENDING_CHIME_MS.
    const audioBlocked = ref(false);
    let pendingChimeAt = 0;

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

    const isAudioRunning = function (context) {
        return context.state === 'running' || context.state === 'interrupted';
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
        return isAudioRunning(context);
    };

    // Play the chime in this tab. Returns false if the browser blocks audio here.
    // `force`: play even while muted (sound test).
    const playNotificationSound = async function (force = false) {
        if (soundMuted.value && !force) {
            return true;
        }
        const context = getAudioContext();
        if (!context) {
            return false;
        }
        if (context.state === 'suspended') {
            try {
                await context.resume();
            } catch (e) {
                // Blocked by the autoplay policy, handled below.
            }
        }
        if (!isAudioRunning(context)) {
            audioBlocked.value = true;
            pendingChimeAt = Date.now();
            return false;
        }
        try {

            // Two-note chime, C5 then G5: mid pitch, brightened by overtones that fade faster than
            // the fundamental (bell-like rather than a beep). Peaks at about 0.7 of full scale.
            const now = context.currentTime;
            const partials = [[1, 1, 'triangle'], [2, 0.45, 'sine'], [3, 0.2, 'sine'], [4, 0.1, 'sine']];
            const tone = (frequency, start, duration, peak) => {
                partials.forEach(([multiple, share, type], index) => {
                    const length = duration / (1 + index * 0.6);
                    const oscillator = context.createOscillator();
                    const gain = context.createGain();
                    oscillator.type = type;
                    oscillator.frequency.setValueAtTime(frequency * multiple, start);
                    gain.gain.setValueAtTime(0.0001, start);
                    gain.gain.exponentialRampToValueAtTime(peak * share, start + 0.012);
                    gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
                    oscillator.connect(gain);
                    gain.connect(context.destination);
                    oscillator.start(start);
                    oscillator.stop(start + length + 0.02);
                });
            };
            tone(523.25, now, 0.7, 0.42);
            tone(783.99, now + 0.2, 0.85, 0.38);
        } catch (e) {
            // Ignore audio playback errors (autoplay restrictions, etc.).
            return false;
        }
        audioBlocked.value = false;
        pendingChimeAt = 0;
        // Hook for tests and integrations: the chime was played in this tab.
        window.dispatchEvent(new CustomEvent('inappnotification:chime'));
        return true;
    };

    // Whether the chime can play in this tab right now.
    const isAudioReady = function () {
        return !soundMuted.value && audioState.context !== null && isAudioRunning(audioState.context);
    };

    let onUnlocked = null;

    const setSoundMuted = function (muted) {
        soundMuted.value = Boolean(muted);
        writeStoredSoundMuted(soundMuted.value);
        if (!soundMuted.value) {
            unlockAndReport();
        }
    };

    const unlockAndReport = async function () {
        if (!(await unlockAudioContext())) {
            return;
        }
        audioBlocked.value = false;
        if (pendingChimeAt && Date.now() - pendingChimeAt < PENDING_CHIME_MS) {
            pendingChimeAt = 0;
            playNotificationSound();
        }
        if (onUnlocked) {
            onUnlocked();
        }
    };

    // Named handler references so they can be removed again in dispose().
    let onStorage = null;
    let onGesture = null;

    // Unlock the audio on the first user gestures; `callback` runs once audio is playable here.
    const bindUnlockHandlers = function (callback) {
        if (audioState.unlockBound) {
            return;
        }
        audioState.unlockBound = true;
        onUnlocked = callback || null;
        onGesture = () => {
            unlockAndReport();
        };
        document.addEventListener('pointerdown', onGesture, { passive: true });
        document.addEventListener('keydown', onGesture);

        onStorage = (event) => {
            if (event.key === SOUND_MUTE_STORAGE_KEY) {
                // Mute toggled in another tab; the reactive ref re-renders the toggle.
                soundMuted.value = readStoredSoundMuted();
            }
        };
        window.addEventListener('storage', onStorage);
    };

    // Remove every global listener this composable installed (called from onUnmounted).
    const dispose = function () {
        if (onGesture) {
            document.removeEventListener('pointerdown', onGesture);
            document.removeEventListener('keydown', onGesture);
            onGesture = null;
        }
        if (onStorage) {
            window.removeEventListener('storage', onStorage);
            onStorage = null;
        }
        onUnlocked = null;
        audioState.unlockBound = false;
    };

    return {
        soundMuted,
        audioBlocked,
        setSoundMuted,
        playNotificationSound,
        isAudioReady,
        unlockAudioContext: unlockAndReport,
        bindUnlockHandlers,
        dispose,
    };
}
