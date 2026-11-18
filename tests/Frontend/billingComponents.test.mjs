import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from './isolatedVite.mjs';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { actor, listInvoice, detailInvoice, legacyInvoice, pagination, preview } from './billingFixtures.mjs';
import { navigationForRole, navigationContext } from '../../resources/js/utils/navigation.js';

let vite; const pages = {};
before(async () => {
    vite = await createServer({ root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false, plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false }, optimizeDeps: { noDiscovery: true, include: [] } });
    for (const name of ['Index', 'Create', 'Show']) pages[name] = (await vite.ssrLoadModule('/resources/js/pages/Admin/Billing/Invoices/' + name + '.jsx')).default;
    pages.Preview = (await vite.ssrLoadModule('/resources/js/components/Billing/InvoiceParts.jsx')).PreviewSummary;
});
after(async () => { await vite?.close(); });
async function render(name, props = {}) {
    return (await createInertiaApp({ page: { component: 'Fixture', props: { auth: { user: { id: actor, role: 'admin', name: 'Admin fixture', home_url: '/admin' } }, flash: {}, errors: {}, ...props }, url: '/admin/billing/invoices', version: null }, resolve: () => pages[name], render: renderToString, setup: ({ App, props }) => React.createElement(App, props) })).body.replace(/<script\b[^>]*data-page="app"[\s\S]*?<\/script>/g, '');
}
test('all three exact Inertia page files resolve; Admin navigation only', async () => {
    for (const role of ['admin', 'perawat', 'keluarga']) assert.equal(navigationForRole(role).flatMap((g) => g.items).some((i) => i.href === '/admin/billing/invoices'), role === 'admin');
    assert.equal(navigationContext('admin', '/admin/billing/invoices/create').title, 'Terbitkan invoice');
    const html = await render('Create'); assert.match(html, /Terbitkan invoice/); assert.match(html, /Memverifikasi konteks/); assert.match(html, /disabled/); assert.doesNotMatch(html, /confirmation_token/);
});
test('index exact amount/XSS safe escaping; filters/sorting/pagination and empty differ from error', async () => {
    const html = await render('Index', { invoices: pagination([listInvoice]), filters: {} });
    assert.match(html, /999\.999\.999\.999,99/); assert.match(html, /&lt;img/); assert.doesNotMatch(html, /<img src=x/);
    for (const text of ['UUID lansia', 'Bulan layanan', 'Status invoice', 'Urutan', 'Sebelumnya', 'Berikutnya', 'Detail']) assert.ok(html.includes(text));
    const empty = await render('Index', { invoices: pagination() }); assert.match(empty, /Belum ada invoice/);
    const broken = await render('Index', { invoices: { ...pagination(), data: [{ ...listInvoice, amount: 1 }] } }); assert.match(broken, /tidak dapat diverifikasi/); assert.doesNotMatch(broken, /Belum ada invoice yang sesuai/);
});
test('managed detail shows only explicit snapshot/current labels; legacy never fabricates owner', async () => {
    const managed = await render('Show', { invoice: detailInvoice });
    assert.match(managed, /nama saat ini/); assert.match(managed, /UUID penanggung berasal dari snapshot/); assert.match(managed, /Riwayat penerbitan immutable/);
    for (const text of ['source_fingerprint', 'confirmation_token', 'content_hash', 'proof_path', '>Edit<', '>Refund<', '>Bayar<']) assert.ok(!managed.includes(text));
    const legacy = await render('Show', { invoice: legacyInvoice }); assert.match(legacy, /Owner historis tidak tersedia/); assert.doesNotMatch(legacy, /Keluarga fixture/);
    const broken = await render('Show', { invoice: { ...detailInvoice, current_revision: null } }); assert.match(broken, /integritasnya/);
});
for (const decision of ['ISSUE', 'HOLD', 'SKIP', 'REJECT']) {
    test('preview ' + decision + ' never says issued or invents due/grace', async () => {
        const p = decision === 'ISSUE' ? { decision, reason: preview.reason, calculation: preview.calculation } : { decision, reason: decision === 'SKIP' ? 'no_chargeable_service' : decision === 'HOLD' ? 'null_monthly_rate' : 'billing_month_not_ended', calculation: null };
        const html = await render('Preview', { preview: p }); assert.match(html, /Preview belum menerbitkan invoice/); assert.match(html, new RegExp(decision)); assert.doesNotMatch(html, /2026-11-22|2026-11-25|SYNTHETIC-OPAQUE/);
    });
}
