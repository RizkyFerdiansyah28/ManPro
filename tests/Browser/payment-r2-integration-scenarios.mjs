import assert from 'node:assert/strict';
import { setTimeout as pause } from 'node:timers/promises';
import { sha256 } from './payment-r2-integration-owned.mjs';

const familyPage = s => '/keluarga/tagihan/' + s.invoice;
const familyApi = s => '/keluarga/billing/invoices/' + s.invoice;
const adminPage = (s, p) => '/admin/pembayaran/' + s.invoice + '/' + p;
const adminApi = (s, p) => '/admin/billing/invoices/' + s.invoice + '/payments/' + p;
const proofApi = (s, p) => '/billing/invoices/' + s.invoice + '/payments/' + p + '/proof';
const recoveryPath = '/billing/payment-commands/reconcile';
const openProof = 'Buka bukti pembayaran simulasi pengajuan 1';
const recoverLabel = 'Periksa hasil tindakan yang sama';
const publicReason = 'Bukti sintetis belum dapat dikonfirmasi.';
const privateNote = 'CATATAN-PRIVAT-SINTETIS-R2';
const exact = (page, role, name) => page.getByRole(role, { name, exact: true });
const button = (page, name) => exact(page, 'button', name);
const heading = (page, name) => exact(page, 'heading', name).waitFor();

function awaitResponse(c, path, method) {
    return c.page.waitForResponse(response => new URL(response.url()).pathname === path && response.request().method() === method);
}

async function familyDetail(c, source, ready = true) {
    await c.open(familyPage(source));
    await c.page.getByText(source.label, { exact: true }).waitFor();
    if (ready) await c.page.waitForFunction(() => document.querySelector('#payment-proof')?.disabled === false);
}

async function fillProof(page, image) {
    await page.locator('#payment-proof').setInputFiles(image);
    await page.getByRole('checkbox', { name: /Saya mengonfirmasi bukti transfer simulasi/ }).check();
}

async function submit(c, s, effect = 'Tindakan tercatat', expected = 201) {
    const watch = expected === null ? null : awaitResponse(c, familyApi(s) + '/payments', 'POST');
    const start = { writes: c.net.writes, contexts: c.net.contexts };
    await fillProof(c.page, c.image);
    assert.equal(c.net.writes, start.writes);
    assert.equal(c.net.contexts, start.contexts);
    await button(c.page, 'Kirim bukti pembayaran').click();
    await heading(c.page, effect);
    assert.equal(c.net.writes, start.writes + 1);
    const response = watch && await watch;
    if (response) assert.equal(response.status(), expected);
    return response ? response.json() : null;
}

function graphFor(snapshot, payment) {
    const active = snapshot.scanner.graph.filter(v => v.payment_id === payment && v.job_generation === v.generation);
    assert.equal(active.length, 1);
    const graph = active[0];
    assert.equal(graph.descriptor_bound, true);
    assert.equal(graph.stage_id, 'synthetic_stage_v1');
    assert.equal(graph.policy_id, snapshot.scanner.head.policy_id);
    assert.equal(graph.policy_epoch, snapshot.scanner.head.policy_epoch);
    return graph;
}

function initialFacts(before, after, fact, family) {
    assert.equal(fact.command_fact, 'submission_recorded');
    assert.equal(fact.replayed, false);
    assert.equal(after.financial.invoice.status, 'unpaid');
    assert.deepEqual(after.financial.revisions, before.financial.revisions);
    assert.equal(after.financial.payments.length, before.financial.payments.length + 1);
    const payment = after.financial.payments.find(p => p.id === fact.payment_id);
    assert.equal(payment?.status, 'pending');
    assert.equal(payment.amount, fact.amount);
    assert.equal(payment.invoice_revision_id, fact.revision_id);
    assert.equal(after.financial.requests.length, before.financial.requests.length + 1);
    assert.ok(after.financial.requests.some(r => r.operation === 'submit' && r.actor_id === family
        && r.outcome_payment_id === payment.id && r.outcome_revision_id === fact.revision_id));
    assert.equal(after.audit.actions.length, before.audit.actions.length + 1);
    assert.ok(after.audit.actions.some(a => a.action === 'payment_submitted' && a.payment_id === payment.id && a.actor_id === family));
    const initial = graphFor(after, fact.payment_id);
    assert.equal(initial.state, 'QUEUED');
    assert.equal(initial.job_state, 'READY');
    assert.equal(initial.attempt_id, null);
    assert.equal(initial.verdict, null);
    for (const kind of ['initial_subject_bound', 'job_created']) {
        assert.ok(after.audit.proof.some(a => a.event_type === kind && a.payment_id === payment.id && a.descriptor_bound));
    }
    assert.deepEqual(after.protected, before.protected);
    return payment.id;
}

async function pending(h, c, s) {
    await familyDetail(c, s);
    const before = await h.observe(s);
    const fact = await submit(c, s);
    const after = await h.observe(s);
    return initialFacts(before, after, fact, h.fixture.accounts.a.id);
}

async function scan(h, s, p, expected = 'clean') {
    const before = await h.observe(s);
    const { scan: result } = await h.control('scan', s.invoice, p);
    const after = await h.observe(s);
    assert.deepEqual(after.financial, before.financial);
    assert.deepEqual(after.audit.actions, before.audit.actions);
    assert.deepEqual(after.protected, before.protected);
    const active = graphFor(after, p);
    if (expected === 'off') {
        assert.equal(result.accepted, false);
        assert.deepEqual(after, before);
        return;
    }
    assert.equal(result.accepted, true);
    assert.equal(active.state, { clean: 'CLEAN_ELIGIBLE', infected: 'INFECTED_HELD', unknown: 'UNKNOWN_HELD' }[expected]);
    assert.equal(active.verdict, expected);
    assert.equal(active.verdict_bound, true);
    assert.equal(active.attempt_generation, active.generation);
    assert.equal(active.verdict_generation, active.generation);
    assert.equal(active.attempt_fence, active.fence);
    assert.equal(active.verdict_fence, active.fence);
    assert.ok(after.audit.proof.length > before.audit.proof.length);
}

function grantCount(state, payment, event) {
    return state.audit.proof.filter(a => a.payment_id === payment && a.event_type === event).length;
}

function grantDelta(before, after, payment, kind, actor) {
    assert.equal(grantCount(after, payment, kind), grantCount(before, payment, kind) + 1);
    const graph = graphFor(after, payment);
    assert.ok(after.audit.proof.some(a => a.payment_id === payment && a.event_type === kind && a.actor === actor
        && a.bound && a.descriptor_bound && a.policy_bound && a.generation === graph.generation && a.fence === graph.fence));
}

async function preview(h, c, s, p, actor, family) {
    const before = await h.observe(s);
    assert.equal(c.net.proofs, 0);
    const response = awaitResponse(c, proofApi(s, p), 'GET');
    await button(c.page, family ? openProof : 'Buka bukti transfer').click();
    const blob = c.page.locator('[data-payment-proof]');
    await blob.waitFor();
    const url = await blob.getAttribute('src');
    assert.match(url, /^blob:/);
    const res = await response;
    assert.equal(res.status(), 200);
    assert.equal(sha256(await res.body()), sha256(c.image.buffer));
    assert.equal(res.headers()['content-type'], 'image/png');
    assert.match(res.headers()['content-disposition'], /attachment; filename="bukti\.png"/);
    for (const token of ['no-store', 'private']) assert.ok(res.headers()['cache-control'].includes(token));
    assert.equal(res.headers()['x-content-type-options'], 'nosniff');
    assert.equal(res.headers()['referrer-policy'], 'no-referrer');
    await button(c.page, family ? 'Tutup bukti pembayaran simulasi' : 'Tutup bukti transfer').click();
    await blob.waitFor({ state: 'detached' });
    assert.equal(await c.page.evaluate(u => fetch(u).then(() => false, () => true), url), true);
    const after = await h.observe(s);
    grantDelta(before, after, p, 'protected_get_granted', actor);
    assert.deepEqual(after.financial, before.financial);
    assert.deepEqual(after.scanner, before.scanner);
    assert.deepEqual(after.protected, before.protected);
}

async function review(c, s, p, operation, effect = 'Tindakan tercatat', status = 200) {
    const contextCount = c.net.contexts;
    await button(c.page, operation === 'verify' ? 'Tinjau verifikasi' : 'Tolak pengajuan simulasi').click();
    const dialog = c.page.getByRole('dialog');
    const decision = button(dialog, operation === 'verify' ? 'Konfirmasi dan verifikasi' : 'Konfirmasi dan tolak');
    assert.equal(await decision.isDisabled(), true);
    if (operation === 'verify') {
        await dialog.getByRole('checkbox', { name: /Saya telah memeriksa receipt dummy/ }).check();
    } else {
        await dialog.getByLabel('Kode alasan').fill('receipt_not_confirmed');
        await dialog.getByLabel(/Alasan untuk keluarga/).fill(publicReason);
        await dialog.getByLabel(/Catatan privat Admin/).fill(privateNote);
    }
    await dialog.getByRole('checkbox', { name: /Saya mengonfirmasi tindakan ini/ }).check();
    assert.equal(c.net.contexts, contextCount);
    const response = status === null ? null : awaitResponse(c, adminApi(s, p) + '/' + operation, 'POST');
    await decision.click();
    await heading(c.page, effect);
    const res = response && await response;
    if (res) assert.equal(res.status(), status);
    return res ? res.json() : null;
}

async function marker(page) {
    const value = await page.evaluate(() => ({
        shared: Object.entries(localStorage).filter(([k]) => k.startsWith('silansia.payment.')),
        legacy: Object.entries(sessionStorage).filter(([k]) => k.startsWith('silansia.payment.')),
    }));
    for (const [k, v] of [...value.shared, ...value.legacy]) {
        assert.match(k, /^silansia\.payment\.hold\.v1:[a-f0-9]{64}$/);
        assert.equal(v, '1');
    }
    return value;
}

async function noPersistedMaterial(c, needles) {
    const present = await c.page.evaluate(values => {
        const browserStores = JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]);
        return values.some(v => browserStores.includes(v));
    }, needles.filter(v => typeof v === 'string' && v.length > 15));
    assert.equal(present, false);
}

async function openAdmin(c, s, p) {
    await c.open(adminPage(s, p));
    await c.page.getByText(s.label, { exact: true }).waitFor();
}

async function family(h) {
    const { primary, foreign, zero } = h.fixture.sources;
    const a = await h.client('a');
    const b = await h.client('b');
    await a.login();
    await b.login();
    await a.open('/keluarga/tagihan');
    await a.page.getByText(primary.label, { exact: true }).waitFor();
    assert.equal(await a.page.getByText(foreign.label, { exact: true }).count(), 0);
    await b.open('/keluarga/tagihan');
    await b.page.getByText(foreign.label, { exact: true }).waitFor();
    assert.equal(await b.page.getByText(primary.label, { exact: true }).count(), 0);
    const p = await pending(h, a, primary);
    await a.page.getByText(/Menunggu verifikasi Admin\. Tagihan belum dinyatakan lunas/).waitFor();
    await scan(h, primary, p);
    await preview(h, a, primary, p, h.fixture.accounts.a.id, true);
    const zeroBefore = await h.observe(zero);
    await familyDetail(a, zero, false);
    await a.page.getByText('Tagihan tanpa biaya. Bukti pembayaran tidak diperlukan.').waitFor();
    assert.equal(await a.page.locator('#payment-proof').count(), 0);
    assert.equal((await a.api(familyApi(zero) + '/payment-context')).status(), 409);
    assert.equal(zeroBefore.financial.invoice.status, 'paid');
    assert.equal(zeroBefore.financial.invoice.amount, '0.00');
    assert.ok(zeroBefore.audit.actions.some(row => row.action === 'zero_charge'));
    assert.equal(zeroBefore.financial.payments.length + zeroBefore.scanner.graph.length, 0);
    assert.deepEqual(await h.observe(zero), zeroBefore);
    h.checked(1, 2, 3, 4, 5, 8);
}

async function admin(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    const p = await pending(h, a, s);
    await scan(h, s, p);
    const c = await h.client('admin');
    await c.login();
    await c.open('/admin/pembayaran');
    await c.page.getByText(s.label, { exact: true }).waitFor();
    await openAdmin(c, s, p);
    await preview(h, c, s, p, h.fixture.accounts.admin.id, false);
    const before = await h.observe(s);
    const ctx = await c.api(adminApi(s, p) + '/review-context?operation=verify');
    assert.equal(ctx.status(), 200);
    assert.equal((await ctx.json()).expires_in, 300);
    assert.deepEqual(await h.observe(s), before);
    const fact = await review(c, s, p, 'verify');
    assert.equal(fact.command_fact, 'verification_recorded');
    assert.equal(fact.replayed, false);
    assert.equal(fact.payment_id, p);
    const after = await h.observe(s);
    grantDelta(before, after, p, 'verify_use_granted', h.fixture.accounts.admin.id);
    assert.equal(after.financial.invoice.status, 'paid');
    assert.equal(after.financial.payments[0].status, 'verified');
    assert.equal(after.financial.requests.length, before.financial.requests.length + 1);
    assert.equal(after.audit.actions.length, before.audit.actions.length + 1);
    assert.deepEqual(after.scanner, before.scanner);
    assert.deepEqual(after.protected, before.protected);
    h.checked(9, 10, 11, 12, 13, 14, 31);
}

async function reject(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    const p = await pending(h, a, s);
    const c = await h.client('admin');
    await c.login();
    await openAdmin(c, s, p);
    const before = await h.observe(s);
    assert.equal(graphFor(before, p).state, 'QUEUED');
    const fact = await review(c, s, p, 'reject');
    assert.equal(fact.command_fact, 'rejection_recorded');
    const after = await h.observe(s);
    assert.equal(after.financial.invoice.status, 'unpaid');
    assert.equal(after.financial.payments[0].status, 'rejected');
    assert.deepEqual(after.audit.proof, before.audit.proof);
    assert.deepEqual(after.scanner, before.scanner);
    assert.ok(after.audit.actions.some(row => row.action === 'payment_rejected' && row.payment_id === p && row.synthetic_note_matches));
    assert.equal(c.net.proofs, 0);
    await noPersistedMaterial(c, [privateNote, c.hooks.context]);
    await familyDetail(a, s);
    await a.page.getByText('Alasan untuk keluarga: ' + publicReason, { exact: true }).waitFor();
    assert.ok(!(await a.page.locator('body').innerText()).includes(privateNote));
    const json = await a.api(familyApi(s));
    assert.ok(!(await json.text()).includes(privateNote));
    const replacement = await submit(a, s);
    assert.notEqual(initialFacts(after, await h.observe(s), replacement, h.fixture.accounts.a.id), p);
    h.checked(6, 7, 15, 16);
}

async function negative(h, mode) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    if (mode === 'no-policy') {
        await familyDetail(a, s);
        const before = await h.observe(s);
        assert.equal(before.scanner.head, null);
        await submit(a, s, 'Hasil tindakan belum diketahui', 503);
        assert.deepEqual(await h.observe(s), before);
        return;
    }
    const p = await pending(h, a, s);
    if (mode === 'held') await scan(h, s, p, 'unknown');
    else if (mode === 'infected') await scan(h, s, p, 'infected');
    else if (mode === 'scanner-off') await scan(h, s, p, 'off');
    else if (mode === 'stale' || mode === 'missing') {
        await scan(h, s, p);
        await h.control(mode, s.invoice, p);
    } else if (['missing-verdict', 'stale-envelope', 'wrong-subject', 'wrong-generation'].includes(mode)) {
        if (mode === 'stale-envelope') {
            await scan(h, s, p);
            await h.control('stale', s.invoice, p);
        }
        const result = (await h.control('scanner-denial', s.invoice, p)).denial;
        assert.equal(mode === 'missing-verdict' ? result.missing_current_verdict : result.binding_rejected && result.graph_unchanged, true);
    }
    const before = await h.observe(s);
    assert.equal(before.financial.payments[0].status, 'pending');
    const c = await h.client('admin');
    await c.login();
    await openAdmin(c, s, p);
    const denied = awaitResponse(c, proofApi(s, p), 'GET');
    await button(c.page, 'Buka bukti transfer').click();
    const proof = await denied;
    assert.equal(proof.status(), 503);
    assert.equal((await proof.json()).error.code, 'proof_use_unavailable');
    assert.ok(!proof.headers()['content-disposition']);
    assert.equal(await c.page.locator('[data-payment-proof]').count(), 0);
    assert.equal((await marker(c.page)).shared.length, 0);
    const failure = await review(c, s, p, 'verify', 'Hasil tindakan belum diketahui', 503);
    assert.equal(failure.error.code, 'proof_use_unavailable');
    assert.equal(failure.financial_outcome, 'undisclosed');
    assert.equal((await marker(c.page)).shared.length, 1);
    assert.deepEqual(await h.observe(s), before);
    h.checked(19, 37);
}

async function unknown(h, mode) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    await familyDetail(a, s);
    const before = await h.observe(s);
    const timed = mode.startsWith('timeout-');
    const lost = mode === 'ack-lost' || mode === 'timeout-committed';
    a.hooks.loss = lost ? 'committed' : 'absent';
    let completed;
    if (timed) {
        const result = Promise.withResolvers();
        completed = result.promise;
        a.hooks.waitForAck = async () => {
            await pause(16200);
            result.resolve();
        };
    }
    await submit(a, s, 'Hasil tindakan belum diketahui', null);
    if (completed) await completed;
    const after = await h.observe(s);
    assert.equal(after.financial.payments.length, lost ? 1 : 0);
    assert.equal(after.financial.requests.length, before.financial.requests.length + Number(lost));
    assert.equal(a.net.shipped, Number(lost));
    assert.equal((await marker(a.page)).shared.length, 1);
    assert.equal(a.net.reconciles + a.net.recoveries, 0);
    await noPersistedMaterial(a, [a.hooks.context, h.fixture.proof, privateNote]);
    if (mode === 'reload' || mode === 'session') {
        if (mode === 'session') {
            const other = await a.ctx.newPage();
            await other.goto(a.origin + familyPage(s));
            await other.getByText(s.label, { exact: true }).waitFor();
            await button(a.page, 'Keluar').click();
            await a.page.waitForURL(a.origin + '/login');
            await heading(other, 'Akses sesi berubah');
            await a.login();
            await a.open(familyPage(s));
            const fresh = await a.api('/billing/payment-commands/recovery-context');
            assert.equal(fresh.status(), 200);
            const old = await a.api(recoveryPath, { method: 'POST', body: {
                command_context: a.hooks.context, recovery_context: (await fresh.json()).recovery_context,
            } });
            assert.equal(old.status(), 422);
            assert.equal((await old.json()).error.code, 'context_invalid');
        } else await a.page.reload();
        await heading(a.page, 'Perlu pemeriksaan manual');
        assert.equal(await button(a.page, recoverLabel).count(), 0);
        assert.equal(a.net.reconciles + a.net.recoveries, 0);
        assert.equal((await marker(a.page)).shared.length, 1);
        h.checked(mode === 'session' ? 23 : 22, ...(mode === 'session' ? [24] : []));
    } else {
        const watched = awaitResponse(a, recoveryPath, 'POST');
        await button(a.page, recoverLabel).click();
        const response = await watched;
        assert.equal(response.status(), lost ? 200 : 409);
        const result = await response.json();
        if (lost) {
            assert.equal(result.payment_id, after.financial.payments[0].id);
            assert.equal(result.replayed, true);
        } else assert.equal(result.error.code, 'outcome_unresolved');
        await heading(a.page, lost ? 'Hasil tindakan ditemukan' : 'Hasil belum dapat dipastikan');
        assert.equal(a.net.reconciles, 1);
        assert.equal(a.net.recoveries, 1);
        assert.equal((await marker(a.page)).shared.length, lost ? 0 : 1);
        h.checked(21);
    }
    assert.equal(a.net.writes, 1);
    assert.deepEqual(await h.observe(s), after);
    h.checked(20, 34, ...(timed ? [18] : []));
}

async function tabs(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    await familyDetail(a, s);
    const second = await a.ctx.newPage();
    await second.goto(a.origin + familyPage(s));
    await second.waitForFunction(() => document.querySelector('#payment-proof')?.disabled === false);
    assert.equal(await a.page.evaluate(() => typeof navigator.locks?.request), 'function');
    const start = await marker(a.page);
    assert.deepEqual(start, { shared: [], legacy: [] });
    const before = await h.observe(s);
    a.hooks.loss = 'absent';
    const entered = Promise.withResolvers();
    const resume = Promise.withResolvers();
    a.hooks.waitForContext = () => {
        entered.resolve();
        return resume.promise;
    };
    try {
        await fillProof(a.page, a.image);
        await fillProof(second, a.image);
        await button(a.page, 'Kirim bukti pembayaran').click();
        await Promise.race([entered.promise, pause(8000).then(() => { if (a.net.contexts === 0) throw new Error('first_context_not_entered'); })]);
        assert.equal((await marker(second)).shared.length, 0);
        await button(second, 'Kirim bukti pembayaran').click();
        await second.getByText(/sedang diproses di tab lain/).waitFor();
        assert.equal(a.net.contexts, 1);
        assert.equal(a.net.writes, 0);
    } finally {
        resume.resolve();
    }
    await heading(a.page, 'Hasil tindakan belum diketahui');
    await heading(second, 'Perlu pemeriksaan manual');
    assert.equal(await button(second, recoverLabel).count(), 0);
    assert.deepEqual((await marker(second)).shared, (await marker(a.page)).shared);
    const [[key]] = (await marker(second)).shared;
    // Use a fresh profile for the legacy marker case. Never clear an unresolved shared marker.
    const legacy = await h.client('a');
    await legacy.login();
    await legacy.page.addInitScript(key => sessionStorage.setItem(key, '1'), key);
    await legacy.open(familyPage(s));
    await heading(legacy.page, 'Perlu pemeriksaan manual');
    assert.equal((await marker(legacy.page)).legacy.length, 1);
    assert.equal((await marker(legacy.page)).shared.length, 1);
    assert.equal(legacy.net.writes, 0);
    await a.page.close();
    await second.reload();
    await heading(second, 'Perlu pemeriksaan manual');
    assert.equal(a.net.contexts, 1);
    assert.equal(a.net.writes, 1);
    assert.equal(a.net.shipped, 0);
    assert.deepEqual(await h.observe(s), before);
    await second.close();
    h.checked(25);
}

async function unavailable(h, capability) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a', capability);
    await a.login();
    await familyDetail(a, s, false);
    await heading(a.page, 'Pembayaran belum tersedia');
    assert.equal(await a.page.locator('#payment-proof').isDisabled(), true);
    assert.equal(a.net.writes + a.net.contexts, 0);
}

async function adminReplay(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    const p = await pending(h, a, s);
    await scan(h, s, p);
    const c = await h.client('admin');
    await c.login();
    await openAdmin(c, s, p);
    const before = await h.observe(s);
    c.hooks.loss = 'committed';
    await review(c, s, p, 'verify', 'Hasil tindakan belum diketahui', null);
    const committed = await h.observe(s);
    assert.equal(committed.financial.invoice.status, 'paid');
    grantDelta(before, committed, p, 'verify_use_granted', h.fixture.accounts.admin.id);
    await h.control('stale', s.invoice, p);
    const proofUnavailable = await c.api(proofApi(s, p));
    assert.equal(proofUnavailable.status(), 503);
    assert.equal((await proofUnavailable.json()).error.code, 'proof_use_unavailable');
    const after = await h.observe(s);
    assert.deepEqual(after.financial, committed.financial);
    assert.equal(grantCount(after, p, 'verify_use_granted'), 1);
    const replied = awaitResponse(c, recoveryPath, 'POST');
    await button(c.page, recoverLabel).click();
    const response = await replied;
    assert.equal(response.status(), 200);
    const canonical = await response.json();
    assert.equal(canonical.replayed, true);
    assert.equal(canonical.command_fact, 'verification_recorded');
    assert.equal(canonical.payment_id, p);
    await heading(c.page, 'Hasil tindakan ditemukan');
    assert.equal(c.net.writes, 1);
    assert.equal(c.net.reconciles, 1);
    assert.equal(c.net.recoveries, 1);
    assert.equal(c.net.proofs, 0);
    assert.deepEqual(await h.observe(s), after);
    h.checked(17);
}

async function expired(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.login();
    await familyDetail(a, s);
    const before = await h.observe(s);
    a.hooks.denyContext = true;
    await fillProof(a.page, a.image);
    await button(a.page, 'Kirim bukti pembayaran').click();
    await heading(a.page, 'Pembayaran belum tersedia');
    assert.equal(a.net.writes, 0);
    assert.equal((await marker(a.page)).shared.length, 0);
    a.hooks.denyContext = false;
    await button(a.page, 'Periksa akses kembali').click();
    a.hooks.expireCommand = true;
    const failure = await submit(a, s, 'Data berubah atau hasil perlu diperiksa', 409);
    assert.equal(failure.error.code, 'context_expired');
    assert.equal((await marker(a.page)).shared.length, 1);
    assert.equal(a.net.recoveries, 0);
    const original = a.hooks.context;
    a.hooks.expireRecovery = true;
    let watched = awaitResponse(a, recoveryPath, 'POST');
    await button(a.page, recoverLabel).click();
    let response = await watched;
    assert.equal(response.status(), 409);
    assert.equal((await response.json()).error.code, 'context_expired');
    await heading(a.page, 'Hasil tindakan belum diketahui');
    const expiredRecovery = a.hooks.recovery;
    assert.equal((await marker(a.page)).shared.length, 1);
    assert.equal(a.net.recoveries, 1);
    a.hooks.expireRecovery = false;
    watched = awaitResponse(a, recoveryPath, 'POST');
    await button(a.page, recoverLabel).click();
    response = await watched;
    assert.equal(response.status(), 409);
    assert.equal((await response.json()).error.code, 'outcome_unresolved');
    await heading(a.page, 'Hasil belum dapat dipastikan');
    assert.notEqual(a.hooks.recovery, expiredRecovery);
    assert.equal(a.hooks.context, original);
    assert.equal(a.net.writes, 1);
    assert.equal(a.net.recoveries, 2);
    assert.deepEqual(await h.observe(s), before);
    await noPersistedMaterial(a, [original, expiredRecovery, a.hooks.recovery]);
    h.checked(26);
}

async function late(h) {
    const { primary: s, next } = h.fixture.sources;
    const a = await h.client('a');
    await a.login();
    await familyDetail(a, s);
    const nextBefore = await h.observe(next);
    const committed = Promise.withResolvers();
    const deliver = Promise.withResolvers();
    a.hooks.waitForAck = () => {
        committed.resolve();
        return deliver.promise;
    };
    try {
        await fillProof(a.page, a.image);
        await button(a.page, 'Kirim bukti pembayaran').click();
        await Promise.race([committed.promise, pause(8500).then(() => { throw new Error('late_commit_not_observed'); })]);
        await exact(a.page, 'link', '← Daftar tagihan').click();
        await a.page.waitForURL(a.origin + '/keluarga/tagihan');
        await familyDetail(a, next);
    } finally {
        deliver.resolve();
    }
    assert.equal(await exact(a.page, 'heading', 'Tindakan tercatat').count(), 0);
    assert.deepEqual(await h.observe(next), nextBefore);
    assert.equal(a.net.writes, 1);
    const p = (await h.observe(s)).financial.payments[0].id;
    await scan(h, s, p);
    await familyDetail(a, s, false);
    const ready = Promise.withResolvers();
    const release = Promise.withResolvers();
    a.hooks.waitForProof = () => {
        ready.resolve();
        return release.promise;
    };
    try {
        await button(a.page, openProof).click();
        await Promise.race([ready.promise, pause(8500).then(() => { throw new Error('late_preview_not_observed'); })]);
        await button(a.page, 'Tutup bukti pembayaran simulasi').click();
    } finally {
        release.resolve();
    }
    await a.page.locator('[data-payment-proof]').waitFor({ state: 'detached' });
    assert.equal(a.net.proofs, 1);
    assert.equal(grantCount(await h.observe(s), p, 'protected_get_granted'), 1);
    h.checked(27);
}

async function navigation(h) {
    const s = h.fixture.sources.primary;
    const a = await h.client('a');
    await a.ctx.addInitScript(() => {
        window.__r2Restored = false;
        window.addEventListener('pageshow', event => { window.__r2Restored = event.persisted; });
    });
    await a.login();
    await familyDetail(a, s);
    const before = await h.observe(s);
    a.hooks.loss = 'absent';
    await submit(a, s, 'Hasil tindakan belum diketahui', null);
    await a.open('/keluarga/tagihan');
    await a.page.goBack();
    await a.page.waitForURL(a.origin + familyPage(s));
    const restored = await a.page.evaluate(() => window.__r2Restored);
    await heading(a.page, 'Perlu pemeriksaan manual');
    assert.equal(await button(a.page, recoverLabel).count(), 0);
    assert.equal(a.net.writes, 1);
    assert.deepEqual(await h.observe(s), before);
    if (restored) h.checked(28);
}

async function security(h) {
    const { primary: s, foreign, transfer, legacy } = h.fixture.sources;
    const a = await h.client('a');
    const b = await h.client('b');
    const c = await h.client('admin');
    const inactive = await h.client('inactive');
    const nurse = await h.client('nurse');
    await a.login();
    await b.login();
    await c.login();
    await inactive.login('inactive', false);
    await nurse.login();
    const p = await pending(h, a, s);
    await scan(h, s, p);
    const before = await h.observe(s);
    for (const [client, path, status] of [
        [a, familyApi(foreign), 404], [b, familyApi(s), 404], [a, familyApi(transfer), 404], [b, familyApi(transfer), 404],
        [b, proofApi(s, p), 404], [c, adminApi(foreign, p), 404], [c, '/admin/pembayaran/' + legacy.invoice + '/' + p, 404],
        [nurse, '/admin/billing/payments', 403], [inactive, proofApi(s, p), 401],
    ]) assert.equal((await client.api(path)).status(), status);
    for (const operation of ['verify', 'reject']) {
        const response = await b.api(adminApi(s, p) + '/' + operation, { method: 'POST', body: {} });
        assert.equal(response.status(), 403);
    }
    for (const csrf of ['missing', 'invalid', 'valid']) {
        const response = await a.api(recoveryPath, { method: 'POST', csrf, body: {} });
        assert.equal(response.status(), csrf === 'valid' ? 422 : 419);
    }
    assert.equal((await a.api(proofApi(s, p), { method: 'HEAD' })).status(), 405);
    assert.equal((await a.api(proofApi(s, p), { headers: { Range: 'bytes=0-1' } })).status(), 422);
    const verifyWithoutAck = await c.api(adminApi(s, p) + '/verify', { method: 'POST', body: { confirm: true, bank_receipt_checked: false } });
    assert.equal(verifyWithoutAck.status(), 422);
    for (const path of [familyApi(s), adminApi(s, p)]) {
        const response = await (path === familyApi(s) ? a : c).api(path);
        assert.equal(response.status(), 200);
        assert.doesNotMatch(await response.text(), /proof_sha256|proof_path|proof_reference|CLEAN_ELIGIBLE|subject_digest|private_note/);
    }
    assert.deepEqual(await h.observe(s), before);
    h.checked(9, 29, 30, 35, 36, 37);
}

async function ordering(h) {
    const a = await h.client('a');
    const c = await h.client('admin');
    await a.login();
    await c.login();
    for (const mode of ['get-visible', 'get-rollback', 'get-unknown', 'verify-visible', 'verify-rollback', 'verify-unknown']) {
        const s = h.fixture.sources[mode];
        const p = await pending(h, a, s);
        await scan(h, s, p);
        const verify = mode.startsWith('verify-');
        let request;
        if (verify) {
            const context = await c.api(adminApi(s, p) + '/review-context?operation=verify');
            assert.equal(context.status(), 200);
            request = await c.api(adminApi(s, p) + '/verify', { method: 'POST', headers: { 'X-Silansia-05E-Probe': mode },
                body: { command_context: (await context.json()).command_context, confirm: true, bank_receipt_checked: true } });
        } else {
            request = await a.api(proofApi(s, p), { headers: { 'X-Silansia-05E-Probe': mode } });
        }
        const evidence = h.probe(mode);
        assert.equal(request.status(), mode.endsWith('-visible') ? 200 : 503);
        assert.equal(evidence.before.grants, 0);
        assert.equal(evidence.image, !verify && mode.endsWith('-visible'));
        if (verify && !mode.endsWith('-rollback')) assert.equal(evidence.before_response.same_commit, true);
        if (evidence.image) assert.equal(sha256(await request.body()), sha256(a.image.buffer));
        else assert.doesNotMatch(request.headers()['content-type'], /^image\//);
    }
    h.checked(32, 33);
}

export const scenarios = {
    family, admin, reject, 'admin-replay': adminReplay,
    'no-policy': h => negative(h, 'no-policy'),
    queued: h => negative(h, 'queued'),
    held: h => negative(h, 'held'),
    infected: h => negative(h, 'infected'),
    stale: h => negative(h, 'stale'),
    missing: h => negative(h, 'missing'),
    'scanner-off': h => negative(h, 'scanner-off'),
    'missing-verdict': h => negative(h, 'missing-verdict'),
    'stale-envelope': h => negative(h, 'stale-envelope'),
    'wrong-subject': h => negative(h, 'wrong-subject'),
    'wrong-generation': h => negative(h, 'wrong-generation'),
    'ack-lost': h => unknown(h, 'ack-lost'),
    'ack-absent': h => unknown(h, 'ack-absent'),
    'timeout-committed': h => unknown(h, 'timeout-committed'),
    'timeout-absent': h => unknown(h, 'timeout-absent'),
    reload: h => unknown(h, 'reload'),
    session: h => unknown(h, 'session'),
    tabs, expired, late, navigation,
    'locks-unavailable': h => unavailable(h, 'locks-unavailable'),
    'storage-unavailable': h => unavailable(h, 'storage-unavailable'),
    security, ordering,
};

export { familyDetail, grantDelta, initialFacts, openAdmin, pending, preview, review, scan, submit };
