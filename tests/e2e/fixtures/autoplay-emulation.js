// Emulates the browsers' autoplay policy for Web Audio: a context only runs after a user gesture
// in this tab; like Chrome, the permission survives same-tab, same-origin navigations.
(() => {
    const Real = window.AudioContext;
    if (!Real) return;
    let activated = sessionStorage.getItem('__audio_activated') === '1';
    const activate = () => { activated = true; sessionStorage.setItem('__audio_activated', '1'); };
    document.addEventListener('pointerdown', activate, true);
    document.addEventListener('keydown', activate, true);
    class EmulatedAudioContext extends Real {
        constructor(...args) { super(...args); this.__blocked = !activated; }
        get state() { return this.__blocked ? 'suspended' : super.state; }
        resume() {
            if (!activated) return Promise.reject(new DOMException('blocked by autoplay policy', 'NotAllowedError'));
            this.__blocked = false;
            return super.resume();
        }
    }
    window.AudioContext = EmulatedAudioContext;
})();
