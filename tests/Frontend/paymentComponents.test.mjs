import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from './isolatedVite.mjs';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

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
test('family list does not expose Admin owner or private fields', async () => {
    const html = await list({ role: 'keluarga', status: '', page: { data: [{ ...row, status: 'unpaid' }], page: 1, has_more: false } });
    assert.match(html, /Tagihan keluarga/); assert.match(html, /Belum lunas/); assert.match(html, new RegExp('/keluarga/tagihan/' + row.id));
    assert.doesNotMatch(html, /Penanggung sintetis|MUST-NOT-RENDER/);
});
test('empty, loading and errors remain distinct and accessible', async () => {
    assert.match(await list({ page: { data: [], page: 1, has_more: false } }), /Belum ada data/);
    const loading = await list({ loading: true }); assert.match(loading, /role="status"/); assert.doesNotMatch(loading, /Belum ada data/);
    const error = await list({ error: { code: 'forbidden', correlation: 'SYNTHETIC-ID' } }); assert.match(error, /role="alert"/); assert.match(error, /SYNTHETIC-ID/); assert.doesNotMatch(error, /Belum ada data/);
});
for (const status of ['pending','verified','rejected']) test('payment badge ' + status + ' preserves explicit status', async () => {
    const html = await render('PaymentStatus', { status }); assert.match(html, new RegExp({ pending: 'Menunggu verifikasi', verified: 'Terverifikasi', rejected: 'Ditolak' }[status]));
    if (status === 'pending') assert.doesNotMatch(html, /Lunas/);
});
for (const state of ['unknown','unresolved','conflict','manual_hold','session_lost','resolved']) test('recovery notice ' + state + ' never invents financial result', async () => {
    const html = await render('PaymentFlowNotice', { state: { name: state }, onReconcile: noop, onCheck: noop });
    assert.match(html, /aria-live="polite"/); assert.doesNotMatch(html, /Pembayaran gagal|Terverifikasi|Tindakan tercatat/);
    assert.equal(html.includes('Periksa hasil tindakan yang sama'), ['unknown','unresolved','conflict'].includes(state));
    if (state === 'manual_hold') assert.match(html, /Context asli sudah tidak tersedia/);
});
test('upload form starts unconfirmed, bounded, disabled and without financial fields', async () => {
    const html = await render('ProofSubmissionForm', { disabled: false, onSubmit: noop });
    for (const value of ['type="file"', 'JPEG/PNG', '2 MiB', '2.048 px', '4 juta', 'type="checkbox"', 'disabled=""']) assert.ok(html.includes(value));
    assert.doesNotMatch(html, /checked=""|name="amount"|name="owner_id"|command_context|proof_path/);
});
