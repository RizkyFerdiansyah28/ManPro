import test from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationPoller } from '../../resources/js/utils/notificationPoller.js';

const payload = { unread_count: 2, pending_count: 3, as_of: '2026-11-10T00:00:00.000000Z' };
const response = (data = payload, changes = {}) => ({ ok: true, status: 200, redirected: false,
    headers: { get: () => 'application/json' }, json: async () => data, ...changes });
function harness(fetcher = async () => response()) {
    let visible = true;
    let online = true;
    let counter = 0;
    const timers = new Map();
    const states = [];
    const requests = [];
    const poller = createNotificationPoller({
        onState: (s) => states.push(s), isVisible: () => visible, isOnline: () => online,
        fetchSummary: (...args) => { requests.push(args); return fetcher(...args); },
        setTimer: (fn, ms) => { const id = ++counter; timers.set(id, { fn, ms }); return id; },
        clearTimer: (id) => timers.delete(id),
    });
    return { poller, timers, states, requests, latest: () => states.at(-1),
        visible: (value) => { visible = value; }, online: (value) => { online = value; },
        next: async () => { const [id, task] = timers.entries().next().value; timers.delete(id); await task.fn(); } };
}
test('immediate summary then 15-second timer; no navigation or form payload', async () => {
    const h = harness();
    await h.poller.start();
    assert.deepEqual(h.latest().data, payload);
    assert.equal(h.timers.values().next().value.ms, 15000);
    await h.next();
    assert.equal(h.requests.length, 2);
    for (const [url, options] of h.requests) {
        assert.equal(url, '/notifications/summary');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.body, undefined);
    }
    h.poller.stop();
    assert.equal(h.timers.size, 0);
});
test('hidden/offline pauses; focus/reconnect refreshes immediately', async () => {
    const h = harness();
    h.visible(false);
    await h.poller.start();
    assert.equal(h.requests.length, 0);
    h.visible(true);
    await h.poller.visibilityChanged();
    assert.equal(h.requests.length, 1);
    h.online(false);
    h.poller.visibilityChanged();
    assert.equal(h.timers.size, 0);
    h.online(true);
    await h.poller.visibilityChanged();
    assert.equal(h.requests.length, 2);
    h.poller.stop();
});
for (const failure of ['401', '403', 'redirect', 'html']) {
    test('auth failure ' + failure + ' clears data and stops polling', async () => {
        let expired = false;
        const h = harness(async () => !expired ? response() : response(payload, {
            status: /^\d+$/.test(failure) ? Number(failure) : 200, redirected: failure === 'redirect',
            headers: { get: () => failure === 'html' ? 'text/html' : 'application/json' },
        }));
        await h.poller.start();
        expired = true;
        await h.poller.refresh();
        assert.equal(h.latest().expired, true);
        assert.equal(h.latest().data, null);
        assert.equal(h.timers.size, 0);
        const count = h.requests.length;
        await h.poller.refresh();
        assert.equal(h.requests.length, count);
    });
}
test('errors preserve previous counts as stale; backoff is bounded and recovers', async () => {
    let fail = false;
    const h = harness(async () => { if (fail) throw new Error('offline'); return response(); });
    await h.poller.start();
    fail = true;
    for (const expected of [30000, 60000, 60000]) {
        await h.next();
        assert.equal(h.timers.values().next().value.ms, expected);
        assert.deepEqual(h.latest().data, payload);
        assert.equal(h.latest().stale, true);
    }
    fail = false;
    await h.poller.refresh();
    assert.equal(h.latest().stale, false);
    assert.equal(h.timers.values().next().value.ms, 15000);
    h.poller.stop();
});
test('a stalled request is aborted after ten seconds, preserves stale counts, and can recover', async () => {
    let stalled = false;
    const h = harness(async (_url, options) => {
        if (!stalled) return response();
        return new Promise((_resolve, reject) => options.signal.addEventListener('abort',
            () => reject(new DOMException('Timed out', 'AbortError')), { once: true }));
    });
    await h.poller.start();
    stalled = true;
    const request = h.poller.refresh();
    assert.equal(h.timers.values().next().value.ms, 10000);
    await h.next();
    await request;
    assert.equal(h.requests.at(-1)[1].signal.aborted, true);
    assert.equal(h.latest().loading, false);
    assert.equal(h.latest().stale, true);
    assert.deepEqual(h.latest().data, payload);
    assert.equal(h.timers.values().next().value.ms, 30000);
    stalled = false;
    await h.poller.refresh();
    assert.equal(h.latest().stale, false);
    assert.equal(h.timers.values().next().value.ms, 15000);
    h.poller.stop();
    assert.equal(h.timers.size, 0);
});
test('one request in flight; stopped/old actor response cannot publish', async () => {
    let resolve;
    const h = harness(() => new Promise((r) => { resolve = r; }));
    const first = h.poller.start();
    await h.poller.refresh();
    assert.equal(h.requests.length, 1);
    const statesBeforeStop = h.states.length;
    h.poller.stop();
    assert.equal(h.requests[0][1].signal.aborted, true);
    resolve(response());
    await first;
    assert.equal(h.states.length, statesBeforeStop);
    assert.equal(h.timers.size, 0);
});
for (const invalid of [{ ...payload, unread_count: -1 }, { ...payload, pending_count: '3' }, { ...payload, as_of: 'invalid' }]) {
    test('invalid response ' + JSON.stringify(invalid) + ' is not trusted data', async () => {
        const h = harness(async () => response(invalid));
        await h.poller.start();
        assert.equal(h.latest().data, null);
        assert.equal(h.latest().stale, true);
        assert.equal(h.latest().expired, false);
        h.poller.stop();
    });
}
