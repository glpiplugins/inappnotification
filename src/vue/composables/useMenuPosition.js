import { ref, nextTick } from 'vue';

// Flip an action menu up / left / right so it stays inside the dropdown bounds.
// Ported from the legacy positionItemMenu(); state is exposed reactively so the
// caller binds `menuUp` as a class and `menuStyle` as inline styles.
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
