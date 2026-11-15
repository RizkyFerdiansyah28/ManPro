// Real Chrome -> Laravel -> owned PostgreSQL. Secrets travel only through pipes/memory.
import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const input = JSON.parse(raw); raw = '';
const { origin, sources, accounts, password } = input;
assert.equal(new URL(origin).hostname, '127.0.0.1');
assert.ok(!['8000', '5187', ''].includes(new URL(origin).port));
const database = process.env.SILANSIA_TEST_DB_DATABASE;
assert.match(database, /^silansia_test_[a-f0-9]{16}$/);
const run = process.env.P93D_EVIDENCE_RUN; assert.match(run, /^[a-f0-9]{16}$/);
const base = '/admin/billing/invoices';
const report = { level: 'real browser to Laravel and owned PostgreSQL', database, origin, cases: [], failed: 0, passed: 0 };
let browser; let stage = 'isolation';
let knownInvoiceId;
function control(body) {
    const result = spawnSync(process.env.P93D_PHP_BINARY, ['tests/Support/p93d-control.php'], { cwd: process.cwd(), env: process.env, input: JSON.stringify(body), encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(result.status, 0, 'Owned control failed');
    const parsed = JSON.parse(result.stdout); assert.equal(parsed.ok, true); return parsed;
}
const snapshot = resident => control({ action: 'snapshot', residents: Array.isArray(resident) ? resident : [resident] }).snapshot;
async function scenario(name, sourceName, action) {
    stage = name;
    const source = sources[sourceName];
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' });
    const facts = { name, status: 'running', requests: [], runtime_errors: 0, blocked_origins: 0, issue_posts: 0, reconcile_posts: 0 };
    const page = await context.newPage();
    let latestPreview; let original; let loss = '';
    page.on('pageerror', () => { facts.runtime_errors++; });
    await context.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (url.origin !== origin) { facts.blocked_origins++; return route.abort('blockedbyclient'); }
        if (url.pathname === base && request.method() === 'POST') {
            facts.issue_posts++; const body = request.postDataJSON(); original = body;
            const stored = await request.frame().evaluate(body => {
                const key = Object.keys(sessionStorage).find(k => k.startsWith('silansia.invoice.intent.v1:') && !k.endsWith('hold'));
                const value = key ? JSON.parse(sessionStorage.getItem(key)) : null;
                return value?.status === 'attempted' && value?.confirmation_token === body.confirmation_token && value?.request_id === body.request_id && value?.elderly_id === body.elderly_id && value?.billing_month === body.billing_month;
            }, body);
            facts.stored_before_post = stored; facts.original_preview_tuple = body.request_id === latestPreview?.confirmation.request_id && body.confirmation_token === latestPreview?.confirmation.token && body.elderly_id === source.resident && body.billing_month === source.month;
            assert.equal(stored, true); assert.equal(facts.original_preview_tuple, true);
            if (loss === 'uncommitted') return route.abort('failed');
            if (loss === 'committed' || loss === 'malformed') {
                const response = await route.fetch({ maxRedirects: 0 });
                facts.server_issue_status = response.status(); assert.equal(response.status(), 201);
                if (loss === 'malformed') return route.fulfill({ response, body: '{"malformed":true}' });
                return route.abort('failed');
            }
        }
        if (url.pathname === base + '/reconcile' && request.method() === 'POST') {
            facts.reconcile_posts++; const body = request.postDataJSON();
            facts.reconcile_original_tuple = body.request_id === original?.request_id && body.confirmation_token === original?.confirmation_token;
            assert.equal(facts.reconcile_original_tuple, true);
        }
        return route.continue();
    });
    page.on('response', async response => {
        if (new URL(response.url()).pathname === base + '/preview' && response.status() === 200) latestPreview = await response.json();
    });
    const api = async (path, method = 'GET', body, csrf = true) => {
        const headers = { Accept: 'application/json' };
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            if (csrf) { const cookie = (await context.cookies(origin)).find(c => c.name === 'XSRF-TOKEN'); if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.value); }
        }
        const response = await context.request.fetch(origin + path, { method, headers, ...(body ? { data: body } : {}), maxRedirects: 0, timeout: 20000 });
        facts.requests.push({ path: path.split('?')[0], method, status: response.status() });
        return response;
    };
    const login = async (account, active = true) => {
        await page.goto(origin + '/login');
        await page.locator('input[name="email"]').fill(account.email);
        await page.locator('input[name="password"]').fill(password);
        await page.getByRole('button', { name: /Masuk/ }).click();
        if (active) await page.waitForURL(url => url.pathname !== '/login');
        else await page.waitForTimeout(200);
    };
    const uiPreview = async () => {
        await page.goto(origin + base + '/create');
        await page.getByLabel('Cari nama lansia (termasuk nonaktif)').fill(source.name);
        await page.getByRole('button', { name: 'Cari lansia', exact: true }).click();
        await page.getByRole('radio', { name: new RegExp(source.name) }).check();
        await page.locator('#invoice-month').fill(source.month.slice(0, 7));
        await page.getByRole('checkbox', { name: /Saya sudah memastikan/ }).check();
        await page.getByRole('button', { name: 'Preview invoice', exact: true }).click();
        await page.getByRole('heading', { name: 'Hasil preview', exact: true }).waitFor();
        assert.ok(latestPreview); return latestPreview;
    };
    const uiIssue = async (mode = '') => {
        loss = mode;
        await page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' }).click();
        await page.getByRole('dialog').getByRole('checkbox').check();
        await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click();
        if (mode) await page.getByRole('heading', { name: 'Hasil penerbitan belum diketahui' }).waitFor();
        else await page.getByRole('heading', { name: 'Detail invoice', exact: true }).waitFor();
        return original;
    };
    try {
        await action({ page, context, source, facts, login, api, uiPreview, uiIssue, original: () => original });
        assert.equal(facts.runtime_errors, 0); assert.equal(facts.blocked_origins, 0);
        facts.status = 'PASS'; report.passed++;
    } catch (error) {
        facts.status = 'FAIL'; facts.error_class = error.name; report.failed++;
        throw error;
    } finally { report.cases.push(facts); await context.close(); }
}

try {
    const proof = await (await fetch(origin + '/__p93d/identity')).json();
    assert.equal(proof.environment, 'testing'); assert.equal(proof.database, database);
    assert.equal(proof.connection, 'silansia_testing'); assert.equal(proof.ownership_verified, true);
    assert.equal(proof.environment_file_exists, false); assert.equal(proof.csrf_test_bypass, false);
    report.isolation_verified_before_browser = true;
    browser = await chromium.launch({ channel: 'chrome', headless: true, chromiumSandbox: true });
    report.browser_version = browser.version();
    await scenario('real UI issue, replay, conflicting target and exact database facts', 'happy', async r => {
        await r.login(r.source.admin); const before = snapshot(r.source.resident);
        const preview = await r.uiPreview(); assert.equal(preview.calculation.amount, '3100.00'); assert.equal(snapshot(r.source.resident).roots, 0);
        const second = await r.api(base + '/preview', 'POST', { elderly_id: r.source.resident, billing_month: '2026-10-01' }); assert.equal(second.status(), 200);
        const other = await second.json(); const body = await r.uiIssue();
        const after = snapshot(r.source.resident); assert.equal(after.roots, 1); assert.equal(after.revision_count, 1); assert.equal(after.request_count, 1);
        knownInvoiceId = after.invoice_facts[0].id;
        assert.equal(after.invoice_facts[0].amount, '3100.00'); assert.equal(after.invoice_facts[0].bill_to_family_id, r.source.owner);
        assert.equal(before.financial_ownership_periods, after.financial_ownership_periods); assert.deepEqual(before.protected_domain, after.protected_domain);
        assert.equal((await r.api(base, 'POST', body)).status(), 200);
        assert.equal((await r.api(base, 'POST', { ...body, request_id: other.confirmation.request_id, confirmation_token: other.confirmation.token })).status(), 409);
        assert.deepEqual(snapshot(r.source.resident), after); r.facts.database_consistent = true;
        await r.page.screenshot({ path: `docs/report/SILANSIA_P9_3D_REAL_DETAIL_${run}.png`, fullPage: true });
    });
    await scenario('committed response lost, refresh and real durable reconciliation', 'lost', async r => {
        await r.login(r.source.admin); await r.uiPreview(); await r.uiIssue('committed');
        const committed = snapshot(r.source.resident); assert.equal(committed.roots, 1);
        await r.page.reload(); await r.page.getByRole('heading', { name: 'Intent menunggu kepastian' }).waitFor();
        await r.page.getByRole('button', { name: 'Periksa hasil intent yang sama' }).click();
        await r.page.getByRole('heading', { name: 'Detail invoice', exact: true }).waitFor();
        assert.equal(r.facts.issue_posts, 1); assert.equal(r.facts.reconcile_posts, 1); assert.deepEqual(snapshot(r.source.resident), committed);
        r.facts.commit_and_original_replay_proven = true;
    });
    await scenario('not delivered issue remains unresolved and never creates initial invoice on reconcile', 'uncommitted', async r => {
        await r.login(r.source.admin); await r.uiPreview(); const before = snapshot(r.source.resident); await r.uiIssue('uncommitted');
        await r.page.reload(); await r.page.getByRole('heading', { name: 'Intent menunggu kepastian' }).waitFor();
        await r.page.getByRole('button', { name: 'Periksa hasil intent yang sama' }).click();
        await r.page.getByRole('heading', { name: 'Intent menunggu kepastian' }).waitFor();
        assert.deepEqual(snapshot(r.source.resident), before); assert.equal(r.facts.issue_posts, 1); r.facts.database_unchanged = true;
    });
    await scenario('zero charge is audited without synthetic Payment', 'zero', async r => {
        await r.login(r.source.admin); assert.equal((await r.uiPreview()).calculation.amount, '0.00'); await r.uiIssue();
        const after = snapshot(r.source.resident); assert.equal(after.roots, 1); assert.equal(after.payments_count, 0); assert.equal(after.invoice_facts[0].status, 'paid');
        assert.equal(after.action_counts.find(a => a.action === 'zero_charge')?.count, 1); r.facts.zero_charge_without_payment = true;
    });
    for (const [name, expected] of [['null_price', 'HOLD'], ['no_service', 'SKIP'], ['mixed', 'HOLD'], ['future', 'REJECT']]) await scenario('real eligibility ' + name + ' ' + expected, name, async r => {
        await r.login(r.source.admin); const before = snapshot(r.source.resident); assert.equal((await r.uiPreview()).decision, expected);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.database_unchanged = true;
    });
    await scenario('inactive resident valid historical service remains issuable', 'inactive_history', async r => { await r.login(r.source.admin); assert.equal((await r.uiPreview()).decision, 'ISSUE'); await r.uiIssue(); assert.equal(snapshot(r.source.resident).roots, 1); });
    await scenario('unauthorized roles and guest cannot read issue or reconcile', 'tamper', async r => {
        const protectedResidents = [r.source.resident, sources.happy.resident];
        const before = snapshot(protectedResidents);
        const paths = [['GET', base], ['GET', base + '/create'], ['GET', base + '/resident-options'], ['GET', base + '/' + knownInvoiceId],
            ['POST', base + '/preview'], ['POST', base], ['POST', base + '/reconcile']];
        await r.page.goto(origin + '/login');
        for (const [method, path] of paths) assert.equal((await r.api(path, method, method === 'POST' ? {} : undefined)).status(), 401);
        for (const account of [r.source.family, accounts.caregiver]) {
            await r.login(account);
            for (const [method, path] of paths) {
                const response = await r.api(path, method, method === 'POST' ? {} : undefined);
                assert.equal(response.status(), 403); assert.equal((await response.text()).includes(knownInvoiceId), false);
            }
            await r.page.getByRole('button', { name: 'Keluar', exact: true }).click(); await r.page.waitForURL(origin + '/login');
        }
        await r.login(accounts.inactive, false);
        assert.ok([401, 403].includes((await r.api(base)).status()));
        assert.deepEqual(snapshot(protectedResidents), before); r.facts.database_unchanged = true;
    });
    await scenario('real validators and encrypted token reject tampering without financial writes', 'token', async r => {
        await r.login(r.source.admin); const p = await r.uiPreview();
        const body = { elderly_id: r.source.resident, billing_month: '2026-10-01', request_id: p.confirmation.request_id, confirmation_token: p.confirmation.token, confirm: true };
        const before = snapshot(r.source.resident);
        for (const bad of [{ ...body, amount: '1.00' }, { ...body, elderly_id: 'not-a-uuid' }, { ...body, billing_month: '2026-10-02' }, { ...body, confirmation_token: 'invalid' }, { ...body, service_enrollment_id: r.source.resident }]) assert.equal((await r.api(base, 'POST', bad)).status(), 422);
        const originalReconcile = { ...body }; delete originalReconcile.confirm;
        assert.equal((await r.api(base + '/reconcile', 'POST', { ...originalReconcile, elderly_id: sources.happy.resident })).status(), 422);
        assert.equal((await r.api(base + '/reconcile', 'POST', { ...originalReconcile, billing_month: '2026-09-01' })).status(), 422);
        assert.equal((await r.api(base + '/not-a-uuid')).status(), 404);
        const wrong = control({ action: 'token_fixture', kind: 'audience', token: body.confirmation_token }).token;
        assert.equal((await r.api(base + '/reconcile', 'POST', { ...body, confirm: undefined, confirmation_token: wrong })).status(), 422);
        const expired = control({ action: 'token_fixture', kind: 'expired', token: body.confirmation_token }).token;
        assert.equal((await r.api(base, 'POST', { ...body, confirmation_token: expired })).status(), 409);
        const reconcile = { ...body, confirmation_token: expired }; delete reconcile.confirm;
        assert.equal((await r.api(base + '/reconcile', 'POST', reconcile)).status(), 409);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.database_unchanged = true;
    });
    await scenario('real CSRF missing invalid and valid requests use actual middleware', 'csrf', async r => {
        await r.login(r.source.admin); const before = snapshot(r.source.resident); const body = { elderly_id: r.source.resident, billing_month: '2026-10-01' };
        assert.equal((await r.api(base + '/preview', 'POST', body, false)).status(), 419);
        const invalid = await r.context.request.post(origin + base + '/preview', { headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'synthetic-invalid' }, data: body });
        assert.equal(invalid.status(), 419); assert.equal((await r.api(base + '/preview', 'POST', body)).status(), 200);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.real_csrf_enforced = true;
    });
    await scenario('actual session expiry and same-account rotation reject client recovery', 'session', async r => {
        await r.login(r.source.admin); await r.uiPreview(); await r.uiIssue('committed'); const before = snapshot(r.source.resident);
        control({ action: 'expire_session', actor: r.source.admin.id });
        await r.page.getByRole('button', { name: 'Periksa hasil intent yang sama' }).click(); await r.page.getByRole('heading', { name: 'Akses sesi berubah' }).waitFor();
        await r.login(r.source.admin); await r.page.goto(origin + base + '/create'); await r.page.getByRole('heading', { name: 'Perlu pemeriksaan manual' }).waitFor();
        assert.deepEqual(snapshot(r.source.resident), before); assert.equal(r.facts.reconcile_posts, 0); r.facts.session_boundary_proven = true;
    });
    await scenario('wrong actor token rejected under another authenticated Admin', 'audience', async r => {
        await r.login(r.source.admin); const p = await r.uiPreview(); const before = snapshot(r.source.resident);
        await r.page.getByRole('button', { name: 'Keluar', exact: true }).click(); await r.page.waitForURL(origin + '/login');
        const prior = { elderly_id: r.source.resident, billing_month: '2026-10-01', request_id: p.confirmation.request_id, confirmation_token: p.confirmation.token };
        assert.equal((await r.api(base, 'POST', { ...prior, confirm: true })).status(), 401);
        assert.equal((await r.api(base + '/reconcile', 'POST', prior)).status(), 401);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.financial_posts_after_logout_denied = true;
        await r.login(accounts.other_admin);
        assert.equal((await r.api(base + '/reconcile', 'POST', { elderly_id: r.source.resident, billing_month: '2026-10-01', request_id: p.confirmation.request_id, confirmation_token: p.confirmation.token })).status(), 422);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.database_unchanged = true;
    });
    await scenario('malformed committed acknowledgement still reconciles the same durable result', 'malformed', async r => {
        await r.login(r.source.admin); await r.uiPreview(); await r.uiIssue('malformed'); const before = snapshot(r.source.resident); assert.equal(before.roots, 1);
        await r.page.reload(); await r.page.getByRole('heading', { name: 'Intent menunggu kepastian' }).waitFor();
        await r.page.getByRole('button', { name: 'Periksa hasil intent yang sama' }).click(); await r.page.getByRole('heading', { name: 'Detail invoice', exact: true }).waitFor();
        assert.deepEqual(snapshot(r.source.resident), before); assert.equal(r.facts.issue_posts, 1); r.facts.original_durable_result = true;
    });
    await scenario('simultaneous HTTP reconciliation returns the same existing graph', 'concurrent', async r => {
        await r.login(r.source.admin); await r.uiPreview(); const body = await r.uiIssue('committed'); const before = snapshot(r.source.resident);
        const command = { ...body }; delete command.confirm;
        const responses = await Promise.all([r.api(base + '/reconcile', 'POST', command), r.api(base + '/reconcile', 'POST', command)]);
        assert.ok(responses.every(response => response.status() === 200));
        const data = await Promise.all(responses.map(response => response.json())); assert.equal(data[0].invoice_id, data[1].invoice_id);
        assert.deepEqual(snapshot(r.source.resident), before); r.facts.same_graph = true;
    });
} catch (error) {
    report.ok = false; report.failed = Math.max(report.failed, 1); report.failure_stage = stage; report.error_class = error.name;
} finally {
    if (browser) await browser.close();
    report.ok = report.failed === 0;
    await writeFile(`docs/report/SILANSIA_P9_3D_REAL_BROWSER_${run}.json`, JSON.stringify(report, null, 2));
    process.stdout.write(JSON.stringify({ ok: report.ok, passed: report.passed, failed: report.failed, stage: report.failure_stage ?? 'complete' }));
    process.exitCode = report.ok ? 0 : 1;
}
