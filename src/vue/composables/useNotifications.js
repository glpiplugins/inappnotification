// Data layer for the bell: talks to NotificationController (GET <apiUrl>, POST <apiUrl>/<action>).
// No CSRF token: GLPI 12 validates same-origin POSTs via the Sec-Fetch-Site/Origin headers,
// which the browser sets on fetch() itself.
// Responses are data-only now (the i18n payload has been removed server-side).
export function useNotifications(config) {
    const apiUrl = String(config.apiUrl || '');

    const parseJsonResponse = async function (response) {
        const text = await response.text();
        try {
            return { payload: JSON.parse(text), status: response.status };
        } catch (e) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[inappnotification] Invalid JSON response', {
                    status: response.status,
                    preview: text.slice(0, 200),
                });
            }
            return { payload: null, status: response.status };
        }
    };

    const buildListUrl = function (limit = 15) {
        return `${apiUrl}?limit=${Number(limit)}&_=${Date.now()}`;
    };

    // GET the bell list. Returns payload.data (items/unread_total/muted_total) or null.
    const list = async function (limit = 15) {
        try {
            const response = await fetch(buildListUrl(limit), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const { payload } = await parseJsonResponse(response);
            if (payload && Number(payload.success) === 1) {
                return payload.data || {};
            }
        } catch (e) {
            // Keep last state.
        }
        return null;
    };

    // POST an action. Returns payload.data on success or null.
    const action = async function (name, body = {}) {
        const form = new URLSearchParams();
        Object.keys(body).forEach((key) => {
            const value = body[key];
            if (Array.isArray(value)) {
                value.forEach((entry) => form.append(key + '[]', String(entry)));
                return;
            }
            form.set(key, String(value));
        });

        try {
            const response = await fetch(`${apiUrl}/${encodeURIComponent(name)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form.toString(),
            });
            const { payload, status } = await parseJsonResponse(response);

            if (Number(status) >= 400 || !payload || Number(payload.success) !== 1) {
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('[inappnotification] Action failed', { action: name, status, payload });
                }
                return null;
            }

            return payload.data || {};
        } catch (e) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[inappnotification] postAction failed', e);
            }
            return null;
        }
    };

    return { list, action };
}
