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
