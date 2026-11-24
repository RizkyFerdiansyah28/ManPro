import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from './isolatedVite.mjs';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { resolveObjectURL } from 'node:buffer';

let vite, parts;
before(async () => {
    vite = await createServer({ root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false, plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false }, optimizeDeps: { noDiscovery: true, include: [] } });
    parts = await vite.ssrLoadModule('/resources/js/components/Payments/PaymentPages.jsx');
});
after(async () => { await vite?.close(); });
async function render(name, props) {
    return (await createInertiaApp({ page: { component: 'Fixture', props: { auth: { user: { id: '00000000-0000-4000-8000-000000000001', role: 'admin', name: 'Admin sintetis' } }, flash: {}, errors: {} }, url: '/admin/pembayaran', version: null }, resolve: () => () => React.createElement(parts[name], props), render: renderToString, setup: ({ App, props }) => React.createElement(App, props) })).body.replace(/<script\b[^>]*data-page="app"[\s\S]*?<\/script>/g, '').replace(/<!-- -->/g, '');
}
const noop = () => {};
const row = { id: '00000000-0000-4000-8000-000000000002', invoice_id: '00000000-0000-4000-8000-000000000003', number: 'INV-SYNTHETIC', invoice_number: 'INV-SYNTHETIC', billing_month: '2026-10-01', amount: '999999999999.99', due_date: '2026-11-08', submitted_at: '2026-11-01T01:00:00Z', status: 'pending', resident: { name_current: '<img src=x onerror=alert(1)>' }, financial_owner: { name_current: 'Penanggung sintetis' }, private_note: 'MUST-NOT-RENDER', proof_path: 'MUST-NOT-RENDER' };
const list = props => render('PaymentListContent', { role: 'admin', status: 'pending', loading: false, onStatus: noop, onReload: noop, onPage: noop, ...props });
test('payment list renders safe labels, exact money, pagination and nested detail', async () => {
    const html = await list({ page: { data: [row], page: 2, has_more: true } });
    for (const value of ['Antrean pembayaran', 'Penanggung sintetis', '999.999.999.999,99', 'Sebelumnya', 'Berikutnya', 'Halaman 2', row.invoice_id + '/' + row.id]) assert.ok(html.includes(value), value);
    assert.match(html, /&lt;img/); assert.doesNotMatch(html, /<img src=x|MUST-NOT-RENDER/);
});
test('family list uses the invoice calendar without exposing Admin owner or private fields', async (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-11-08T17:00:00Z') });
    const html = await list({ role: 'keluarga', status: '', page: { data: [{ ...row, status: 'unpaid', grace_period_ends_on: '2026-11-11' }], page: 1, has_more: false } });
    assert.match(html, /Tagihan keluarga/); assert.match(html, />Dalam masa tenggang</); assert.match(html, new RegExp('/keluarga/tagihan/' + row.id));
    assert.match(html, /<option value="unpaid">Belum lunas<\/option>/);
    assert.match(html, /Status tagihan/);
    assert.match(html, /Masa tenggang sampai/);
    assert.match(html, /ui-table-scroll/);
    assert.doesNotMatch(html, /Penanggung sintetis|MUST-NOT-RENDER/);
});
test('empty, loading and errors remain distinct and accessible', async () => {
    const page = { data: [], page: 1, has_more: false };
    assert.match(await list({ page }), /Belum ada pengajuan pembayaran pada status ini/);
    assert.match(await list({ role: 'keluarga', status: '', page }), /Belum ada tagihan untuk ditampilkan/);
    assert.match(await list({ role: 'keluarga', status: 'paid', page }), /Belum ada tagihan dengan status ini/);
    const emptyPage = await list({ page: { ...page, page: 2 } });
    assert.match(emptyPage, /Halaman ini tidak memuat data/);
    assert.match(emptyPage, /<button(?![^>]*disabled)[^>]*>Sebelumnya<\/button>/);
    assert.match(emptyPage, /Halaman 2/);
    const loading = await list({ loading: true, page }); assert.match(loading, /role="status"/); assert.doesNotMatch(loading, /Belum ada/);
    const error = await list({ page, error: { code: 'proof_boundary_unavailable', correlation: 'SYNTHETIC-ID', message: 'SYNTHETIC-INTERNAL-EXCEPTION' } });
    assert.match(error, /role="alert"/); assert.match(error, /Layanan pembayaran belum tersedia saat ini/); assert.match(error, /SYNTHETIC-ID/);
    assert.doesNotMatch(error, /Belum ada|proof_boundary_unavailable|SYNTHETIC-INTERNAL/);
});
for (const status of ['pending','verified','rejected']) test('payment badge ' + status + ' preserves explicit status', async () => {
    const html = await render('PaymentStatus', { status }); assert.match(html, new RegExp({ pending: 'Menunggu keputusan Admin', verified: 'Terverifikasi', rejected: 'Ditolak' }[status]));
    if (status === 'pending') assert.doesNotMatch(html, /Lunas/);
});
for (const state of ['unknown','unresolved','conflict','manual_hold','session_lost','resolved']) test('recovery notice ' + state + ' never invents financial result', async () => {
    const html = await render('PaymentFlowNotice', { state: { name: state }, onReconcile: noop, onCheck: noop });
    assert.match(html, /aria-live="polite"/); assert.doesNotMatch(html, /Pembayaran gagal|Terverifikasi|Tindakan tercatat/);
    assert.equal(html.includes('Periksa hasil tindakan yang sama'), ['unknown','unresolved','conflict'].includes(state));
    if (state === 'manual_hold') assert.match(html, /Context asli sudah tidak tersedia/);
});
const unavailableProof = {
    code: 'proof_use_unavailable', status: 503,
    correlation: '00000000-0000-4000-8000-000000000004',
    message: 'SYNTHETIC-INTERNAL-EXCEPTION',
    scanner_verdict: 'SYNTHETIC-PRIVATE-VERDICT',
    storage_path: 'SYNTHETIC-PRIVATE-PATH',
};
test('proof viewer renders closed with no image, download or automatic request', async () => {
    const api = { proof: () => assert.fail('Proof must only be requested after opening the viewer') };
    const html = await render('ProofViewer', { api, invoiceId: row.invoice_id, paymentId: row.id });
    assert.match(html, /Buka bukti transfer/);
    assert.doesNotMatch(html, /data-payment-proof|blob:|Unduh salinan bukti|Memuat bukti/);
});
test('proof preview 503 renders safe denial and correlation without private metadata', async () => {
    const html = await render('ErrorBox', { error: unavailableProof, proofPreview: true });
    assert.match(html, /role="alert"/);
    assert.match(html, /Bukti pembayaran belum dapat dibuka saat ini\. Pemeriksaan bukti mungkin belum selesai\. Buka kembali pratinjau nanti\./);
    assert.ok(html.includes(unavailableProof.correlation));
    assert.doesNotMatch(html, /SYNTHETIC-(?:INTERNAL|PRIVATE)|data-payment-proof|blob:|Unduh salinan bukti|Terverifikasi|Pembayaran gagal/);
    assert.doesNotMatch(await render('ErrorBox', { error: { ...unavailableProof, status: 500 }, proofPreview: true }), /Bukti pembayaran belum dapat dibuka/);
});
for (const [status, code, message] of [
    [401, 'authentication_required', 'Silakan masuk kembali.'],
    [403, 'forbidden', 'Akses pembayaran dibatasi.'],
    [404, 'not_found', 'Tagihan atau pembayaran tidak tersedia untuk akun ini.'],
    [405, 'method_not_allowed', 'Tindakan ini tidak tersedia.'],
    [419, 'session_expired', 'Sesi berakhir.'],
    [422, 'invalid_input', 'Periksa kembali isian Anda.'],
    [429, 'rate_limited', 'Terlalu banyak permintaan.'],
    [503, 'gate_closed', 'Layanan pembayaran belum tersedia saat ini.'],
    [503, 'proof_scan_unavailable', 'Pemeriksaan bukti pembayaran sementara tidak tersedia.'],
    [0, 'network_error', 'Koneksi ke layanan pembayaran terganggu.'],
    [0, 'malformed_response', 'Respons dari layanan pembayaran tidak dapat dibaca.'],
]) test('payment error copy keeps the meaning of ' + code, async () => {
    const html = await render('ErrorBox', { error: { status, code, message: 'SYNTHETIC-INTERNAL-EXCEPTION' }, proofPreview: true });
    assert.ok(html.includes(message));
    assert.doesNotMatch(html, /SYNTHETIC-INTERNAL|Bukti pembayaran belum dapat dibuka|Terverifikasi|Pembayaran gagal/);
});
test('uncertain verify denial offers only explicit recovery and does not borrow preview retry copy', async () => {
    const html = await render('PaymentFlowNotice', { state: { name: 'unknown', error: unavailableProof }, onReconcile: noop, onCheck: noop });
    assert.match(html, /Hasil tindakan belum dapat dipastikan\. Periksa hasil tindakan yang sama sebelum melakukan tindakan lain\./);
    assert.match(html, /<button[^>]*>Periksa hasil tindakan yang sama<\/button>/);
    assert.ok(html.includes(unavailableProof.correlation));
    assert.doesNotMatch(html, /Bukti pembayaran belum dapat dibuka|Silakan coba kembali nanti|Periksa akses kembali|Konfirmasi dan verifikasi|Tindakan tercatat|Terverifikasi|Pembayaran gagal|SYNTHETIC-(?:INTERNAL|PRIVATE)/);
});
test('scanner unavailability shows only safe copy and explicit same-intent recovery', async () => {
    const error = { ...unavailableProof, code: 'proof_scan_unavailable' };
    const html = await render('PaymentFlowNotice', { state: { name: 'unknown', error }, onReconcile: noop, onCheck: noop });
    assert.match(html, /Hasil tindakan belum dapat dipastikan/);
    assert.match(html, /Pemeriksaan bukti pembayaran sementara tidak tersedia/);
    assert.match(html, /<button[^>]*>Periksa hasil tindakan yang sama<\/button>/);
    assert.equal((html.match(/<button\b/g) ?? []).length, 1);
    assert.ok(html.includes(error.correlation));
    assert.doesNotMatch(html, /Kirim ulang|siap diverifikasi|Tindakan tercatat|proof_scan_unavailable|SYNTHETIC-(?:INTERNAL|PRIVATE)/);
});
test('canonical replay shows the recorded fact and explains that it already existed', async () => {
    const fact = { command_fact: 'verification_recorded', replayed: true };
    const html = await render('PaymentFlowNotice', { state: { name: 'resolved', fact }, onReconcile: noop, onCheck: noop });
    assert.match(html, /Verifikasi pembayaran tercatat/);
    assert.match(html, /Hasil ini sudah tercatat sebelumnya/);
    assert.doesNotMatch(html, /<button|belum dapat dipastikan/);
});
test('manual hold without in-memory intent directs to a human and exposes no recovery action', async () => {
    const html = await render('PaymentFlowNotice', { state: { name: 'manual_hold' }, onReconcile: noop, onCheck: noop });
    assert.match(html, /Hasil tindakan sebelumnya belum dapat dipastikan/);
    assert.match(html, /Hubungi pengelola untuk pemeriksaan manual/);
    assert.doesNotMatch(html, /Periksa hasil tindakan yang sama|<button|Tindakan tercatat/);
});
test('coordination contention and unavailable Web Locks give non-financial notices', async () => {
    for (const [code, description] of [
        ['coordination_busy', 'Pengajuan ini sedang diproses di tab lain.'],
        ['coordination_unavailable', 'Koordinasi antar-tab tidak tersedia di browser ini.'],
    ]) {
        const html = await render('PaymentFlowNotice', { state: { name: 'unavailable', error: { code } }, onReconcile: noop, onCheck: noop });
        assert.ok(html.includes(description));
        assert.match(html, /Periksa akses kembali/);
        assert.doesNotMatch(html, /Periksa hasil tindakan yang sama|Kirim ulang|Tindakan tercatat/);
    }
});
test('upload form starts unconfirmed, bounded, disabled and without financial fields', async () => {
    const html = await render('ProofSubmissionForm', { disabled: false, onSubmit: noop });
    for (const value of ['type="file"', 'JPEG/PNG', '2 MiB', '2.048 px', '4 juta', 'type="checkbox"', 'disabled=""']) assert.ok(html.includes(value));
    assert.doesNotMatch(html, /checked=""|name="amount"|name="owner_id"|command_context|proof_path/);
});

const familyInvoice = { ...row, id: row.invoice_id, elderly_id: row.id, status: 'unpaid', grace_period_ends_on: '2026-11-11' };
const pendingAttempt = { id: row.id, status: 'pending', submitted_at: row.submitted_at };
const rejectedAttempt = {
    id: '00000000-0000-4000-8000-000000000005', status: 'rejected', submitted_at: row.submitted_at,
    public_rejection_reason: 'Bukti <dummy> perlu diganti.',
    private_note: 'SYNTHETIC-PRIVATE-NOTE', scanner_verdict: 'SYNTHETIC-PRIVATE-VERDICT',
    proof_path: 'SYNTHETIC-PRIVATE-PATH',
};
const familyDetail = props => render('FamilyPaymentDetails', {
    invoice: familyInvoice, payments: [], api: { proof: () => assert.fail('Rendering history must not request proof') },
    stateName: 'idle', locked: false, generation: 0, onNewAttempt: noop, onSubmit: noop, ...props,
});

test('family list clearly identifies the academic simulation while loading', async () => {
    const html = await render('PaymentListPage', { role: 'keluarga' });
    assert.match(html, /<h1>Simulasi Pembayaran<\/h1>/);
    assert.match(html, /data sintetis untuk simulasi akademik/);
    assert.match(html, /role="status"[^>]*>Memuat daftar tagihan/);
    assert.doesNotMatch(html, /Belum ada tagihan untuk ditampilkan/);
});
test('family history keeps attempt statuses separate and only offers explicit, named preview actions', async (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-11-11T17:00:00Z') });
    const html = await familyDetail({ payments: [pendingAttempt, rejectedAttempt] });
    assert.match(html, />Lewat masa tenggang</);
    assert.match(html, /Menunggu keputusan Admin/);
    assert.match(html, /role="status">Menunggu verifikasi Admin\. Tagihan belum dinyatakan lunas/);
    assert.match(html, /Bukti perlu diperiksa sebelum Admin dapat memverifikasi pembayaran/);
    assert.match(html, /Ditolak/);
    assert.match(html, /Alasan untuk keluarga: Bukti &lt;dummy&gt; perlu diganti/);
    assert.match(html, /WIB/);
    assert.ok(html.includes(pendingAttempt.id) && html.includes(rejectedAttempt.id));
    for (const ordinal of [1, 2]) {
        assert.ok(html.includes('aria-label="Buka bukti pembayaran simulasi pengajuan ' + ordinal + '"'));
    }
    assert.equal((html.match(/aria-haspopup="dialog" aria-expanded="false"/g) ?? []).length, 2);
    assert.doesNotMatch(html, />Lunas<|SYNTHETIC-PRIVATE|href="[^"]*\/proof|\/storage\/|role="dialog"|data-payment-proof|<img|payment-proof|prefetch/);
});
test('server-paid and verified render separately; zero charge never offers proof submission', async () => {
    const verified = { ...pendingAttempt, status: 'verified' };
    const paid = await familyDetail({ invoice: { ...familyInvoice, status: 'paid' }, payments: [verified] });
    assert.match(paid, />Lunas</);
    assert.match(paid, />Terverifikasi</);
    assert.doesNotMatch(paid, /id="payment-proof"|Bukti perlu diperiksa/);

    const zero = await familyDetail({ invoice: { ...familyInvoice, amount: '0.00', status: 'paid' } });
    assert.match(zero, />Lunas</);
    assert.match(zero, /Tagihan tanpa biaya\. Bukti pembayaran tidak diperlukan/);
    assert.doesNotMatch(zero, /id="payment-proof"|Buka bukti|Siapkan pengajuan bukti baru/);
});
test('eligible resubmission requires a fresh selection and confirmation; UNKNOWN disables the form', async () => {
    const initial = await familyDetail();
    assert.match(initial, /Belum ada pengajuan bukti simulasi/);
    const ready = await familyDetail({ payments: [rejectedAttempt] });
    assert.match(ready, /Pengajuan pembayaran simulasi/);
    assert.match(ready, /<label[^>]*for="payment-proof"[^>]*>Bukti pembayaran simulasi/);
    assert.equal((ready.match(/id="payment-proof"/g) ?? []).length, 1);
    assert.match(ready, /accept="\.jpg,\.jpeg,\.png,image\/jpeg,image\/png"/);
    assert.match(ready, /JPEG\/PNG maksimal 2 MiB/);
    assert.doesNotMatch(ready, /checked=""|Siapkan pengajuan bukti baru/);
    assert.match(ready, /<button[^>]*type="submit"[^>]*disabled=""[^>]*>Kirim bukti pembayaran<\/button>/);

    const held = await familyDetail({ payments: [rejectedAttempt], stateName: 'unknown', locked: true });
    assert.match(held, /<input[^>]*id="payment-proof"[^>]*disabled=""/);
    assert.match(held, /<button[^>]*type="submit"[^>]*disabled=""[^>]*>Kirim bukti pembayaran<\/button>/);
    assert.doesNotMatch(held, /Siapkan pengajuan bukti baru/);

    const completed = await familyDetail({ payments: [rejectedAttempt], stateName: 'resolved', locked: true });
    assert.match(completed, /<button[^>]*type="button"[^>]*>Siapkan pengajuan bukti baru<\/button>/);
});
test('selected family viewer prefers a named dialog and close button; default Admin viewer stays inline', async () => {
    const api = { proof: () => assert.fail('SSR must not load proof') };
    const props = { api, invoiceId: familyInvoice.id, paymentId: rejectedAttempt.id };
    const family = await render('ProofViewer', { ...props, dialogId: 'fixture-proof-dialog', onDismiss: noop });
    assert.match(family, /id="fixture-proof-dialog" role="dialog" aria-labelledby="[^"]+"/);
    assert.match(family, /<h3 id="[^"]+"[^>]*>Bukti pembayaran simulasi<\/h3>/);
    assert.match(family, /<button[^>]*>Tutup bukti pembayaran simulasi<\/button>/);
    assert.match(family, /role="status">Memeriksa akses dan memuat pratinjau bukti/);
    assert.doesNotMatch(family, /data-payment-proof|blob:|\/proof|Unduh salinan bukti/);
    const admin = await render('ProofViewer', props);
    assert.match(admin, /Bukti transfer privat/);
    assert.match(admin, /Buka bukti transfer/);
    assert.doesNotMatch(admin, /simulasi|role="dialog"|data-payment-proof/);
});

function previewFixture() {
    const requests = [], urls = [], errors = [];
    const events = new EventTarget();
    let accessLost = 0;
    const api = { proof: (invoiceId, paymentId, signal) => new Promise((resolve, reject) => {
        requests.push({ invoiceId, paymentId, signal, resolve, reject });
    }) };
    const open = (invoiceId, paymentId) => parts.loadProofPreview(api, invoiceId, paymentId, {
        onLoad: url => urls.push(url),
        onError: error => errors.push(error),
        onAccessLost: () => { accessLost++; },
    }, events);
    return { requests, urls, errors, events, open, accessLost: () => accessLost };
}
const nextTurn = () => new Promise(resolve => setImmediate(resolve));

const adminInvoice = {
    ...familyInvoice,
    current_revision_id: '00000000-0000-4000-8000-000000000006',
    financial_owner: { id: row.id, name_current: 'Nama Direktori Kini' },
};
const paymentAttempt = {
    ...pendingAttempt, invoice_id: adminInvoice.id, invoice_revision_id: adminInvoice.current_revision_id,
    amount: adminInvoice.amount, verified_at: null, private_note: 'PRIVATE-NOTE-NOT-IN-MARKUP',
};
const adminDetail = props => render('AdminPaymentDetails', {
    invoice: adminInvoice, payment: paymentAttempt, api: { proof: () => assert.fail('Admin detail SSR must not load proof') },
    locked: false, onSubmit: noop, ...props,
});

test('Admin simulation page preserves the existing filter choices and paging controls', async () => {
    const shell = await render('PaymentListPage', { role: 'admin' });
    assert.match(shell, /Pemeriksaan Pembayaran Simulasi/);
    assert.match(shell, /Tidak ada transaksi bank nyata yang dilakukan/);
    assert.match(shell, /Memuat daftar pembayaran/);
    const html = await list({ page: { data: [row], page: 2, has_more: true } });
    for (const status of ['pending', 'verified', 'rejected']) assert.ok(html.includes('value="' + status + '"'));
    assert.match(html, /Status pembayaran/);
    assert.match(html, /<option value="pending"[^>]*>Menunggu keputusan Admin<\/option>/);
    assert.match(html, />Menunggu keputusan Admin<\/span>/);
    assert.match(html, /Sebelumnya.*Halaman 2.*Berikutnya/);
    assert.ok(html.includes('/admin/pembayaran/' + row.invoice_id + '/' + row.id));
});

test('Admin review separates invoice from attempt and offers metadata-only reject before any preview', async (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-11-08T16:59:59Z') });
    const html = await adminDetail({ payment: { ...paymentAttempt, scanner_verdict: 'CLEAN_ELIGIBLE' } });
    for (const value of ['Belum jatuh tempo', 'Menunggu keputusan Admin', 'Bukti pembayaran simulasi', 'Tinjau verifikasi',
        'Bukti perlu diperiksa sebelum Admin dapat memverifikasi pembayaran',
        'Tolak pengajuan simulasi', 'berdasarkan metadata', 'Nama Direktori Kini', 'UUID penanggung berasal dari snapshot',
        adminInvoice.number, adminInvoice.current_revision_id, 'WIB']) assert.ok(html.includes(value), value);
    assert.doesNotMatch(html, /<dialog|<img|blob:|\/storage\/|PRIVATE-NOTE-NOT-IN-MARKUP|CLEAN_ELIGIBLE|siap diverifikasi/);
});

for (const status of ['verified', 'rejected']) test('Admin terminal attempt shows backend status without review actions: ' + status, async (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-11-11T17:00:00Z') });
    const html = await adminDetail({
        invoice: { ...adminInvoice, status: status === 'verified' ? 'paid' : 'unpaid' },
        payment: { ...paymentAttempt, status, public_rejection_reason: status === 'rejected' ? 'Bukti dummy kurang jelas.' : null },
    });
    assert.match(html, status === 'verified' ? />Lunas<.*Terverifikasi/ : /Lewat masa tenggang.*Ditolak/);
    if (status === 'rejected') assert.match(html, /Alasan untuk keluarga: Bukti dummy kurang jelas/);
    assert.doesNotMatch(html, /Tinjau verifikasi|Tolak pengajuan simulasi|PRIVATE-NOTE-NOT-IN-MARKUP/);
});

test('verify dialog names the target and requires two unchecked confirmations', async () => {
    const html = await render('ReviewDialog', { mode: 'verify', invoice: adminInvoice, payment: paymentAttempt, onClose: noop, onSubmit: noop });
    assert.match(html, /<dialog[^>]*aria-labelledby="([^"]+)" aria-describedby="([^"]+)"/);
    assert.match(html, /Verifikasi Pembayaran Simulasi|Periksa receipt dummy/);
    assert.ok(html.includes(paymentAttempt.id) && html.includes(adminInvoice.number));
    assert.equal((html.match(/type="checkbox"[^>]*required=""/g) ?? []).length, 2);
    assert.doesNotMatch(html, /\schecked=""|mutasi bank|dummy_receipt_checked|PRIVATE-NOTE-NOT-IN-MARKUP/);
    assert.match(html, /<button[^>]*type="submit"[^>]*disabled=""[^>]*>Konfirmasi dan verifikasi/);
});

test('reject dialog has independent text areas, descriptions, and validation before submission', async () => {
    const html = await render('ReviewDialog', { mode: 'reject', invoice: adminInvoice, payment: paymentAttempt, onClose: noop, onSubmit: noop });
    const publicReason = html.match(/<textarea id="([^"]+-public)"[^>]*required=""[^>]*aria-describedby="([^"]+)"/);
    const privateNote = html.match(/<textarea id="([^"]+-private)"[^>]*aria-describedby="([^"]+)"/);
    assert.ok(publicReason && privateNote);
    assert.notEqual(publicReason[2], privateNote[2]);
    for (const field of [publicReason, privateNote]) {
        assert.ok(html.includes('for="' + field[1] + '"') && html.includes('id="' + field[2] + '"'));
    }
    assert.match(html, /pattern="\[a-z\]\[a-z0-9_\]\{0,39\}"/);
    assert.match(html, /Berdasarkan metadata|berdasarkan metadata/);
    assert.match(html, /Catatan privat Admin \(opsional\)/);
    assert.doesNotMatch(privateNote[0], /required/);
    assert.match(html, /<button[^>]*type="submit"[^>]*disabled=""[^>]*>Konfirmasi dan tolak/);
});

test('preview loader aborts a prior attempt, ignores its late result and revokes the current Blob on close', async () => {
    const preview = previewFixture();
    assert.deepEqual(preview.requests, []);
    const stopFirst = preview.open(familyInvoice.id, pendingAttempt.id);
    stopFirst();
    const stopSecond = preview.open(familyInvoice.id, rejectedAttempt.id);
    assert.deepEqual(preview.requests.map(({ invoiceId, paymentId }) => [invoiceId, paymentId]), [
        [familyInvoice.id, pendingAttempt.id],
        [familyInvoice.id, rejectedAttempt.id],
    ]);
    assert.equal(preview.requests[0].signal.aborted, true);
    assert.equal(preview.requests[1].signal.aborted, false);
    preview.requests[1].resolve(new Blob(['second'], { type: 'image/png' }));
    await nextTurn();
    assert.equal(preview.urls.length, 1);
    assert.equal(await resolveObjectURL(preview.urls[0]).text(), 'second');
    preview.requests[0].resolve(new Blob(['stale']));
    await nextTurn();
    assert.equal(preview.urls.length, 1);
    stopSecond();
    stopSecond();
    assert.equal(preview.requests[1].signal.aborted, true);
    assert.equal(resolveObjectURL(preview.urls[0]), undefined);
    assert.deepEqual(preview.errors, []);
});
test('loaded preview is revoked before starting a new invoice, including identical attempt ID in mock', async () => {
    const preview = previewFixture();
    const stopOld = preview.open(familyInvoice.id, pendingAttempt.id);
    preview.requests[0].resolve(new Blob(['first']));
    await nextTurn();
    const oldUrl = preview.urls[0];
    assert.equal(await resolveObjectURL(oldUrl).text(), 'first');
    stopOld();
    assert.equal(resolveObjectURL(oldUrl), undefined);
    const stopNew = preview.open(row.id, pendingAttempt.id);
    assert.deepEqual([preview.requests[1].invoiceId, preview.requests[1].paymentId], [row.id, pendingAttempt.id]);
    stopNew();
});
for (const event of ['silansia:payment-access-lost', 'pagehide']) test('preview cleanup is triggered once by ' + event, async () => {
    const preview = previewFixture();
    const stop = preview.open(familyInvoice.id, rejectedAttempt.id);
    preview.requests[0].resolve(new Blob(['before access loss']));
    await nextTurn();
    assert.ok(resolveObjectURL(preview.urls[0]));
    preview.events.dispatchEvent(new Event(event));
    assert.equal(preview.requests[0].signal.aborted, true);
    assert.equal(resolveObjectURL(preview.urls[0]), undefined);
    assert.equal(preview.accessLost(), 1);
    preview.events.dispatchEvent(new Event(event));
    stop();
    assert.equal(preview.accessLost(), 1);
});
test('preview denial has no Blob or retry and late errors after unmount are ignored', async () => {
    const preview = previewFixture();
    const stop = preview.open(familyInvoice.id, pendingAttempt.id);
    preview.requests[0].reject(unavailableProof);
    await nextTurn();
    assert.deepEqual(preview.errors, [unavailableProof]);
    assert.deepEqual(preview.urls, []);
    assert.equal(preview.requests.length, 1);
    stop();
    const stopNext = preview.open(familyInvoice.id, rejectedAttempt.id);
    stopNext();
    preview.requests[1].reject({ code: 'not_found', status: 404 });
    await nextTurn();
    assert.deepEqual(preview.errors, [unavailableProof]);
    assert.deepEqual(preview.urls, []);
    assert.equal(preview.requests.length, 2);
});
