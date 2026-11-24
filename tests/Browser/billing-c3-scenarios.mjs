import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { setTimeout as pause } from 'node:timers/promises';
import { invoiceDisplayStatus, wibDate } from '../../resources/js/utils/billing/invoiceStatus.js';
import { assertInitialPreviewUnchanged, assertPrivateError, assertStorageDelta, oversizedEnvelope, syntheticPng } from './billing-c3-acceptance.mjs';
import { familyDetail, grantDelta, initialFacts, openAdmin, pending, preview, review, scan, scenarios, submit } from './payment-r2-integration-scenarios.mjs';

const invoices = '/admin/billing/invoices';
const familyApi = s => '/keluarga/billing/invoices/' + s.invoice;
const reviewApi = (s, payment) => `${invoices}/${s.invoice}/payments/${payment}`;
const proofApi = (s, payment) => `/billing/invoices/${s.invoice}/payments/${payment}/proof`;
const watch = (client, path) => client.page.waitForResponse(r => new URL(r.url()).pathname === path && r.request().method() === 'POST');

const jsonPost = (client, path, body, extra) => client.api(path, { method: 'POST', body, ...extra });
const acceptState = async h => (await h.control('acceptance')).acceptance;
const proofHash = c => createHash('sha256').update(c.image.buffer).digest('hex');
const rateWindowMs = 61000;

async function awaitIssueBudget(h) {
    h.issueResponses ??= [];
    const now = performance.now();
    h.issueResponses = h.issueResponses.filter(at => now - at < rateWindowMs);
    if (h.issueResponses.length >= 6) {
        await pause(rateWindowMs - (now - h.issueResponses.shift()));
    }
}

export function invoiceStoreBody(source, intent) {
    return {
        elderly_id: source.resident,
        billing_month: source.month,
        request_id: intent.request_id,
        confirmation_token: intent.token,
        confirm: true,
    };
}

async function immutable(h, source, before) {
    assert.deepEqual(await h.observe(source), before);
}

async function primeIssue(h, client, tag) {
    const preview = () => requestPreview(h, client, tag);
    if (tag === 'primary') {
        return assertInitialPreviewUnchanged(async () => (await h.control('acceptance-initial')).acceptance, preview);
    }
    return preview();
}

async function requestPreview(h, client, tag) {
    const source = h.fixture.sources[tag];
    assert.deepEqual((await h.control('before', tag)).initial, { invoices: 0, revisions: 0 });
    await client.open(invoices + '/create');
    const { page } = client;
    await page.getByLabel('Cari nama lansia (termasuk nonaktif)').fill(source.label);
    await page.getByRole('button', { name: 'Cari lansia', exact: true }).click();
    await page.getByRole('radio', { name: new RegExp(source.label) }).check();
    await page.locator('#invoice-month').fill(source.month.slice(0, 7));
    await page.getByRole('checkbox', { name: /Saya sudah memastikan/ }).check();
    const observed = watch(client, invoices + '/preview');
    await page.getByRole('button', { name: 'Preview invoice', exact: true }).click();
    const response = await observed;
    assert.equal(response.status(), 200);
    const preview = await response.json();
    assert.equal(preview.issued, false);
    assert.equal(preview.calculation.amount, source.amount);
    assert.equal(preview.calculation.monthly_price, source.amount);
    assert.equal(preview.calculation.financial_owner_id, source.family);
    assert.equal(preview.calculation.chargeable_days, preview.calculation.days_in_month);
    assert.deepEqual((await h.control('before', tag)).initial, { invoices: 0, revisions: 0 });
    return preview.confirmation;
}

async function issue(h, client, tag, confirmation) {
    const { page } = client;
    await awaitIssueBudget(h);
    const intent = confirmation ?? await primeIssue(h, client, tag);
    await page.getByRole('button', { name: 'Tinjau konfirmasi penerbitan' }).click();
    await page.getByRole('dialog').getByRole('checkbox').check();
    const observed = watch(client, invoices);
    await page.getByRole('button', { name: 'Konfirmasi dan terbitkan' }).click();
    const response = await observed;
    h.issueResponses.push(performance.now());
    assert.equal(response.status(), 201);
    const fact = await response.json();
    assert.equal(fact.issued, true);
    assert.equal(fact.replayed, false);
    assert.equal(fact.detail_url, invoices + '/' + fact.invoice_id);
    await page.waitForURL(factPath => factPath.pathname === fact.detail_url);
    await page.getByRole('heading', { name: 'Detail invoice', exact: true }).waitFor();
    const bound = (await h.control('bind', tag, fact.invoice_id)).issued;
    assert.equal(bound.revision, fact.revision_id);
    assert.equal(bound.amount, fact.amount);
    return { ...h.fixture.sources[tag], invoice: fact.invoice_id, intent, bound };
}

async function getContext(client, endpoint) {
    const response = await client.api(endpoint);
    assert.equal(response.status(), 200);
    const { command_context } = await response.json();
    assert.ok(command_context);
    return command_context;
}

function multipart(client, source, command, proof = client.image, fields = {}) {
    return client.api(familyApi(source) + '/payments', { method: 'POST',
        multipart: { command_context: command, confirm: '1', proof, ...fields } });
}

async function denied(response, status, code) {
    assert.equal(response.status(), status);
    assertPrivateError(await response.json(), code);
    assert.match(response.headers()['cache-control'], /no-store/);
    assert.equal(response.headers()['x-content-type-options'], 'nosniff');
    assert.doesNotMatch(response.headers()['content-type'] ?? '', /^image\//);
}

async function happy(h, admin, family) {
    const primary = await issue(h, admin, 'primary');
    await family.open('/keluarga/tagihan');
    await family.page.getByText(primary.label, { exact: true }).waitFor();
    const payment = await pending(h, family, primary);
    await family.page.getByText(/Menunggu verifikasi Admin\. Tagihan belum dinyatakan lunas/).waitFor();
    await family.page.getByText('Belum jatuh tempo', { exact: true }).waitFor();
    await scan(h, primary, payment);
    await admin.open('/admin/pembayaran');
    await admin.page.getByText(primary.label, { exact: true }).waitFor();
    await openAdmin(admin, primary, payment);
    const privatePath = proofApi(primary, payment);
    const protectedResponse = admin.page.waitForResponse(r => new URL(r.url()).pathname === privatePath && r.request().method() === 'GET');
    admin.hooks.proofAudit = privatePath;
    await preview(h, admin, primary, payment, h.fixture.accounts.admin.id, false);
    admin.hooks.proofAudit = null;
    assert.equal((await protectedResponse).headers()['x-silansia-c3-proof-audit'], 'committed-before-send');
    const before = await h.observe(primary);
    const verifyPath = reviewApi(primary, payment);
    const unusedVerify = await getContext(admin, verifyPath + '/review-context?operation=verify');
    const fact = await review(admin, primary, payment, 'verify');
    assert.equal(fact.command_fact, 'verification_recorded');
    assert.equal(fact.payment_id, payment);
    assert.equal(fact.invoice_id, primary.invoice);
    assert.equal(fact.revision_id, primary.bound.revision);
    assert.equal(fact.amount, primary.amount);
    assert.equal(fact.replayed, false);
    const after = await h.observe(primary);
    grantDelta(before, after, payment, 'verify_use_granted', h.fixture.accounts.admin.id);
    assert.equal(after.financial.invoice.status, 'paid');
    assert.ok(after.financial.invoice.paid_at);
    assert.equal(after.financial.payments.length, 1);
    assert.equal(after.financial.payments[0].status, 'verified');
    assert.equal(after.financial.requests.length, before.financial.requests.length + 1);
    assert.equal(after.audit.actions.length, before.audit.actions.length + 1);
    assert.deepEqual(after.audit.actions.map(row => row.action), ['invoice_issued', 'payment_submitted', 'payment_verified']);
    assert.ok(after.audit.actions.some(row => row.action === 'payment_verified' && row.actor_id === primary.admin && row.payment_id === payment));
    for (const key of ['protected', 'scanner']) assert.deepEqual(after[key], before[key]);
    assert.deepEqual((await h.control('visibility', primary.invoice, payment)).visibility,
        { payment: 'verified', invoice: 'paid', grants: 1, verify_facts: 1, same_commit: true });
    await denied(await admin.api(verifyPath + '/review-context?operation=verify'), 409, 'stale_payment_state');
    await denied(await jsonPost(admin, verifyPath + '/verify',
        { command_context: unusedVerify, confirm: true, bank_receipt_checked: true }), 409, 'invoice_not_eligible');
    await immutable(h, primary, after);
    await family.page.reload();
    await family.page.getByText('Lunas', { exact: true }).first().waitFor();
    const detail = await family.api(familyApi(primary));
    assert.equal(detail.status(), 200);
    const payload = await detail.json();
    assert.equal(payload.invoice.id, primary.invoice);
    assert.equal(payload.invoice.amount, primary.amount);
    assert.equal(payload.invoice.status, 'paid');
    h.checked('C3-01');
    return { primary, payment, paid: after };
}

async function authorization(h, admin, family, primary, payment) {
    const before = await acceptState(h);
    const guest = await h.client('guest');
    const inactive = await h.client('inactive');
    const nurse = await h.client('nurse');
    await inactive.login('inactive', false);
    await nurse.login();
    for (const path of ['/admin/pembayaran', '/keluarga/tagihan']) {
        const page = await guest.api(path, { headers: { Accept: 'text/html' } });
        assert.equal(page.status(), 302);
        assert.equal(new URL(page.headers().location, guest.origin).href, guest.origin + '/login');
    }
    await denied(await guest.api(familyApi(primary)), 401);
    await denied(await nurse.api(familyApi(primary)), 403);
    await denied(await nurse.api('/admin/billing/payments'), 403);
    await denied(await admin.api(familyApi(primary) + '/payment-context'), 403);
    await denied(await multipart(admin, primary, 'invalid-synthetic-context'), 403);
    await denied(await jsonPost(family, reviewApi(primary, payment) + '/verify', {}), 403);
    assert.equal((await jsonPost(family, invoices + '/preview', {})).status(), 403);
    assert.equal((await jsonPost(family, invoices, invoiceStoreBody(primary, primary.intent))).status(), 403);
    await denied(await jsonPost(family, '/billing/payment-commands/reconcile', {}, { csrf: 'missing' }), 419);
    await denied(await jsonPost(family, '/billing/payment-commands/reconcile', {}, { csrf: 'invalid' }), 419);
    assert.deepEqual(await acceptState(h), before);
    h.checked('C3-02');
}

async function ownership(h, admin, family, primary, payment) {
    const foreign = await issue(h, admin, 'foreign');
    const transfer = await issue(h, admin, 'transfer');
    const transferContext = await getContext(family, familyApi(transfer) + '/payment-context');
    const transferredPayment = await pending(h, family, transfer);
    await scan(h, transfer, transferredPayment);
    const original = await acceptState(h);
    const b = await h.client('b');
    await b.login();
    await b.open('/keluarga/tagihan');
    await b.page.getByText(foreign.label, { exact: true }).waitFor();
    assert.equal(await b.page.getByText(primary.label, { exact: true }).count(), 0);
    const fake = { name: 'not-a-proof.png', mimeType: 'image/png', buffer: Buffer.from('synthetic bogus') };
    for (const path of [familyApi(primary), familyApi(primary) + '/payment-context', proofApi(primary, payment)]) {
        await denied(await b.api(path), 404);
    }
    // A valid context for B's own invoice still gives no authority over A's target.
    const ctx = await getContext(b, familyApi(foreign) + '/payment-context');
    await denied(await multipart(b, primary, ctx, b.image), 404);
    await denied(await admin.api(reviewApi(foreign, payment)), 404);
    await denied(await admin.api(proofApi(foreign, payment)), 404);
    assert.deepEqual(await acceptState(h), original);
    assert.equal((await h.control('transfer', transfer.invoice)).transferred, true);
    const afterTransfer = await acceptState(h);
    assert.deepEqual(afterTransfer.storage, original.storage);
    for (const client of [family, b]) {
        for (const path of [familyApi(transfer), familyApi(transfer) + '/payment-context', proofApi(transfer, transferredPayment)]) {
            await denied(await client.api(path), 404);
        }
        await denied(await multipart(client, transfer, client === family ? transferContext : ctx), 404);
        const list = await client.api('/keluarga/billing/invoices');
        assert.equal(list.status(), 200);
        assert.doesNotMatch(await list.text(), new RegExp(transfer.invoice));
    }
    assert.deepEqual(await acceptState(h), afterTransfer);
    const transferred = await h.observe(transfer);
    assert.equal(transferred.financial.invoice.bill_to_family_id, h.fixture.accounts.a.id);
    assert.equal((await h.observe(foreign)).financial.payments.length, 0);
    h.checked('C3-03');
    return { fake, protectedAfterTransfer: transferred.protected };
}

async function invalidAndDuplicate(h, admin, family, primary, negativeImage) {
    const invalid = await issue(h, admin, 'invalid-format');
    const dimensions = await issue(h, admin, 'invalid-dimensions');
    const tamper = await issue(h, admin, 'tamper');
    // Mint both issue intents while the source still has no invoice.
    const original = await primeIssue(h, admin, 'rejection');
    const s = h.fixture.sources.rejection;
    const second = await jsonPost(admin, invoices + '/preview', { elderly_id: s.resident, billing_month: s.month });
    assert.equal(second.status(), 200);
    const competing = (await second.json()).confirmation;
    const rejection = await issue(h, admin, 'rejection', original);
    const before = await h.observe(rejection);
    const beforeFiles = await acceptState(h);
    const context = await getContext(family, familyApi(rejection) + '/payment-context');
    const invalidContext = await getContext(family, familyApi(invalid) + '/payment-context');
    const dimensionContext = await getContext(family, familyApi(dimensions) + '/payment-context');
    const tamperContext = await getContext(family, familyApi(tamper) + '/payment-context');
    await denied(await jsonPost(family, familyApi(invalid) + '/payments', {}), 415);
    await denied(await multipart(family, invalid, invalidContext, negativeImage), 422);
    await denied(await multipart(family, invalid, invalidContext, { ...family.image, name: 'mismatch.jpg', mimeType: 'image/jpeg' }),
        422, 'invalid_proof');
    const corrupt = Buffer.from(family.image.buffer);
    corrupt[corrupt.length - 1] ^= 1;
    await denied(await multipart(family, dimensions, dimensionContext, { ...family.image, buffer: corrupt }), 422, 'invalid_proof');
    for (const [width, height] of [[2049, 1], [1, 2049], [2001, 2000]]) {
        await denied(await multipart(family, dimensions, dimensionContext, { ...family.image, buffer: syntheticPng(width, height) }), 422, 'invalid_proof');
    }
    const fileTooLarge = { ...family.image, buffer: Buffer.alloc(2097153) };
    await denied(await multipart(family, invalid, invalidContext, fileTooLarge), 413, 'request_too_large');
    await denied(await family.api(familyApi(invalid) + '/payments', {
        method: 'POST', ...oversizedEnvelope(family.image.buffer),
    }), 413, 'request_too_large');
    await immutable(h, rejection, before);
    assert.deepEqual(await acceptState(h), beforeFiles);
    h.checked('C3-04');

    await denied(await multipart(family, tamper, tamperContext, family.image, { amount: '1.00' }), 422);
    await denied(await multipart(family, tamper, tamperContext, family.image, { status: 'paid' }), 422);
    await denied(await multipart(family, tamper, tamperContext, family.image, { actor_id: h.fixture.accounts.b.id }), 422);
    await denied(await multipart(family, tamper, tamperContext, family.image, { bill_to_family_id: h.fixture.accounts.b.id }), 422, 'invalid_input');
    await denied(await multipart(family, dimensions, dimensionContext, family.image, { proof_path: 'private-test-sentinel/path.png' }), 422, 'invalid_input');
    await denied(await multipart(family, tamper, tamperContext, family.image, { revision_id: primary.bound.revision }), 422);
    await denied(await family.api(familyApi(rejection), { headers: { Accept: 'text/html' } }), 406);
    await denied(await family.api(familyApi(rejection), { headers: { 'X-Inertia': 'true' } }), 406);
    const otherSession = await h.client('a');
    await otherSession.login();
    await denied(await multipart(otherSession, rejection, context), 422, 'context_invalid');
    await denied(await multipart(family, primary, context), 422, 'context_invalid');
    const { expired } = await h.controlInput('expire-context', [rejection.invoice], context);
    await denied(await multipart(family, rejection, expired), 409, 'context_expired');
    await immutable(h, rejection, before);
    assert.deepEqual(await acceptState(h), beforeFiles);
    h.checked('C3-05');

    await awaitIssueBudget(h);
    const replay = await jsonPost(admin, invoices, invoiceStoreBody(s, original));
    h.issueResponses.push(performance.now());
    assert.equal(replay.status(), 200);
    const replayedInvoice = await replay.json();
    assert.equal(replayedInvoice.invoice_id, rejection.invoice);
    assert.equal(replayedInvoice.replayed, true);
    await awaitIssueBudget(h);
    const issueConflict = await jsonPost(admin, invoices, invoiceStoreBody(s, competing));
    h.issueResponses.push(performance.now());
    assert.equal(issueConflict.status(), 409);
    assert.equal((await issueConflict.json()).error.code, 'invoice_exists');
    assert.notEqual(primary.invoice, rejection.invoice);
    await immutable(h, rejection, before);
    assert.deepEqual(await acceptState(h), beforeFiles);

    const competingPayment = await getContext(family, familyApi(rejection) + '/payment-context');
    const cookies = await family.ctx.cookies(family.origin);
    const cookie = cookies.find(value => value.name.startsWith('silansia_c3_'))?.value;
    const csrf = cookies.find(value => value.name === 'XSRF-TOKEN')?.value;
    assert.ok(cookie && csrf);
    const race = (await h.controlInput('race', [rejection.invoice], JSON.stringify({ cookie, csrf, contexts: [context, competingPayment] }))).race;
    const { overlap, fact } = race;
    assert.equal(overlap, true);
    assert.equal(race.conflict, 'pending_payment_exists');
    const after = await h.observe(rejection);
    const payment = initialFacts(before, after, fact, h.fixture.accounts.a.id);
    const afterRace = await acceptState(h);
    const added = assertStorageDelta(beforeFiles.storage, afterRace.storage, 2, proofHash(family));
    assert.deepEqual(added, new Set([race.winner_reference, race.orphan_reference]));
    const repeated = await multipart(family, rejection, context);
    const resubmitAllowedAt = performance.now() + rateWindowMs;
    assert.equal(repeated.status(), 200);
    const replayedPayment = await repeated.json();
    assert.equal(replayedPayment.payment_id, payment);
    assert.equal(replayedPayment.replayed, true);
    await immutable(h, rejection, after);
    assert.deepEqual(await acceptState(h), afterRace);
    h.checked('C3-06');
    return { rejection, payment, resubmitAllowedAt };
}

async function scannerAndTransitions(h, admin, family, paid, state) {
    const { rejection, payment, resubmitAllowedAt } = state;
    async function denyUnusable(s, p) {
        const before = await h.observe(s);
        const path = reviewApi(s, p);
        await denied(await admin.api(proofApi(s, p)), 503, 'proof_use_unavailable');
        const context = await getContext(admin, path + '/review-context?operation=verify');
        await denied(await jsonPost(admin, path + '/verify', { command_context: context, confirm: true, bank_receipt_checked: true }),
            503, 'proof_use_unavailable');
        assert.equal(before.financial.invoice.status, 'unpaid');
        assert.equal(before.financial.payments.find(row => row.id === p)?.status, 'pending');
        await immutable(h, s, before);
    }
    await denyUnusable(rejection, payment);
    // Drain eligible jobs in order before the stale scenario creates a new generation.
    await scan(h, rejection, payment);
    const currentVerify = await getContext(admin, reviewApi(rejection, payment) + '/review-context?operation=verify');
    await denied(await jsonPost(admin, reviewApi(rejection, payment) + '/verify',
        { command_context: currentVerify, confirm: true, bank_receipt_checked: false }), 422);
    const unusedVerify = await getContext(admin, reviewApi(rejection, payment) + '/review-context?operation=verify');
    assert.notEqual(unusedVerify, currentVerify);
    await openAdmin(admin, rejection, payment);
    assert.equal((await review(admin, rejection, payment, 'reject')).command_fact, 'rejection_recorded');
    const rejected = await h.observe(rejection);
    assert.equal(rejected.financial.invoice.status, 'unpaid');
    assert.equal(rejected.financial.payments[0].status, 'rejected');
    assert.ok(rejected.audit.actions.some(row => row.action === 'payment_rejected' && row.payment_id === payment));
    await denied(await admin.api(reviewApi(rejection, payment) + '/review-context?operation=verify'), 409, 'stale_payment_state');
    await denied(await jsonPost(admin, reviewApi(rejection, payment) + '/verify',
        { command_context: currentVerify, confirm: true, bank_receipt_checked: true }), 409, 'stale_payment_state');
    await denied(await jsonPost(admin, reviewApi(rejection, payment) + '/verify',
        { command_context: unusedVerify, confirm: true, bank_receipt_checked: true }), 409, 'stale_payment_state');
    await immutable(h, rejection, rejected);
    const zero = await issue(h, admin, 'zero');
    const cancelled = await issue(h, admin, 'cancelled');
    assert.equal((await h.control('cancelled-fixture', cancelled.invoice)).cancelled, true);
    const terminalTemplate = await getContext(family, familyApi(rejection) + '/payment-context');
    for (const i of [paid, zero, cancelled]) {
        const before = await acceptState(h);
        assert.equal((await h.observe(i)).financial.invoice.status, i === cancelled ? 'cancelled' : 'paid');
        await denied(await family.api(familyApi(i) + '/payment-context'), 409, 'invoice_not_eligible');
        const { negative } = await h.controlInput('terminal-context', [i.invoice], terminalTemplate);
        await denied(await multipart(family, i, negative), 409, 'invoice_not_eligible');
        const after = await acceptState(h);
        assert.deepEqual(after.graph, before.graph);
        // Intake precedes the writer. An unassociated proof stays private for orphan review.
        assertStorageDelta(before.storage, after.storage, 1, proofHash(family));
    }
    await pause(Math.max(0, resubmitAllowedAt - performance.now()));
    await familyDetail(family, rejection);
    const replacement = await submit(family, rejection);
    assert.notEqual(replacement.payment_id, payment);
    initialFacts(rejected, await h.observe(rejection), replacement, h.fixture.accounts.a.id);
    await scan(h, rejection, replacement.payment_id);

    const stale = await issue(h, admin, 'stale');
    const p = await pending(h, family, stale);
    await scan(h, stale, p);
    assert.equal((await h.control('stale', stale.invoice, p)).stale, true);
    await denyUnusable(stale, p);
    await scan(h, stale, p);

    const ttl = await issue(h, admin, 'ttl');
    const ttlPayment = await pending(h, family, ttl);
    await scan(h, ttl, ttlPayment);
    const freshness = async () => (await h.control('verdict-freshness', ttl.invoice, ttlPayment)).freshness;
    const fresh = await freshness();
    assert.equal(fresh.projection, 'CLEAN_ELIGIBLE');
    assert.ok(fresh.remaining_ms > 0 && fresh.remaining_ms <= 120000);
    assert.equal((await admin.api(proofApi(ttl, ttlPayment))).status(), 200);
    // Wait for the actual DB clock. No verdict, clock or policy mutation to simulate age.
    await pause(fresh.remaining_ms + 50);
    const expired = await freshness();
    assert.deepEqual(expired, { ...fresh, remaining_ms: 0, projection: 'STALE_HELD' });
    await denyUnusable(ttl, ttlPayment);
    return { ttl, cancelled, denyUnusable };
}

async function verifyAcknowledgmentLoss(h, admin, family) {
    const source = await issue(h, admin, 'verify-loss');
    const payment = await pending(h, family, source);
    await scan(h, source, payment);
    await openAdmin(admin, source, payment);
    admin.hooks.loss = 'committed';
    const writes = admin.net.writes;
    const shipped = admin.net.shipped;
    const elapsed = performance.now();
    await review(admin, source, payment, 'verify', 'Hasil tindakan belum diketahui', null);
    assert.ok(performance.now() - elapsed >= 15000, 'expected the UI transport deadline before UNKNOWN');
    admin.hooks.loss = null;
    const committed = await h.observe(source);
    assert.equal(committed.financial.invoice.status, 'paid');
    assert.equal(committed.financial.payments[0].status, 'verified');
    const reply = watch(admin, '/billing/payment-commands/reconcile');
    await admin.page.getByRole('button', { name: 'Periksa hasil tindakan yang sama', exact: true }).click();
    const recovered = await reply;
    assert.equal(recovered.status(), 200);
    const fact = await recovered.json();
    assert.equal(fact.command_fact, 'verification_recorded');
    assert.equal(fact.payment_id, payment);
    assert.equal(fact.replayed, true);
    await admin.page.getByRole('heading', { name: 'Hasil tindakan ditemukan', exact: true }).waitFor();
    assert.equal(admin.net.writes, writes + 1);
    assert.equal(admin.net.shipped, shipped + 1);
    assert.equal(admin.net.timedOut, 1);
    await immutable(h, source, committed);
}

export function assertCalendarExamples() {
    const unpaid = { status: 'unpaid', due_date: '2028-02-29', grace_period_ends_on: '2028-03-03' };
    for (const [instant, result] of [['2028-02-29T16:59:59.999Z', 'not_due'], ['2028-02-29T17:00:00.000Z', 'in_grace'],
        ['2028-03-03T16:59:59.999Z', 'in_grace'], ['2028-03-03T17:00:00.000Z', 'past_grace']]) {
        assert.equal(invoiceDisplayStatus(unpaid, wibDate(new Date(instant))), result);
    }
    assert.equal(invoiceDisplayStatus({ ...unpaid, due_date: null }), 'unpaid');
    assert.equal(invoiceDisplayStatus({ status: 'paid' }), 'paid');
    assert.equal(invoiceDisplayStatus({ status: 'cancelled' }), 'cancelled');
}

async function calendarSmoke(family, unpaid, paid, cancelled) {
    const labels = [
        [unpaid, new Date(unpaid.bound.due + 'T16:59:59.999Z'), 'Belum jatuh tempo'],
        [unpaid, new Date(unpaid.bound.due + 'T17:00:00.000Z'), 'Dalam masa tenggang'],
        [unpaid, new Date(unpaid.bound.grace + 'T16:59:59.999Z'), 'Dalam masa tenggang'],
        [unpaid, new Date(unpaid.bound.grace + 'T17:00:00.000Z'), 'Lewat masa tenggang'],
        [paid, new Date(paid.bound.grace + 'T17:00:00.000Z'), 'Lunas'],
        [cancelled, new Date(cancelled.bound.grace + 'T17:00:00.000Z'), 'Dibatalkan'],
    ];
    try {
        for (const [source, day, label] of labels) {
            await family.page.clock.setFixedTime(day);
            await family.open('/keluarga/tagihan/' + source.invoice);
            await family.page.getByText(label, { exact: true }).first().waitFor();
        }
    } finally {
        await family.page.clock.setFixedTime(new Date());
    }
}

export async function billingC3(h) {
    const admin = await h.client('admin');
    const family = await h.client('a');
    await admin.login();
    await family.login();
    const result = await happy(h, admin, family);
    await authorization(h, admin, family, result.primary, result.payment);
    const { fake, protectedAfterTransfer } = await ownership(h, admin, family, result.primary, result.payment);
    const state = await invalidAndDuplicate(h, admin, family, result.primary, fake);
    const { ttl, cancelled, denyUnusable } = await scannerAndTransitions(h, admin, family, result.primary, state);
    await verifyAcknowledgmentLoss(h, admin, family);
    // Unknown schedules a retry. Run it after every test that requires an uncontested scanner queue.
    const held = await issue(h, admin, 'unknown');
    const originalImage = family.image;
    let unknown;
    try {
        family.image = { ...originalImage, buffer: Buffer.from(h.fixture.unknown_proof, 'base64') };
        unknown = await pending(h, family, held);
    } finally {
        family.image = originalImage;
    }
    await scan(h, held, unknown, 'unknown');
    await denyUnusable(held, unknown);
    h.checked('C3-07', 'C3-08');
    for (const [tag, mode] of Object.entries({ lost: 'ack-lost', absent: 'ack-absent', session: 'session' })) {
        const source = await issue(h, admin, tag);
        await scenarios[mode]({ ...h, fixture: { ...h.fixture, sources: { primary: source } }, checked: () => {} });
    }
    h.checked('C3-09');
    assertCalendarExamples();
    await calendarSmoke(family, ttl, result.primary, cancelled);
    h.checked('C3-10');
    const beforeContainment = await acceptState(h);
    assert.equal((await fetch(admin.origin + '/login', { redirect: 'error' })).status, 404);
    assert.equal((await admin.api('/login', { headers: { Origin: 'https://example.test' } })).status(), 404);
    assert.equal((await admin.api('/admin/accounts')).status(), 404);
    await h.listenerProbe();
    await admin.containmentProbe();
    assert.deepEqual(await acceptState(h), beforeContainment);
    // Only the explicitly authorized transfer case can change these domain fingerprints.
    const final = await h.observe(result.primary);
    assert.deepEqual(final.financial, result.paid.financial);
    assert.deepEqual(final.audit, result.paid.audit);
    assert.deepEqual(final.protected, protectedAfterTransfer);
    for (const [table, digest] of Object.entries(result.paid.protected)) {
        if (!['elderlies', 'service_enrollments', 'financial_ownership_periods'].includes(table)) assert.equal(final.protected[table], digest);
    }
    h.checked('C3-11');
}
