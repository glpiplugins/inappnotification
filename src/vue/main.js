import './inappnotification.css';
import BellApp from './BellApp.vue';

// Bootstrap: GLPI offers no navbar hook, so we inject a single mount node into the
// user menu and mount the Vue app there, reusing GLPI's Vue runtime (window.Vue).
// This bundle is loaded as a footer JS module, after build/vue/app.js, so window.Vue
// / window._vue are available; we still poll defensively for the user menu.
(function () {
    const MOUNT_ID = 'inappnotification-bell-root';

    function getBootData() {
        // Server-rendered config + translated labels, delivered via a <meta> tag
        // (see plugin_inappnotification_header_tags()). Falls back to a global if present.
        let data = window.INAPPNOTIFICATION || null;
        if (!data) {
            const meta = document.querySelector('meta[name="inappnotification:boot"]');
            if (meta) {
                try {
                    data = JSON.parse(meta.getAttribute('content') || '{}');
                } catch (e) {
                    data = null;
                }
            }
        }
        data = data || {};
        return {
            config: data.config || {},
            labels: data.labels || {},
        };
    }

    function findUserMenu() {
        const pageHeader = document.querySelector('header.navbar.d-print-none');
        return (pageHeader ? pageHeader.querySelector('.navbar-nav.flex-row.order-md-last.user-menu') : null)
            || (pageHeader ? pageHeader.querySelector('.user-menu') : null);
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
        host.innerHTML = `<div id="${MOUNT_ID}"></div>`;
        userMenu.prepend(host);

        const root = host.querySelector(`#${MOUNT_ID}`);
        if (!root) {
            return false;
        }
        if (root.__vue_app__ !== undefined) {
            return true;
        }

        const { config, labels } = getBootData();
        window.Vue.createApp(BellApp, { config, labels }).mount(root);
        return true;
    }

    function start() {
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
