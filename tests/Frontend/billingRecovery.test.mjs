import test from 'node:test';
import assert from 'node:assert/strict';
import { InvoiceWorkflow, observeInvoicePage } from '../../resources/js/utils/billing/workflow.js';
import { createIntentStorage, intentPrefix, purgeInvoiceAccess } from '../../resources/js/utils/billing/intentStorage.js';
import { createInvoiceApi, InvoiceApiError } from '../../resources/js/utils/billing/api.js';
import { context, preview as parsePreview, invoice, page, invoiceBase, month } from '../../resources/js/utils/billing/contracts.js';
import { installInvoiceSessionBoundary } from '../../resources/js/utils/billing/sessionBoundary.js';
import { actor, ctx, target, token, requestId, preview, issued, frozen, MemoryStorage, listInvoice, detailInvoice, pagination } from './billingFixtures.mjs';

function rig(overrides = {}, storage = new MemoryStorage()) {
    const calls = [];
    const api = { context: async () => { calls.push(['context']); return { ...ctx }; }, preview: async (v) => { calls.push(['preview', v]); return structuredClone(preview); },
        issue: async (v) => { calls.push(['issue', v]); return { ...issued }; }, reconcile: async (v) => { calls.push(['reconcile', v]); return { ...issued, replayed: true }; }, ...overrides };
    const store = createIntentStorage(storage);
    return { workflow: new InvoiceWorkflow({ api, store, actor }), api, store, storage, calls };
}
async function eligible(r) { await r.workflow.checkContext(); await r.workflow.preview(target); assert.equal(r.workflow.getSnapshot().name, 'eligible'); }
const rejected = (code, status = 503) => async () => { throw new InvoiceApiError(code, status); };

test('frozen intent survives unknown, refresh, unresolved and expired reconcile without another issue', async () => {
    const r = rig({ issue: rejected('outcome_unknown') });
    await eligible(r); r.workflow.confirm(); await r.workflow.issue();
    assert.equal(r.workflow.getSnapshot().name, 'outcome_unknown');
    const saved = r.store.read(ctx);
    assert.equal(saved.confirmation_token, token); assert.equal(saved.request_id, requestId);
    assert.doesNotMatch(JSON.stringify(r.workflow.getSnapshot()), new RegExp(token));
    const replayCalls = [];
    const next = rig({ reconcile: async (v) => { replayCalls.push(v); throw new InvoiceApiError('outcome_unresolved', 409); } }, r.storage);
    await next.workflow.checkContext(); assert.equal(next.workflow.getSnapshot().name, 'unresolved');
    await next.workflow.preview(target); await next.workflow.issue();
    assert.equal(next.calls.filter(([op]) => ['preview', 'issue'].includes(op)).length, 0);
    await next.workflow.reconcile(); assert.equal(next.workflow.getSnapshot().name, 'unresolved');
    assert.equal(replayCalls[0].request_id, requestId); assert.equal(replayCalls[0].confirmation_token, token);
    next.api.reconcile = async () => ({ ...issued, replayed: true });
    await next.workflow.reconcile(); assert.equal(next.workflow.getSnapshot().name, 'replayed');
    assert.equal(next.store.read(ctx), null);
});

test('double click is latched; state and storage are attempted before POST', async () => {
    let resolve; let count = 0;
    const r = rig({ issue: async () => { count++; assert.equal(r.store.read(ctx).status, 'attempted'); return new Promise((done) => { resolve = done; }); } });
    await eligible(r); r.workflow.confirm();
    const a = r.workflow.issue(); const b = r.workflow.issue();
    await new Promise(setImmediate); assert.equal(count, 1);
    resolve({ ...issued }); await Promise.all([a, b]); assert.equal(r.workflow.getSnapshot().name, 'issued');
});

for (const [field, value] of [['actor_id', '62345678-1234-4234-8234-123456789abc'], ['session_context', 'c'.repeat(64)], ['audience_context', 'c'.repeat(64)]]) {
    test('fresh context mismatch blocks stored token and new preview: ' + field, async () => {
        const r = rig(); await eligible(r);
        r.api.context = async () => ({ ...ctx, [field]: value });
        await r.workflow.checkContext();
        assert.equal(r.workflow.getSnapshot().name, 'authorization_lost');
        assert.doesNotMatch([...r.storage.data.values()].join(''), new RegExp(token));
        await r.workflow.preview(target); assert.equal(r.calls.filter(([op]) => op === 'preview').length, 1);
    });
}
for (const code of ['network_error', 'malformed_response', 'billing_unavailable', 'rate_limited']) {
    test('context failure never reads pending storage: ' + code, async () => {
        let reads = 0;
        const storage = { get length() { reads++; throw new Error(); } };
        const r = rig({ context: rejected(code) }, storage);
        await r.workflow.checkContext(); assert.equal(reads, 0); assert.equal(r.workflow.getSnapshot().name, 'readiness_unavailable');
    });
}
test('cached page metadata is never used to open storage; malformed fresh context fails closed', async () => {
    let reads = 0; const storage = { get length() { reads++; throw new Error(); } };
    const r = rig({ context: async () => ({ ...ctx, version: '1' }) }, storage);
    await r.workflow.checkContext(); assert.equal(reads, 0); assert.equal(r.workflow.getSnapshot().name, 'readiness_unavailable');
});
for (const code of ['network_error', 'malformed_response', 'confirmation_expired', 'confirmation_invalid', 'invalid_input', 'rate_limited', 'preview_stale', 'invoice_exists', 'request_conflict']) {
    test('issue errors retain original intent with no automatic retry: ' + code, async () => {
        let count = 0; const r = rig({ issue: async () => { count++; throw new InvoiceApiError(code, code === 'invalid_input' ? 422 : 409); } });
        await eligible(r); r.workflow.confirm(); await r.workflow.issue();
        assert.equal(r.workflow.getSnapshot().name, 'outcome_unknown');
        await r.workflow.issue(); await r.workflow.preview(target);
        assert.equal(count, 1); assert.equal(r.store.read(ctx).request_id, requestId); assert.equal(r.store.read(ctx).confirmation_token, token);
    });
}
for (const status of [401, 403, 419]) {
    test('authorization lost removes local access but retains a manual hold: ' + status, async () => {
        const r = rig({ issue: rejected('authentication_required', status) }); await eligible(r); r.workflow.confirm(); await r.workflow.issue();
        assert.equal(r.workflow.getSnapshot().name, 'authorization_lost');
        assert.doesNotMatch([...r.storage.data.values()].join(''), new RegExp(token));
        assert.throws(() => r.store.read(ctx), /Intent memerlukan/);
    });
}
test('auth changes after POST block result disclosure; completion after disposal cannot navigate or clear pending', async () => {
    let resolve; const r = rig({ issue: async () => new Promise((done) => { resolve = done; }) });
    await eligible(r); r.workflow.confirm(); const action = r.workflow.issue(); await new Promise(setImmediate);
    r.workflow.loseAccess(); resolve({ ...issued }); await action;
    assert.equal(r.workflow.getSnapshot().result, null);
    const next = rig({ issue: async () => new Promise((done) => { resolve = done; }) });
    await eligible(next); next.workflow.confirm(); const pending = next.workflow.issue(); await new Promise(setImmediate);
    next.workflow.dispose(); resolve({ ...issued }); await pending; assert.equal(next.store.read(ctx).request_id, requestId);
});
for (const decision of ['HOLD', 'SKIP', 'REJECT']) {
    test('non-ISSUE preview cannot issue: ' + decision, async () => {
        const reason = { HOLD: 'null_monthly_rate', SKIP: 'no_chargeable_service', REJECT: 'billing_month_not_ended' }[decision];
        const r = rig({ preview: async () => ({ kind: 'preview', issued: false, decision, reason, calculation: null, confirmation: null }) });
        await r.workflow.checkContext(); await r.workflow.preview(target); r.workflow.confirm(); await r.workflow.issue();
        assert.equal(r.workflow.getSnapshot().name, 'idle'); assert.equal(r.workflow.getSnapshot().hasIntent, false); assert.equal(r.calls.filter(([op]) => op === 'issue').length, 0);
    });
}
for (const bad of ['{', JSON.stringify({ ...frozen(), context: { ...ctx, version: 2 } }), 'x'.repeat(8193), JSON.stringify({ ...frozen(), financial_owner_id: actor })]) {
    test('corrupt or oversized storage enters manual recovery and removes token access', () => {
        const s = new MemoryStorage(); const store = createIntentStorage(s); store.save(frozen());
        const key = [...s.data.keys()].find((k) => !k.endsWith('hold')); s.setItem(key, bad);
        assert.throws(() => store.read(ctx), /Intent memerlukan/); assert.equal(s.getItem(key), null); assert.equal(s.getItem(intentPrefix + 'hold'), 'manual');
    });
}
test('unavailable storage never sends preview or issue; missing record with hold is not rollback', async () => {
    const r = rig({}, null); await r.workflow.checkContext(); assert.equal(r.workflow.getSnapshot().name, 'manual_recovery');
    await r.workflow.preview(target); assert.equal(r.calls.filter(([op]) => op === 'preview').length, 0);
    const s = new MemoryStorage(); s.setItem(intentPrefix + 'hold', 'pending'); assert.throws(() => createIntentStorage(s).read(ctx), /Intent memerlukan/);
});

test('read-only lifecycle events each require fresh context, with cleanup on unmount', async () => {
    const events = new EventTarget(); const doc = new EventTarget(); doc.visibilityState = 'visible';
    let checks = 0; let disposed = false; let lost = 0;
    const cleanup = observeInvoicePage({ workflow: { checkContext() { checks++; }, loseAccess() { lost++; }, dispose() { disposed = true; } }, target: events, doc });
    for (const type of ['focus', 'pageshow', 'popstate']) events.dispatchEvent(new Event(type)); doc.dispatchEvent(new Event('visibilitychange'));
    assert.equal(checks, 5); events.dispatchEvent(new Event('silansia:billing-access-lost')); assert.equal(lost, 1);
    cleanup(); events.dispatchEvent(new Event('focus')); assert.equal(checks, 5); assert.equal(disposed, true);
});
test('logout and cross-tab signal purge token access; missing auth on error props is not logout', () => {
    const callbacks = {}; const s = new MemoryStorage(); createIntentStorage(s).save(frozen());
    let received; let sent;
    class Channel { constructor() { received = this; } postMessage(v) { sent = v; } close() {} }
    const target = new EventTarget(); Object.assign(target, { sessionStorage: s, BroadcastChannel: Channel, location: { origin: 'http://fixture' }, Event });
    const cleanup = installInvoiceSessionBoundary({ router: { on(name, fn) { callbacks[name] = fn; return () => {}; } }, target, initialAuth: { user: { id: actor, role: 'admin' } } });
    callbacks.navigate({ detail: { page: { props: {} } } }); assert.match([...s.data.values()].join(''), new RegExp(token));
    callbacks.before({ detail: { visit: { method: 'post', url: '/logout' } } }); assert.deepEqual(sent, { type: 'access_lost' }); assert.doesNotMatch([...s.data.values()].join(''), new RegExp(token));
    createIntentStorage(s).save(frozen()); received.onmessage({ data: { type: 'access_lost' } }); assert.doesNotMatch([...s.data.values()].join(''), new RegExp(token)); cleanup();
});

test('adapter POST has CSRF/cookies/no-store, no X-Inertia and no financial abort/retry', async () => {
    const calls = [];
    const api = createInvoiceApi({ doc: { cookie: 'XSRF-TOKEN=encoded%3Dcookie' }, fetcher: async (path, options) => { calls.push([path, options]); return new Response(JSON.stringify(issued), { status: 201, headers: { 'Content-Type': 'application/json' } }); } });
    const { context: ignored, version: ignoredVersion, expires_at: ignoredExpires, status: ignoredStatus, ...body } = frozen();
    await api.issue(body); assert.equal(calls.length, 1); const opts = calls[0][1];
    assert.equal(opts.credentials, 'same-origin'); assert.equal(opts.cache, 'no-store'); assert.equal(opts.headers['X-XSRF-TOKEN'], 'encoded=cookie'); assert.equal(opts.headers['X-Inertia'], undefined); assert.equal(opts.signal, undefined); assert.equal(JSON.parse(opts.body).confirmation_token, token); assert.equal(JSON.parse(opts.body).confirm, true);
});
for (const body of ['<html>login</html>', '{', JSON.stringify({ ...issued, amount: 1 }), JSON.stringify({ ...issued, detail_url: 'https://evil.test' }), JSON.stringify({ ...issued, source_fingerprint: 'secret' })]) {
    test('malformed committed response is not treated as success', async () => {
        const api = createInvoiceApi({ doc: { cookie: 'XSRF-TOKEN=x' }, fetcher: async () => new Response(body, { status: 201, headers: { 'Content-Type': 'application/json' } }) });
        await assert.rejects(api.issue({}), (e) => e.code === 'malformed_response' && !e.message.includes(token));
    });
}
test('Inertia context parser rejects cached/partial/wrong component or marker types', async () => {
    for (const body of [{ component: 'Auth/Login', props: { recovery_context: ctx } }, { component: 'Admin/Billing/Invoices/Create', props: {} }, { component: 'Admin/Billing/Invoices/Create', props: { recovery_context: { ...ctx, version: '1' } } }]) {
        const api = createInvoiceApi({ fetcher: async () => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } }) });
        await assert.rejects(api.context(), (e) => e.code === 'malformed_response');
    }
});
test('response contracts reject unsafe pagination and preserve max exact money', () => {
    assert.equal(invoice(detailInvoice, true).amount, '999999999999.99'); parsePreview(preview); context(ctx);
    assert.throws(() => page({ ...pagination([listInvoice]), next_page_url: invoiceBase + '?confirmation_token=SECRET' }, invoice, invoiceBase));
    assert.throws(() => invoice({ ...listInvoice, amount: Number(listInvoice.amount) }));
});

test('unsupported browser cannot be unlocked by manual context refresh', async () => {
    let requests = 0;
    const flow = new InvoiceWorkflow({ actor, api: { context: async () => { requests++; return ctx; } }, store: createIntentStorage(new MemoryStorage()), supported: false });
    await flow.checkContext(); await flow.checkContext(); await flow.preview(target);
    assert.equal(flow.getSnapshot().name, 'manual_recovery'); assert.equal(requests, 0);
});
test('late context/preview response cannot undo a logout event', async () => {
    let resolve;
    const r = rig({ context: async () => new Promise((done) => { resolve = done; }) });
    const checking = r.workflow.checkContext(); r.workflow.loseAccess(); resolve(ctx); await checking;
    assert.equal(r.workflow.getSnapshot().name, 'authorization_lost');
    const next = rig({ preview: async () => new Promise((done) => { resolve = done; }) });
    await next.workflow.checkContext(); const pending = next.workflow.preview(target); await new Promise(setImmediate);
    next.workflow.loseAccess(); resolve(preview); await pending;
    assert.equal(next.workflow.getSnapshot().name, 'authorization_lost'); assert.doesNotMatch([...next.storage.data.values()].join(''), new RegExp(token));
});
test('failed initial storage write prevents any issue and never leaves UI eligible', async () => {
    const storage = new MemoryStorage(); const base = storage.setItem.bind(storage);
    storage.setItem = (k, v) => { if (!k.endsWith('hold')) throw new Error('synthetic quota'); base(k, v); };
    const r = rig({}, storage); await r.workflow.checkContext(); await r.workflow.preview(target);
    assert.equal(r.workflow.getSnapshot().name, 'manual_recovery'); assert.equal(r.calls.filter(([op]) => op === 'issue').length, 0);
});
test('HTTP timeout leaves financial fetch un-aborted and sends only once', async () => {
    let calls = 0; let options;
    const api = createInvoiceApi({ doc: { cookie: 'XSRF-TOKEN=x' }, timeout: 5, fetcher: async (_, opts) => { calls++; options = opts; return new Promise(() => {}); } });
    await assert.rejects(api.issue({}), (error) => error.code === 'network_error'); assert.equal(calls, 1); assert.equal(options.signal, undefined);
});
test('CSRF is mandatory before any financial fetch and missing cookie never transmits', async () => {
    let count = 0;
    const api = createInvoiceApi({ doc: { cookie: '' }, fetcher: async () => { count++; } });
    await assert.rejects(api.issue({}), (error) => error.status === 419); assert.equal(count, 0);
});
test('full Inertia GET sends asset version without partial reload or token fields', async () => {
    let options;
    const api = createInvoiceApi({ version: () => 'fixture-version', fetcher: async (_, value) => { options = value; return new Response(JSON.stringify({ component: 'Admin/Billing/Invoices/Create', props: { recovery_context: ctx } }), { headers: { 'Content-Type': 'application/json' } }); } });
    assert.deepEqual(await api.context(), ctx); assert.equal(options.headers['X-Inertia-Version'], 'fixture-version'); assert.equal(options.headers['X-Inertia'], 'true'); assert.equal(options.headers['X-Inertia-Partial-Data'], undefined); assert.equal(options.body, undefined);
});
test('canonical four-digit AD months follow backend including early years', () => {
    assert.equal(month('0001-01-01'), true); assert.equal(month('0000-01-01'), false); assert.equal(month('2026-13-01'), false); assert.equal(month('2026-10-02'), false);
});
