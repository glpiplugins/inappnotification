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

import './inappnotification.css';
import BellApp from './BellApp.vue';

// Bootstrap: GLPI offers no navbar hook, so we inject a single mount node into the
// user menu and mount the Vue app there, reusing GLPI's Vue runtime (window.Vue).
// This bundle is loaded as a footer JS module, after build/vue/app.js, so window.Vue
// / window._vue are available; we still poll defensively for the user menu.
(function () {
    const MOUNT_ID = 'inappnotification-bell-root';

    // Server-rendered config + translated labels, delivered via a <meta> tag
    // (see plugin_inappnotification_header_tags()). Absent when logged out.
    function getBootData() {
        const meta = document.querySelector('meta[name="inappnotification:boot"]');
        return meta ? JSON.parse(meta.getAttribute('content')) : null;
    }

    function findUserMenu() {
        const pageHeader = document.querySelector('header.navbar.d-print-none');
        if (!pageHeader) {
            return null;
        }
        // The header can contain the user menu twice, e.g. in the simplified interface a mobile-only
        // copy (inside .d-lg-none) comes first: use the one visible at the current width.
        const candidates = Array.from(pageHeader.querySelectorAll('.user-menu'));
        return candidates.find((menu) => menu.getClientRects().length > 0)
            || candidates.find((menu) => !menu.closest('.d-lg-none'))
            || candidates[0]
            || null;
    }

    function mountBell() {
        if (document.getElementById(MOUNT_ID)) {
            return true;
        }
        if (!window.Vue || typeof window.Vue.createApp !== 'function') {
            return false; // GLPI Vue runtime not ready yet.
        }
        const userMenu = findUserMenu();
        if (!userMenu) {
            return false;
        }

        const host = document.createElement('div');
        host.className = 'nav-item dropdown inappnotification-bell-item';
        const root = document.createElement('div');
        root.id = MOUNT_ID;
        host.append(root);
        userMenu.prepend(host);

        window.Vue.createApp(BellApp, boot).mount(root);
        return true;
    }

    const boot = getBootData();

    function start() {
        if (!boot) {
            return;
        }
        let tries = 0;
        const timer = window.setInterval(function () {
            tries += 1;
            if (mountBell() || tries >= 60) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
