import { ref } from 'vue';

// Data layer for the bell: talks to NotificationController (GET <apiUrl>, POST <apiUrl>/<action>).
// CSRF handling + single retry on session/CSRF expiry, ported from the legacy code.
// Responses are data-only now (the i18n payload has been removed server-side).
export function useNotifications(config) {
    const apiUrl = String(config.apiUrl || '');
    const csrfToken = ref(String(config.csrf || ''));

    const resolveCsrfToken = function () {
        if (csrfToken.value) {
            return csrfToken.value;
        }
        if (typeof window.getAjaxCsrfToken === 'function') {
            const ajaxToken = window.getAjaxCsrfToken();
            if (typeof ajaxToken === 'string' && ajaxToken !== '') {
                csrfToken.value = ajaxToken;
                return csrfToken.value;
            }
        }
        const metaToken = document.querySelector('meta[property="glpi:csrf_token"]');
        if (metaToken) {
            const metaValue = String(metaToken.getAttribute('content') || '');
            if (metaValue !== '') {
                csrfToken.value = metaValue;
                return csrfToken.value;
            }
        }
        const tokenInput = document.querySelector('input[name="_glpi_csrf_token"]');
        if (tokenInput && typeof tokenInput.value === 'string' && tokenInput.value !== '') {
            csrfToken.value = tokenInput.value;
            return csrfToken.value;
        }
        return '';
    };

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

    const ensureCsrfToken = async function () {
        let token = resolveCsrfToken();
        if (token) {
            return token;
        }
        try {
            const response = await fetch(buildListUrl(1), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const { payload } = await parseJsonResponse(response);
            if (payload && Number(payload.success) === 1) {
                const data = payload.data || {};
                token = String(data.csrf_token || '');
                if (token !== '') {
                    csrfToken.value = token;
                    return token;
                }
            }
        } catch (e) {
            // Keep silent: caller decides what to do if token stays empty.
        }
        return '';
    };

    // GET the bell list. Returns payload.data (items/unread_total/muted_total/csrf_token) or null.
    const list = async function (limit = 15) {
        try {
            const response = await fetch(buildListUrl(limit), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const { payload } = await parseJsonResponse(response);
            if (payload && Number(payload.success) === 1) {
                const data = payload.data || {};
                csrfToken.value = String(data.csrf_token || resolveCsrfToken());
                return data;
            }
        } catch (e) {
            // Keep last state.
        }
        return null;
    };

    // POST an action. Returns payload.data on success or null. Retries once on 401/403/failure.
    const action = async function (name, body = {}) {
        const sendAction = async function (token) {
            const form = new URLSearchParams();
            if (token) {
                form.set('_glpi_csrf_token', token);
            }
            Object.keys(body).forEach((key) => {
                const value = body[key];
                if (Array.isArray(value)) {
                    value.forEach((entry) => form.append(key + '[]', String(entry)));
                    return;
                }
                form.set(key, String(value));
            });

            const response = await fetch(`${apiUrl}/${encodeURIComponent(name)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-Glpi-Csrf-Token': token } : {}),
                },
                body: form.toString(),
            });
            return parseJsonResponse(response);
        };

        try {
            const token = await ensureCsrfToken();
            if (!token) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('[inappnotification] Missing CSRF token; aborting POST action');
                }
                return null;
            }

            let { payload, status } = await sendAction(token);

            // If session/CSRF state changed, refresh token once and retry.
            if (Number(status) === 401 || Number(status) === 403 || !payload || Number(payload.success) !== 1) {
                csrfToken.value = '';
                const refreshedToken = await ensureCsrfToken();
                if (refreshedToken) {
                    ({ payload, status } = await sendAction(refreshedToken));
                }
            }

            if (Number(status) >= 400 || !payload || Number(payload.success) !== 1) {
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('[inappnotification] Action failed', { action: name, status, payload });
                }
                return null;
            }

            const data = payload.data || {};
            csrfToken.value = String(data.csrf_token || csrfToken.value);
            return data;
        } catch (e) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[inappnotification] postAction failed', e);
            }
            return null;
        }
    };

    return { csrfToken, ensureCsrfToken, resolveCsrfToken, list, action };
}
