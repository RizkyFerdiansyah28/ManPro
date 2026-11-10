// Ringkasan tidak menavigasi/reload Inertia dan tidak pernah mengirim data form.
export function createNotificationPoller({
    onState, fetchSummary = (url, options) => fetch(url, options),
    isVisible = () => document.visibilityState === 'visible', isOnline = () => navigator.onLine,
    setTimer = setTimeout, clearTimer = clearTimeout,
}) {
    let running = false;
    let timer = null;
    let controller = null;
    let requestTimer = null;
    let epoch = 0;
    let failures = 0;
    let state = { data: null, loading: false, stale: true, expired: false, error: '' };
    const publish = (changes) => { state = { ...state, ...changes }; onState({ ...state }); };
    const clear = () => { if (timer !== null) clearTimer(timer); timer = null; };
    const clearRequestTimer = () => { if (requestTimer !== null) clearTimer(requestTimer); requestTimer = null; };
    function pause() {
        clear();
        clearRequestTimer();
        epoch++;
        controller?.abort();
        controller = null;
        publish({ loading: false, stale: true });
    }
    async function refresh() {
        if (!running || state.expired) return;
        clear();
        if (!isVisible() || !isOnline()) {
            pause();
            if (!isOnline()) publish({ error: 'Koneksi offline. Ringkasan belum diperbarui.' });
            return;
        }
        if (controller) return;
        const request = new AbortController();
        controller = request;
        const current = ++epoch;
        publish({ loading: true });
        let timedOut = false;
        requestTimer = setTimer(() => { timedOut = true; request.abort(); }, 10000);
        try {
            const response = await fetchSummary('/notifications/summary', {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: request.signal,
            });
            if (!running || current !== epoch) return;
            if ([401, 403].includes(response.status) || response.redirected
                || !(response.headers.get('Content-Type') || '').includes('application/json')) {
                running = false;
                publish({ data: null, expired: true, stale: true, error: 'Sesi atau izin akses telah berubah. Masuk kembali untuk melanjutkan.' });
                return;
            }
            if (!response.ok) throw new Error('summary_unavailable');
            const data = await response.json();
            if (!Number.isSafeInteger(data.unread_count) || data.unread_count < 0
                || !Number.isSafeInteger(data.pending_count) || data.pending_count < 0
                || typeof data.as_of !== 'string' || Number.isNaN(Date.parse(data.as_of))) throw new Error('summary_invalid');
            if (!running || current !== epoch) return;
            failures = 0;
            publish({ data: { unread_count: data.unread_count, pending_count: data.pending_count, as_of: data.as_of }, stale: false, error: '' });
        } catch (failure) {
            if (running && current === epoch && (failure.name !== 'AbortError' || timedOut)) {
                failures++;
                publish({ stale: true, error: 'Ringkasan belum dapat diperbarui. Antrean insiden tetap dapat dibuka secara manual.' });
            }
        } finally {
            if (controller === request) {
                clearRequestTimer();
                controller = null;
                publish({ loading: false });
                if (running && isVisible() && isOnline()) timer = setTimer(refresh, Math.min(60000, 15000 * (2 ** failures)));
            }
        }
    }
    return {
        start() { if (!state.expired) { running = true; return refresh(); } },
        refresh,
        visibilityChanged() { return isVisible() && isOnline() ? refresh() : pause(); },
        stop() { running = false; clear(); clearRequestTimer(); epoch++; controller?.abort(); controller = null; },
    };
}
