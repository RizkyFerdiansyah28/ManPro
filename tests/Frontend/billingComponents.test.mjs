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
import { invoiceDisplayStatus, millisecondsUntilNextWibDay, wibDate } from '../../resources/js/utils/billing/invoiceStatus.js';

let vite; const pages = {};
before(async () => {
    vite = await createServer({ root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false, plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false }, optimizeDeps: { noDiscovery: true, include: [] } });
    for (const name of ['Index', 'Create', 'Show']) pages[name] = (await vite.ssrLoadModule('/resources/js/pages/Admin/Billing/Invoices/' + name + '.jsx')).default;
    const parts = await vite.ssrLoadModule('/resources/js/components/Billing/InvoiceParts.jsx');
    pages.Preview = parts.PreviewSummary;
    pages.Status = parts.InvoiceStatus;
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
    const empty = await render('Index', { invoices: pagination() }); assert.match(empty, /Belum ada invoice diterbitkan/);
    const filtered = await render('Index', { invoices: pagination(), filters: { status: 'paid' } }); assert.match(filtered, /Belum ada invoice yang sesuai/);
    const emptyPage = await render('Index', { invoices: { ...pagination(), total: 1, current_page: 2 } }); assert.match(emptyPage, /Halaman ini tidak memuat invoice/);
    const broken = await render('Index', { invoices: { ...pagination(), data: [{ ...listInvoice, amount: 1 }] } }); assert.match(broken, /tidak dapat diverifikasi/); assert.doesNotMatch(broken, /Belum ada invoice yang sesuai/);
});

const deadlines = { status: 'unpaid', due_date: '2028-02-29', grace_period_ends_on: '2028-03-03' };
for (const [instant, day, status, label] of [
    ['2028-02-28T16:59:59.999Z', '2028-02-28', 'not_due', 'Belum jatuh tempo'],
    ['2028-02-28T17:00:00.000Z', '2028-02-29', 'not_due', 'Belum jatuh tempo'],
    ['2028-02-29T16:59:59.999Z', '2028-02-29', 'not_due', 'Belum jatuh tempo'],
    ['2028-02-29T17:00:00.000Z', '2028-03-01', 'in_grace', 'Dalam masa tenggang'],
    ['2028-03-03T16:59:59.999Z', '2028-03-03', 'in_grace', 'Dalam masa tenggang'],
    ['2028-03-03T17:00:00.000Z', '2028-03-04', 'past_grace', 'Lewat masa tenggang'],
]) test(`indikator ${label} pada ${instant} mengikuti tanggal WIB`, async () => {
    const today = wibDate(new Date(instant));
    assert.equal(today, day);
    assert.equal(invoiceDisplayStatus(Object.freeze(deadlines), today), status);
    assert.match(await render('Status', { invoice: deadlines, today }), new RegExp(`>${label}<`));
});

test('jadwal penyegaran memakai satu milidetik sebelum dan satu hari tepat pada tengah malam WIB', () => {
    assert.equal(millisecondsUntilNextWibDay(new Date('2028-03-03T16:59:59.999Z')), 1);
    assert.equal(millisecondsUntilNextWibDay(new Date('2028-03-03T17:00:00.000Z')), 86_400_000);
});

test('status terminal mengalahkan tanggal lampau atau tanggal yang tidak tersedia', async () => {
    for (const [status, label] of [['paid', 'Lunas'], ['cancelled', 'Dibatalkan']]) {
        for (const dates of [deadlines, { due_date: null }]) {
            const invoice = { ...dates, status };
            const html = await render('Status', { invoice, today: '2028-04-01' });
            assert.match(html, new RegExp(`>${label}<`));
            assert.doesNotMatch(html, /masa tenggang|Jadwal tagihan tidak tersedia/);
        }
    }
});

test('tanggal hilang, tidak sah, atau terbalik mempertahankan status belum lunas tanpa menebak keterlambatan', async () => {
    for (const dates of [{}, { due_date: deadlines.due_date }, { grace_period_ends_on: deadlines.grace_period_ends_on },
        { ...deadlines, due_date: '2027-02-29' }, { ...deadlines, grace_period_ends_on: '2028-02-28' },
        { ...deadlines, grace_period_ends_on: '2028-03-03T00:00:00Z' }]) {
        const html = await render('Status', { invoice: { status: 'unpaid', ...dates }, today: '2028-04-01', legacy: true });
        assert.match(html, />Belum lunas</);
        assert.match(html, /Jadwal tagihan tidak tersedia/);
        assert.match(html, /Legacy · baca saja/);
        assert.doesNotMatch(html, /masa tenggang|jatuh tempo/);
    }
});

test('daftar dan detail Admin sama-sama menggunakan jadwal invoice', async (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-11-22T17:00:00Z') });
    assert.match(await render('Index', { invoices: pagination([listInvoice]) }), />Dalam masa tenggang</);
    assert.match(await render('Show', { invoice: detailInvoice }), />Dalam masa tenggang</);
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
