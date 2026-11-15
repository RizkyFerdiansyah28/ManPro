import { test, expect } from '@playwright/test';
import { appendFile, mkdir } from 'node:fs/promises';
import { actor, ctx, target, token, requestId, preview, issued, frozen, detailInvoice, listInvoice, pagination } from '../Frontend/billingFixtures.mjs';

const origin = 'http://127.0.0.1:5187';
const entry = origin + '/tests/Frontend/billing-browser.html';
const base = '/admin/billing/invoices';
const prefix = 'silansia.invoice.intent.v1:';
const recordKey = prefix + [actor, ctx.session_context, ctx.audience_context].join(':');
const run = process.env.SILANSIA_BROWSER_RUN ?? 'FINAL';
let rig;
const clean = text => String(text).replaceAll(token, '[REDACTED]').replace(/[a-f0-9]{64}/gi, '[CONTEXT]');
const errorBody = (code, status) => ({ status, contentType: 'application/json', body: JSON.stringify({ error: { code, message: 'Synthetic acceptance response', retry_mode: 'review' }, financial_outcome: 'unknown', correlation_id: actor }) });
const unexpectedNetworkFailures = () => rig.failedRequests.filter(item => !(item.method === 'GET' && item.error === 'net::ERR_ABORTED'
    && (item.path.startsWith('/resources/') || item.path === '/notifications/summary') && rig.successfulGets.has(item.path)));

test.beforeEach(async ({ context, page }, info) => {
    rig = { name: info.title, contexts: 0, previews: 0, issues: 0, reconciles: 0, logouts: 0, sequence: [],
        financial: [], runtime: [], console: [], failedRequests: [], blocked: [], httpErrors: [],
        contextValue: { ...ctx }, contextStatus: 200, decision: 'ISSUE', badPreview: false,
        issueMode: 'unknown', reconcileMode: 'unresolved', delay: 0, successfulGets: new Set() };
    context.on('page', child => observe(child));
    function observe(p) {
        p.on('pageerror', error => rig.runtime.push(clean(error.message)));
        p.on('console', message => { if (message.type() === 'error') rig.console.push(clean(message.text())); });
        p.on('requestfailed', request => rig.failedRequests.push({ path: new URL(request.url()).pathname, method: request.method(), error: request.failure()?.errorText }));
        p.on('response', response => {
            const path = new URL(response.url()).pathname;
            if (response.status() === 200 && response.request().method() === 'GET') rig.successfulGets.add(path);
            if (response.status() >= 400) rig.httpErrors.push({ path, status: response.status(),
                expected_logout_location: path === '/logout' && response.status() === 409 && response.headers()['x-inertia-location'] === entry });
        });
    }
    observe(page);
    await context.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (url.origin !== origin) { rig.blocked.push(url.origin); return route.abort('blockedbyclient'); }
        const path = url.pathname; const method = request.method();
        const json = (body, status = 200, inertia = false) => route.fulfill({ status, contentType: 'application/json', headers: inertia ? { 'X-Inertia': 'true', 'Cache-Control': 'no-store' } : { 'Cache-Control': 'no-store' }, body: JSON.stringify(body) });
        const inertia = (component, props) => json({ component: 'Admin/Billing/Invoices/' + component, props: { auth: { user: { id: actor, role: 'admin', name: 'Admin fixture', home_url: '/admin' } }, errors: {}, flash: {}, ...props }, url: path + url.search, version: 'fixture-v1', clearHistory: false, encryptHistory: false }, 200, true);
        if (path === '/logout' && method === 'POST') {
            rig.logouts++; rig.contextStatus = 401;
            // Revoke synthetic authentication and use Inertia's location protocol.
            // A bare 204 opens an invalid-response modal while the old mock still authenticates.
            return route.fulfill({ status: 409, headers: { 'X-Inertia-Location': entry }, body: '' });
        }
        if (!path.startsWith(base)) return route.continue();
        if (request.isNavigationRequest()) return route.continue(); // Exercise the real fixture's HTML bootstrap.
        if (method === 'GET' && path === base + '/create') {
            rig.contexts++; rig.sequence.push('context');
            if (rig.contextGate) await rig.contextGate;
            expect(request.headers()['x-inertia']).toBe('true');
            expect(request.headers()['x-inertia-partial-data']).toBeUndefined();
            if (rig.contextStatus !== 200) return route.fulfill(errorBody('authentication_required', rig.contextStatus));
            return inertia('Create', { recovery_context: rig.contextValue, frontend_available: false, filters: {} });
        }
        if (method === 'GET' && path === base + '/resident-options') return json(pagination([{ id: target.elderly_id, name_current: 'Lansia fixture sintetis', is_active: true }]));
        if (method === 'GET' && path === base) return inertia('Index', { invoices: pagination([listInvoice]), filters: {} });
        if (method === 'GET') return inertia('Show', { invoice: detailInvoice });
        if (path.endsWith('/preview')) {
            rig.previews++; rig.sequence.push('preview');
            expect(request.postDataJSON()).toEqual(target);
            if (rig.badPreview) return json({ invalid: true });
            return json(rig.decision === 'ISSUE' ? preview : { kind: 'preview', issued: false, decision: rig.decision,
                reason: { HOLD: 'null_monthly_rate', SKIP: 'no_chargeable_service', REJECT: 'billing_month_not_ended' }[rig.decision], calculation: null, confirmation: null });
        }
        const isReconcile = path === base + '/reconcile';
        if (!isReconcile && path !== base) throw new Error('Unexpected synthetic financial path');
        const body = request.postDataJSON(); const state = await request.frame().evaluate(({ prefix, body }) => {
            try {
                const key = Object.keys(sessionStorage).find(k => k.startsWith(prefix) && !k.endsWith('hold'));
                const saved = key ? JSON.parse(sessionStorage.getItem(key)) : null;
                return { attempted_before_post: saved?.status === 'attempted', same_stored_tuple: saved?.request_id === body.request_id && saved?.confirmation_token === body.confirmation_token && saved?.elderly_id === body.elderly_id && saved?.billing_month === body.billing_month };
            } catch { return { attempted_before_post: false, same_stored_tuple: false }; }
        }, { prefix, body });
        const sameOriginal = body.request_id === requestId && body.confirmation_token === token && body.elderly_id === target.elderly_id && body.billing_month === target.billing_month;
        rig.financial.push({ operation: isReconcile ? 'reconcile' : 'issue', ...state, original_tuple: sameOriginal, confirmation_required: isReconcile || body.confirm === true,
            csrf_present: request.headers()['x-xsrf-token'] === 'synthetic-csrf-only', no_inertia: request.headers()['x-inertia'] === undefined,
            fresh_context_immediately_before_post: rig.sequence.at(-1) === 'context' });
        expect(sameOriginal).toBe(true); expect(state.attempted_before_post).toBe(true); expect(state.same_stored_tuple).toBe(true);
        expect(rig.financial.at(-1)).toMatchObject({ confirmation_required: true, csrf_present: true, no_inertia: true, fresh_context_immediately_before_post: true });
        expect(request.headers()['x-inertia']).toBeUndefined();
        if (isReconcile) { rig.reconciles++; rig.sequence.push('reconcile'); } else { rig.issues++; rig.sequence.push('issue'); }
        if (rig.delay) await new Promise(resolve => setTimeout(resolve, rig.delay));
        const mode = isReconcile ? rig.reconcileMode : rig.issueMode;
        if (mode === 'timeout') { await new Promise(resolve => setTimeout(resolve, 3500)); return route.fulfill(errorBody('outcome_unknown', 503)); }
        if (mode === 'network') return route.abort('failed');
        if (mode === 'malformed') return json({ invalid: true }, isReconcile ? 200 : 201);
        if (typeof mode === 'number') return route.fulfill(errorBody(mode === 419 ? 'session_expired' : 'authentication_required', mode));
        if (mode === 'replay') return json({ ...issued, replayed: true }, 200);
        if (mode === 'issued') return json(issued, 201);
        return route.fulfill(errorBody(mode === 'expired' ? 'confirmation_expired' : mode === 'unresolved' ? 'outcome_unresolved' : 'outcome_unknown', mode === 'unknown' ? 503 : 409));
    });
    await context.routeWebSocket('**/*', ws => {
        const url = new URL(ws.url());
        if (url.host !== '127.0.0.1:5187') { rig.blocked.push(url.origin); ws.close(); } else ws.connectToServer();
    });
});

test.afterEach(async () => {
    const record = { scenario: rig.name, counts: { contexts: rig.contexts, previews: rig.previews, issues: rig.issues, reconciles: rig.reconciles, logouts: rig.logouts },
        ordered_requests: [...rig.sequence], financial_assertions: [...rig.financial], runtime_errors: [...rig.runtime], console_errors: [...rig.console],
        http_errors: [...rig.httpErrors], network_failures: [...rig.failedRequests], successful_gets: [...rig.successfulGets], blocked_origins: [...rig.blocked], keyboard_focus: rig.keyboardFocus ?? null };
    await mkdir('docs/report', { recursive: true });
    await appendFile(`docs/report/SILANSIA_P9_3C3B_REQUEST_EVIDENCE_${run}.jsonl`, JSON.stringify(record) + '\n');
    expect(rig.runtime).toEqual([]); expect(rig.blocked).toEqual([]);
    expect(rig.httpErrors.filter(item => !item.path.startsWith(base) && !(item.expected_logout_location && rig.logouts === 1))).toEqual([]);
    expect(unexpectedNetworkFailures().filter(item => !item.path.startsWith(base))).toEqual([]);
    expect(rig.console.filter(message => !message.startsWith('Failed to load resource:'))).toEqual([]);
});

async function boot(page) { await page.goto(entry); await expect(page.getByRole('heading', { name: 'Terbitkan invoice · fixture sintetis', exact: true })).toBeVisible(); }
async function submitPreview(page) {
    await boot(page); await page.getByRole('radio', { name: /Lansia fixture sintetis/ }).check();
    await page.locator('#invoice-month').fill('2026-10');
    await page.getByRole('checkbox', { name: /Saya sudah memastikan/ }).check();
    await page.getByRole('button', { name: 'Preview invoice', exact: true }).click();
}
async function eligible(page) { await submitPreview(page); await expect(page.getByRole('heading', { name: 'Hasil preview', exact: true })).toBeVisible(); }
async function confirm(page) { await eligible(page); await page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' }).click(); await page.getByRole('dialog').getByRole('checkbox').check(); }
async function unknown(page) { await confirm(page); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click(); await expect(page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' })).toBeVisible(); }
async function storedFlags(page) {
    return page.evaluate(prefix => { const keys = Object.keys(sessionStorage).filter(k => k.startsWith(prefix)); return { hold: sessionStorage.getItem(prefix + 'hold'), hasRecord: keys.some(k => !k.endsWith('hold')) }; }, prefix);
}
const checkRecovery = page => page.getByRole('button', { name: 'Periksa hasil intent yang sama' }).click();

for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    test('A/J initial render and usable layout ' + name, async ({ page }) => {
        await page.setViewportSize(viewport); await eligible(page);
        await expect(page.locator('.ui-management-facts .ui-invoice-amount')).toHaveText('Rp 999.999.999.999,99');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(rig.issues).toBe(0); expect(rig.httpErrors).toEqual([]); expect(unexpectedNetworkFailures()).toEqual([]); expect(rig.console).toEqual([]);
        await page.screenshot({ path: `docs/report/SILANSIA_P9_3C3B_${name.toUpperCase()}_${run}.png`, fullPage: true });
    });
}
test('B valid preview preserves exact server amount and sends no issue', async ({ page }) => { await eligible(page); expect(rig.previews).toBe(1); expect(rig.issues).toBe(0); expect(rig.sequence.slice(0, 3)).toEqual(['context', 'context', 'preview']); });
for (const decision of ['HOLD', 'SKIP', 'REJECT']) test('B non-ISSUE preview ' + decision, async ({ page }) => { rig.decision = decision; await eligible(page); await expect(page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' })).toHaveCount(0); expect(rig.issues).toBe(0); });
test('B malformed preview fails closed with focused alert', async ({ page }) => { rig.badPreview = true; await boot(page); await page.getByRole('radio', { name: /Lansia fixture sintetis/ }).check(); await page.locator('#invoice-month').fill('2026-10'); await page.getByRole('checkbox', { name: /Saya sudah memastikan/ }).check(); await page.getByRole('button', { name: 'Preview invoice', exact: true }).click(); await expect(page.getByRole('alert')).toBeFocused(); expect(rig.issues).toBe(0); });
test('C/J confirmation required, keyboard contained, cancel restores focus', async ({ page }) => {
    await eligible(page); const trigger = page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' }); await trigger.click();
    const dialog = page.getByRole('dialog'); const checkbox = dialog.getByRole('checkbox'); await expect(checkbox).toBeFocused();
    await expect(dialog.getByRole('button', { name: 'Konfirmasi dan terbitkan' })).toBeDisabled(); expect(rig.issues).toBe(0);
    await page.keyboard.press('Space'); await expect(dialog.getByRole('button', { name: 'Konfirmasi dan terbitkan' })).toBeEnabled();
    await page.keyboard.press('Shift+Tab'); rig.keyboardFocus = await page.evaluate(() => ({ tag: document.activeElement?.tagName, inDialog: Boolean(document.activeElement?.closest('dialog')), pageFocused: document.hasFocus() }));
    await expect(dialog.getByRole('button', { name: 'Konfirmasi dan terbitkan' })).toBeFocused();
    await page.keyboard.press('Tab'); await expect(checkbox).toBeFocused(); await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible(); await expect(trigger).toBeFocused(); expect(rig.issues).toBe(0);
});
test('C/D double click, write-before-POST and no automatic retry', async ({ page }) => {
    rig.delay = 400; await confirm(page); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).dblclick();
    await expect(page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' })).toBeVisible();
    await page.waitForTimeout(700); expect(rig.issues).toBe(1); expect(rig.previews).toBe(1); expect(rig.financial[0].attempted_before_post).toBe(true);
    await expect(page.getByRole('button', { name: 'Preview invoice', exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' })).toHaveCount(0);
});
test('C known issue navigates to immutable detail and clears matching intent', async ({ page }) => { rig.issueMode = 'issued'; await confirm(page); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click(); await expect(page.getByRole('heading', { name: 'Detail invoice', exact: true })).toBeVisible(); expect(rig.issues).toBe(1); expect((await storedFlags(page)).hasRecord).toBe(false); });
test('E unknown refresh restores original intent, unresolved then committed replay', async ({ page }) => {
    await unknown(page); const before = rig.contexts; await page.reload();
    await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); expect(rig.contexts).toBeGreaterThan(before);
    await checkRecovery(page); await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible();
    rig.reconcileMode = 'replay'; await checkRecovery(page); await expect(page.getByRole('heading', { name: 'Detail invoice', exact: true })).toBeVisible();
    expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(2); expect(rig.financial.every(r => r.original_tuple)).toBe(true);
});
for (const mode of ['unknown', 'network', 'expired', 'malformed']) test('E uncertain reconcile keeps original intent: ' + mode, async ({ page }) => {
    await unknown(page); rig.reconcileMode = mode; await checkRecovery(page);
    await expect(page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' })).toBeVisible(); expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(1); expect((await storedFlags(page)).hasRecord).toBe(true);
});
test('E issue network failure keeps intent with no retry', async ({ page }) => { rig.issueMode = 'network'; await unknown(page); await page.waitForTimeout(500); expect(rig.issues).toBe(1); expect((await storedFlags(page)).hasRecord).toBe(true); });
test('E actual timeout leaves original financial fetch un-aborted with no retry', async ({ page }) => { rig.issueMode = 'timeout'; await unknown(page); await page.waitForTimeout(700); expect(rig.issues).toBe(1); expect(rig.failedRequests).toEqual([]); expect((await storedFlags(page)).hasRecord).toBe(true); });
test('E stored recovery stays unread until fresh context response is available', async ({ context, page }) => {
    let release; rig.contextGate = new Promise(resolve => { release = resolve; });
    await context.addInitScript(({ key, value, prefix }) => {
        sessionStorage.setItem(key, JSON.stringify(value)); sessionStorage.setItem(prefix + 'hold', 'pending');
        window.__acceptanceReads = 0; const read = Storage.prototype.getItem;
        Storage.prototype.getItem = function(k) { if (k.startsWith(prefix)) window.__acceptanceReads++; return read.call(this, k); };
    }, { key: recordKey, value: frozen(), prefix });
    await boot(page); await expect.poll(() => rig.contexts).toBe(1);
    expect(await page.evaluate(() => window.__acceptanceReads)).toBe(0);
    await expect(page.getByRole('button', { name: 'Preview invoice', exact: true })).toBeDisabled();
    release(); await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible();
    expect(await page.evaluate(() => window.__acceptanceReads > 0)).toBe(true); expect(rig.issues).toBe(0);
});
for (const [field, value] of [['actor_id', '62345678-1234-4234-8234-123456789abc'], ['session_context', 'c'.repeat(64)], ['audience_context', 'd'.repeat(64)], ['version', 2]]) test('F fresh context mismatch blocks financial request: ' + field, async ({ page }) => {
    await unknown(page); rig.contextValue = { ...ctx, [field]: value }; await checkRecovery(page);
    await expect(page.getByRole('heading', { name: field === 'version' ? 'Hasil penerbitan belum diketahui' : 'Akses sesi berubah' })).toBeVisible();
    if (field === 'version') await expect(page.getByRole('alert')).toContainText('Respons server tidak dapat diverifikasi');
    expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(0); if (field !== 'version') expect((await storedFlags(page)).hasRecord).toBe(false);
});
for (const [field, value] of [['actor_id', '62345678-1234-4234-8234-123456789abc'], ['session_context', 'c'.repeat(64)], ['audience_context', 'd'.repeat(64)], ['version', 2]]) test('F changed context before first issue prevents financial fetch: ' + field, async ({ page }) => {
    await confirm(page); rig.contextValue = { ...ctx, [field]: value };
    await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click();
    await expect(page.getByRole('heading', { name: field === 'version' ? 'Konteks belum dapat diverifikasi' : 'Akses sesi berubah' })).toBeVisible();
    expect(rig.issues).toBe(0); expect(rig.reconciles).toBe(0);
});
for (const status of [401, 403, 419]) test('G authorization loss ' + status, async ({ page }) => { rig.issueMode = status; await confirm(page); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click(); await expect(page.getByRole('heading', { name: 'Akses sesi berubah' })).toBeVisible(); expect((await storedFlags(page))).toEqual({ hold: 'manual', hasRecord: false }); expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(0); });
test('G fresh context session expired before opening storage', async ({ page }) => { rig.contextStatus = 419; await boot(page); await expect(page.getByRole('heading', { name: 'Akses sesi berubah' })).toBeVisible(); expect(rig.previews).toBe(0); expect(rig.issues).toBe(0); });
test('G real logout action purges local intent and blocks financial controls', async ({ page }) => { await unknown(page); await page.getByRole('button', { name: 'Keluar', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Akses sesi berubah' })).toBeVisible(); expect((await storedFlags(page)).hasRecord).toBe(false); expect(rig.logouts).toBe(1); expect(rig.issues).toBe(1); });
test('G same account new session cannot recover token after logout', async ({ page }) => { await unknown(page); await page.getByRole('button', { name: 'Keluar', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Akses sesi berubah' })).toBeVisible(); rig.contextStatus = 200; rig.contextValue = { ...ctx, session_context: 'f'.repeat(64) }; await boot(page); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect((await storedFlags(page)).hasRecord).toBe(false); expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(0); });
test('G/I cross-tab logout signal purges original tab', async ({ context, page }) => { await unknown(page); const other = await context.newPage(); await boot(other); await other.getByRole('button', { name: 'logout-signal', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Akses sesi berubah' })).toBeVisible(); expect((await storedFlags(page)).hasRecord).toBe(false); expect(rig.issues).toBe(1); });

for (const [name, mutate] of [
    ['invalid JSON', () => '{broken'], ['missing field', v => { delete v.request_id; return JSON.stringify(v); }],
    ['record version', v => JSON.stringify({ ...v, version: 2 })], ['invalid token', v => JSON.stringify({ ...v, confirmation_token: 'bad' })],
    ['invalid UUID', v => JSON.stringify({ ...v, request_id: 'bad' })],
]) test('H corrupt storage: ' + name, async ({ context, page }) => {
    const corrupt = mutate(frozen()); await context.addInitScript(({ key, value, prefix }) => { sessionStorage.setItem(key, value); sessionStorage.setItem(prefix + 'hold', 'pending'); }, { key: recordKey, value: corrupt, prefix });
    await boot(page); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect(rig.issues).toBe(0); expect(rig.reconciles).toBe(0);
});
test('H unavailable sessionStorage getter does not crash', async ({ context, page }) => { await context.addInitScript(() => Object.defineProperty(window, 'sessionStorage', { get() { throw new DOMException('Synthetic denied', 'SecurityError'); } })); await boot(page); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect(rig.issues).toBe(0); });
test('H storage read failure fails closed', async ({ context, page }) => { await context.addInitScript(prefix => { const original = Storage.prototype.getItem; Storage.prototype.getItem = function(k) { if (k.startsWith(prefix)) throw new DOMException('Synthetic denied', 'SecurityError'); return original.call(this, k); }; }, prefix); await boot(page); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect(rig.issues).toBe(0); });
test('H initial storage write failure blocks issue', async ({ context, page }) => { await context.addInitScript(prefix => { const original = Storage.prototype.setItem; Storage.prototype.setItem = function(k, v) { if (k.startsWith(prefix)) throw new DOMException('Synthetic quota', 'QuotaExceededError'); return original.call(this, k, v); }; }, prefix); await submitPreview(page); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect(rig.issues).toBe(0); });
test('H attempted storage write failure prevents financial fetch', async ({ page }) => { await confirm(page); await page.evaluate(prefix => { const original = Storage.prototype.setItem; Storage.prototype.setItem = function(k, v) { if (k.startsWith(prefix) && !k.endsWith('hold') && JSON.parse(v).status === 'attempted') throw new DOMException('Synthetic quota', 'QuotaExceededError'); return original.call(this, k, v); }; }, prefix); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click(); await expect(page.getByRole('heading', { name: 'Perlu pemeriksaan manual' })).toBeVisible(); expect(rig.issues).toBe(0); });

test('I native Back Forward and out/back recheck fresh context', async ({ page }) => {
    await unknown(page); await page.getByRole('link', { name: 'Periksa daftar invoice', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Invoice layanan', exact: true })).toBeVisible();
    const before = rig.contexts; await page.goBack(); await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); expect(rig.contexts).toBeGreaterThan(before);
    await page.goForward(); await expect(page.getByRole('heading', { name: 'Invoice layanan', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Terbitkan invoice', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); expect(rig.issues).toBe(1);
});
test('I cached history cannot authorize changed session', async ({ page }) => { await unknown(page); await page.getByRole('link', { name: 'Periksa daftar invoice', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Invoice layanan', exact: true })).toBeVisible(); rig.contextValue = { ...ctx, session_context: 'e'.repeat(64) }; await page.goBack(); await expect(page.getByRole('heading', { name: /Akses sesi berubah|Perlu pemeriksaan manual/ })).toBeVisible(); expect(rig.reconciles).toBe(0); expect(rig.issues).toBe(1); });
test('I full navigation URL refresh renders HTML and restores pending', async ({ page }) => { await unknown(page); await page.goto(origin + base + '/create'); await expect(page.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); expect(rig.issues).toBe(1); });
test('I full navigation and reload preserve index and detail page types', async ({ page }) => { await page.goto(origin + base); await expect(page.getByRole('heading', { name: 'Invoice layanan', exact: true })).toBeVisible(); await page.reload(); await expect(page.getByRole('heading', { name: 'Invoice layanan', exact: true })).toBeVisible(); await page.goto(origin + issued.detail_url); await expect(page.getByRole('heading', { name: 'Detail invoice', exact: true })).toBeVisible(); await page.reload(); await expect(page.getByRole('heading', { name: 'Detail invoice', exact: true })).toBeVisible(); expect(rig.issues).toBe(0); });
test('I separate tab does not inherit per-tab pending storage', async ({ context, page }) => { await unknown(page); const other = await context.newPage(); await boot(other); await expect(other.getByRole('radio', { name: /Lansia fixture sintetis/ })).toBeEnabled(); expect((await storedFlags(other)).hasRecord).toBe(false); expect(rig.issues).toBe(1); });
test('I opener-cloned tab restores the same intent without issuing again', async ({ page }) => { await unknown(page); const opened = page.waitForEvent('popup'); await page.evaluate(() => window.open('/tests/Frontend/billing-browser.html', '_blank')); const popup = await opened; await expect(popup.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); await checkRecovery(popup); await expect(popup.getByRole('heading', { name: 'Intent menunggu kepastian' })).toBeVisible(); expect(rig.issues).toBe(1); expect(rig.reconciles).toBe(1); expect(rig.financial.every(item => item.original_tuple)).toBe(true); });
test('J controls disabled during financial request', async ({ page }) => { rig.delay = 900; await confirm(page); await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click(); await expect(page.getByRole('button', { name: 'Preview invoice', exact: true })).toBeDisabled(); await expect(page.getByRole('heading', { name: 'Menunggu hasil committed' })).toBeVisible(); await expect(page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' })).toBeVisible(); expect(rig.issues).toBe(1); });
test('J mobile confirmation stays usable inside viewport and preserves intent', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 }); await confirm(page);
    const dialog = page.getByRole('dialog'); const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    expect(bounds.height).toBeLessThanOrEqual(844);
    await expect(dialog.getByRole('button', { name: 'Konfirmasi dan terbitkan' })).toBeEnabled();
    await page.screenshot({ path: `docs/report/SILANSIA_P9_3C3B_MOBILE_DIALOG_${run}.png` });
    await dialog.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click();
    await expect(page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' })).toBeVisible();
    expect(rig.issues).toBe(1); expect(rig.financial[0].original_tuple).toBe(true);
});
