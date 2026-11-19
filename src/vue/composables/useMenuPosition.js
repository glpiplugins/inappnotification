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

import { ref, nextTick } from 'vue';

// Flip an action menu up / left / right so it stays inside the dropdown bounds.
// State is exposed reactively so the caller binds `menuUp` as a class and
// `menuStyle` as inline styles.
export function useMenuPosition() {
    const menuUp = ref(false);
    const menuStyle = ref({});

    async function position(menuEl, wrapEl) {
        if (!menuEl || !wrapEl) {
            return;
        }
        const dropdown = menuEl.closest('.inappnotification-dropdown');
        if (!dropdown) {
            return;
        }

        menuUp.value = false;
        menuStyle.value = {};
        await nextTick();

        let menuRect = menuEl.getBoundingClientRect();
        const wrapRect = wrapEl.getBoundingClientRect();
        const dropdownRect = dropdown.getBoundingClientRect();

        const overBottom = menuRect.bottom > (dropdownRect.bottom - 4);
        const canOpenUp = (wrapRect.top - menuRect.height) >= (dropdownRect.top + 4);
        if (overBottom && canOpenUp) {
            menuUp.value = true;
            await nextTick();
            menuRect = menuEl.getBoundingClientRect();
        }

        if (menuRect.left < (dropdownRect.left + 4)) {
            menuStyle.value = { right: 'auto', left: '0' };
            await nextTick();
            menuRect = menuEl.getBoundingClientRect();
        }

        if (menuRect.right > (dropdownRect.right - 4)) {
            menuStyle.value = { ...menuStyle.value, left: 'auto', right: '0' };
        }
    }

    function reset() {
        menuUp.value = false;
        menuStyle.value = {};
    }

    return { menuUp, menuStyle, position, reset };
}
