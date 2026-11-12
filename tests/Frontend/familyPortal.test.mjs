import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { parseAst } from 'rolldown/parseAst';
import focusFormErrors from '../../resources/js/utils/focusFormErrors.js';

const id = '12345678-1234-4234-8234-123456789abc';
const second = '12345678-1234-4234-8234-123456789abd';
const instant = '2026-11-12T01:02:03.123456Z';
const long = 'Nama keluarga fixture ' + 'abcdefghij'.repeat(30);
let vite;
const components = {};
before(async () => {
    vite = await createServer({ root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false }, optimizeDeps: { noDiscovery: true, include: [] } });
    for (const [name, path] of Object.entries({
        Dashboard: 'pages/Family/Dashboard', Index: 'pages/Family/Residents/Index', Show: 'pages/Family/Residents/Show',
        Resident: 'components/Family/FamilyResidentCard', Details: 'components/Family/FamilyResidentDetails',
        Service: 'components/Family/FamilyServiceSummary', Benefits: 'components/Family/FamilyBenefitAvailability', Refresh: 'components/Family/FamilyRefresh',
    })) components[name] = (await vite.ssrLoadModule('/resources/js/' + path + '.jsx')).default;
});
after(async () => { await vite?.close(); });
const text = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
const freeze = (value) => { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; };
async function render(name, props, { role = 'keluarga', userName = long, url = '/keluarga' } = {}) {
    const Page = () => React.createElement(components[name], props);
    const result = await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: { id: second, role, name: userName, home_url: '/keluarga' } }, flash: {}, errors: {} }, url, version: null },
        resolve: () => Page, render: renderToString, setup: ({ App, props: appProps }) => React.createElement(App, appProps),
    });
    return result.body.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
}
function catalog(code = 'vip') {
    return { room_type: code === 'vip' ? 'private' : 'shared', instant_verified_emergency: true, verified_incident_summary: true,
        monthly_gait_report: true, weekly_gait_report: code !== 'class_3', daily_gait_report: code === 'vip', family_live_cctv: code === 'vip' };
}
function service(state = 'staying', code = 'vip') {
    const valid = ['staying', 'enrollment_only'].includes(state);
    return { state, catalog_benefits: valid ? catalog(code) : null,
        enrollment: valid ? { started_at: '2026-01-01T01:02:03.654321Z', monthly_price: '1234567.89', service_class: { code, name: 'Kelas fixture ' + code, room_type: code === 'vip' ? 'private' : 'shared' } } : null,
        stay: state === 'staying' ? { room_code: 'UJI-01', room_name: 'Kamar fixture panjang ' + 'x'.repeat(150), started_at: '2026-02-01T01:02:03.654321Z' } : null };
}
function resident(overrides = {}) { return { id, name: long, birth_date: '1950-01-02', age: 73, is_active: true, service: service(), ...overrides }; }
const page = (data = [], overrides = {}) => ({ data, total: data.length, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null, ...overrides });
const filters = { search: '', status: '', per_page: 15 };
const summary = { residents: 6, active_residents: 4, staying: 3, enrolled: 5, needs_review: 1, without_service: 0 };
function props(name) {
    if (name === 'Dashboard') return { summary, residents: [resident()], as_of: instant };
    if (name === 'Index') return { residents: page([resident(), resident({ id: second, name: 'Nama kedua', is_active: false, service: service('enrollment_only') })]), filters, as_of: instant, errors: {} };
    return { resident: resident(), as_of: instant };
}
const inputs = (html) => [...html.matchAll(/<(?:input|select)\b[^>]*>/g)].map((match) => match[0]);

for (const name of ['Dashboard', 'Index', 'Show']) {
    test('portal aktif menjaga props, h1 tunggal, shell dan nama panjang: ' + name, async () => {
        const value = freeze(props(name)), before = JSON.stringify(value);
        const html = await render(name, value, { userName: name === 'Dashboard' ? long : 'Akun keluarga fixture' });
        assert.equal(JSON.stringify(value), before);
        assert.match(html, /class="ui-family-page"/);
        assert.match(html, /class="ui-family-header"/);
        assert.equal((html.match(/<h1\b/g) ?? []).length, 1);
        assert.ok(text(html).includes(long));
        assert.match(html, /href="\/keluarga"/);
        assert.match(html, /href="\/keluarga\/residents"/);
        assert.doesNotMatch(html, /href="\/(?:admin|monitoring|notifications)|aria-label="Notifikasi|ui-notification-bell/);
        assert.doesNotMatch(html, /<video\b|<iframe\b|<canvas\b|<img\b|truncate|line-clamp|text-ellipsis/);
        assert.doesNotMatch(html, /Memuat…|aria-busy="true"|type="file"/);
        const timestamps = [...html.matchAll(/\bdatetime="([^"]+)"/gi)].map((match) => match[1]);
        assert.ok(timestamps.includes(instant));
        assert.match(text(html), /WIB/);
    });
}
test('dashboard mendahulukan lansia; kelima angka berasal dari summary, bukan panjang preview', async () => {
    const value = { summary: { residents: 41, active_residents: 31, staying: 21, enrolled: 22, needs_review: 11 }, residents: [resident()], as_of: instant };
    const html = await render('Dashboard', value);
    assert.ok(html.indexOf('Lansia keluarga Anda') < html.indexOf('Ringkasan administratif'));
    for (const number of [41, 31, 21, 22, 11]) assert.match(html, new RegExp('<dd>' + number + '</dd>'));
    assert.match(text(html), /Menampilkan 1 dari 41 lansia/);
    assert.match(text(html), /perlu diperiksa Admin/);
    assert.equal((html.match(/class="ui-family-counts"/g) ?? []).length, 1);
});
test('preview enam tetap berurutan sesuai backend dan akses daftar lengkap tetap tersedia', async () => {
    const residents = Array.from({ length: 6 }, (_, index) => resident({ id: id.slice(0, -1) + index, name: 'Urutan server ' + (6 - index) }));
    const html = await render('Dashboard', { summary: { ...summary, residents: 9, needs_review: 0 }, residents, as_of: instant });
    assert.equal((html.match(/class="ui-family-resident"/g) ?? []).length, 6);
    assert.ok(html.indexOf('Urutan server 6') < html.indexOf('Urutan server 1'));
    assert.match(text(html), /Menampilkan 6 dari 9 lansia/);
    assert.doesNotMatch(html, /ui-family-notice--warning/);
});
test('dashboard kosong menjelaskan hubungan, tanpa pendaftaran atau kesimpulan aman', async () => {
    const html = await render('Dashboard', { summary: { residents: 0, active_residents: 0, staying: 0, enrolled: 0, needs_review: 0 }, residents: [], as_of: instant });
    assert.match(html, /class="ui-empty-state"/);
    assert.match(text(html), /Belum ada lansia yang terhubung/);
    assert.match(text(html), /Hubungi Admin panti/);
    assert.doesNotMatch(html, /href="[^"]*(?:create|register|cctv|incidents|billing|gait)/);
    assert.match(text(html), /Tidak adanya data bukan bukti kondisi aman/);
});
test('multi-resident list memakai hanya IDs dari data yang diberikan, mempertahankan urutan dan status', async () => {
    const value = props('Index');
    const html = await render('Index', value, { url: '/keluarga/residents' });
    const list = html.match(/<ul class="ui-family-residents">([\s\S]*?)<\/ul>/)?.[1];
    assert.ok(list);
    assert.ok(list.includes(long));
    assert.ok(list.includes('Nama kedua'));
    assert.ok(list.indexOf(long) < list.indexOf('Nama kedua'));
    for (const item of value.residents.data) assert.ok(html.includes('href="/keluarga/residents/' + item.id + '"'));
    assert.match(text(html), /Lansia aktif administratif/);
    assert.match(text(html), /Lansia nonaktif administratif/);
    assert.match(text(html), /73 tahun/); // No client-side age recalculation.
    assert.match(text(html), /belum ada masa tinggal aktif/);
    assert.equal((html.match(/>Lihat detail<\/a>/g) ?? []).length, 2);
});
for (const [name, value, expected] of [
    ['tanpa hubungan', { residents: page(), filters, as_of: instant }, /Belum ada lansia yang terhubung/],
    ['filter tanpa hasil', { residents: page(), filters: { ...filters, search: 'fixture' }, as_of: instant }, /Belum ada lansia yang sesuai di halaman ini/],
    ['halaman di luar hasil', { residents: page([], { total: 20, current_page: 4, last_page: 2 }), filters, as_of: instant }, /Belum ada lansia yang sesuai di halaman ini/],
]) {
    test('empty list membedakan ' + name, async () => {
        const html = await render('Index', value);
        assert.match(text(html), expected);
        assert.match(html, /class="ui-empty-state"/);
        assert.doesNotMatch(html, /href="[^"]*\/(?:create|register)"/);
    });
}
test('filter dan pagination mempertahankan whitelist, pilihan 15/30/50 dan URL server', async () => {
    const next = '/keluarga/residents?search=dua%20kata&status=inactive&per_page=30&page=3';
    const value = { ...props('Index'), filters: { search: 'dua kata', status: 'inactive', per_page: 30 } };
    value.residents = page(value.residents.data, { total: 65, current_page: 2, last_page: 3, prev_page_url: '/keluarga/residents?page=1', next_page_url: next });
    const html = await render('Index', value);
    assert.deepEqual(inputs(html).map((tag) => tag.match(/name="([^"]+)"/)?.[1]), ['search', 'status', 'per_page']);
    assert.match(html, /maxLength="100"/i);
    assert.match(html, /<option selected="">30<\/option>/);
    assert.match(html, /<option>15<\/option>/);
    assert.match(html, /<option>50<\/option>/);
    assert.ok(html.includes(next.replaceAll('&', '&amp;')));
    assert.match(text(html), /65 data · Halaman 2 dari 3/);
    assert.doesNotMatch(html, /name="(?:family_id|sort|room_id|service_class_id)"/);
});
test('error filter terhubung, aman sebagai teks, dan summary bisa difokuskan', async () => {
    const html = await render('Index', { ...props('Index'), errors: { search: '<script>fixture</script>', status: 'Status tidak valid', per_page: 'Jumlah tidak valid', filters: 'Filter tidak diizinkan' } });
    assert.match(html, /tabindex="-1" role="alert"/);
    assert.match(html, /&lt;script&gt;fixture&lt;\/script&gt;/);
    for (const name of ['search', 'status', 'per_page']) {
        const tag = inputs(html).find((input) => input.includes('name="' + name + '"'));
        assert.match(tag, /aria-invalid="true"/);
        const errorId = tag.match(/aria-describedby="([^"]+)"/)[1];
        assert.ok(html.includes('id="' + errorId + '"'));
    }
    assert.match(text(html), /Filter tidak diizinkan/);
});

for (const state of ['none', 'enrollment_only', 'staying', 'needs_review', 'legacy_unknown', 'constructor']) {
    test('service state dipresentasikan tanpa inferensi histori: ' + state, async () => {
        const value = service(state);
        const html = await render('Show', { resident: resident({ service: value }), as_of: instant });
        assert.match(text(html), /Layanan dan kamar saat ini/);
        if (['staying', 'enrollment_only'].includes(state)) {
            assert.ok(text(html).includes(value.enrollment.service_class.name));
            assert.match(text(html), /Rp 1\.234\.567,89/);
            assert.match(text(html), /bukan tagihan atau status pembayaran/);
            assert.ok(html.includes(value.enrollment.started_at));
        } else {
            assert.doesNotMatch(text(html), /Rp |Kelas fixture|Kamar fixture panjang/);
            assert.match(text(html), /Ketentuan kelas belum dapat ditampilkan/);
        }
        if (state === 'staying') {
            assert.ok(text(html).includes(value.stay.room_name));
            assert.ok(html.includes(value.stay.started_at));
            assert.match(text(html), /tidak menunjukkan lokasi fisik/);
        } else if (state === 'enrollment_only') assert.match(text(html), /Belum ada masa tinggal aktif/);
        if (state === 'needs_review') assert.match(text(html), /Informasi layanan belum dapat dipastikan/);
        if (['legacy_unknown', 'constructor'].includes(state)) assert.match(text(html), /Informasi layanan belum tersedia/);
    });
}
for (const [amount, expected] of [[null, 'Tarif belum tercatat'], ['0.00', 'Rp 0,00'], ['123456789012.34', 'Rp 123.456.789.012,34']]) {
    test('snapshot tarif tetap ' + amount + ', tidak memakai tarif master', async () => {
        const value = service();
        value.enrollment.monthly_price = amount;
        value.enrollment.service_class.monthly_price = '999.99';
        const html = await render('Service', { service: freeze(value) });
        assert.ok(text(html).includes(expected));
        assert.doesNotMatch(text(html), /Rp 999,99/);
    });
}
test('histori tersisip tidak dijadikan layanan sekarang atau dibuka bagi keluarga', async () => {
    const value = resident({ service: { ...service('none'), stays: [{ room_name: 'PRIVATE_HISTORY_ROOM' }], enrollments: [{ monthly_price: '999.99' }] }, stays: [{ room_name: 'PRIVATE_HISTORY_ROOM' }], fall_incidents: ['PRIVATE_INCIDENT'], gait_observations: ['PRIVATE_GAIT'] });
    const html = await render('Show', { resident: value, as_of: instant });
    assert.doesNotMatch(html, /PRIVATE_|Rp 999/);
    assert.match(text(html), /Histori masa tinggal dan layanan belum tersedia/);
});
test('needs_review menahan detail dan katalog meski props tak konsisten tersisip', async () => {
    const value = { ...service(), state: 'needs_review' };
    const html = await render('Show', { resident: resident({ service: value }), as_of: instant });
    assert.doesNotMatch(html, /Kelas fixture|Kamar fixture panjang|Rp |Tercantum dalam kelas/);
    assert.match(text(html), /Hubungi Admin panti untuk pemeriksaan/);
});
test('konteks layanan parsial tidak menghasilkan harga/jenis kamar rekaan', async () => {
    const value = { ...service(), enrollment: {}, stay: { room_code: null, room_name: null, started_at: null } };
    const html = await render('Service', { service: value });
    assert.match(text(html), /Jenis kamar belum tersedia/);
    assert.match(text(html), /Tarif belum tercatat/);
    assert.match(text(html), /Kode belum tersedia/);
    assert.doesNotMatch(text(html), /Rp undefined|NaN|Invalid Date/);
});
test('tanpa enrollment pada payload parsial, kamar dan katalog tidak disimpulkan berlaku', async () => {
    const value = { ...service(), enrollment: null };
    const html = await render('Show', { resident: resident({ service: value }), as_of: instant });
    assert.doesNotMatch(html, /Kamar fixture panjang|Tercantum dalam kelas|Rp /);
    assert.match(text(html), /Informasi layanan belum tersedia/);
});
test('flag katalog yang tidak tersedia tidak disimpulkan sebagai tidak tercantum', async () => {
    const html = await render('Benefits', { catalog: {} });
    assert.equal((html.match(/>Ketentuan belum tersedia<\/span>/g) ?? []).length, 6);
    assert.doesNotMatch(html, />Tidak tercantum dalam kelas<\/span>/);
});
for (const [code, entitled, absent] of [['vip', 6, 0], ['class_2', 4, 2], ['class_3', 3, 3]]) {
    test('katalog ' + code + ' tetap terpisah dari ketersediaan runtime', async () => {
        const html = await render('Benefits', { catalog: freeze(catalog(code)) });
        assert.equal((html.match(/>Tercantum dalam kelas<\/span>/g) ?? []).length, entitled);
        assert.equal((html.match(/>Tidak tercantum dalam kelas<\/span>/g) ?? []).length, absent);
        assert.equal((html.match(/>Belum tersedia di portal<\/span>/g) ?? []).length, 6);
        assert.match(text(html), /terlepas dari pembayaran/);
        assert.doesNotMatch(html, /<a\b|<button\b|href=/);
        assert.match(text(html), /bukan bukti kondisi aman atau pembayaran lunas/);
    });
}
test('NULL/unknown data memberi label, tanpa fallback ID atau field keluarga lain', async () => {
    const value = { name: '', birth_date: null, age: null, is_active: null, service: null, family: { name: 'PRIVATE_OTHER_FAMILY', email: 'PRIVATE_EMAIL' } };
    const html = await render('Details', { resident: value });
    assert.match(text(html), /Belum tersedia/);
    assert.doesNotMatch(html, /PRIVATE_|Invalid Date|NaN|undefined/);
    const card = await render('Resident', { resident: value, showAge: true });
    assert.match(text(card), /Status administratif belum tersedia/);
    assert.match(text(card), /Informasi layanan belum tersedia/);
    assert.doesNotMatch(card, /href=|\/undefined|\/null/);
    const refresh = await render('Refresh', { asOf: null });
    assert.match(text(refresh), /Tidak tersedia/);
    assert.doesNotMatch(refresh, /datetime=|Memuat…|role="alert"/i);
});
test('biodata invalid tanggal tidak melempar atau mengarang umur', async () => {
    const html = await render('Details', { resident: resident({ birth_date: 'not-a-date', age: null }) });
    assert.match(text(html), /Tanggal lahir Belum tersedia/);
    assert.match(text(html), /Usia saat data dimuat Belum tersedia/);
    assert.doesNotMatch(html, /datetime="not-a-date"|\bNaN\b|Invalid Date/i);
});
test('tautan detail tetap mempunyai nama aksesibel ketika nama lansia belum tersedia', async () => {
    const html = await render('Resident', { resident: resident({ name: '' }) });
    assert.match(html, /aria-label="Lihat detail lansia"/);
    assert.match(text(html), /Nama belum tersedia/);
    assert.ok(html.includes('href="/keluarga/residents/' + id + '"'));
});
test('detail hanya merender whitelist, tanpa source kamera, notes, raw AI, jurnal atau URL staf', async () => {
    const value = resident({ family: { name: 'PRIVATE_FAMILY', email: 'PRIVATE_EMAIL' }, medical_notes: 'PRIVATE_MEDICAL', notes: 'PRIVATE_NOTES',
        camera: { source_reference: 'PRIVATE_CAMERA' }, ai_detections: ['PRIVATE_AI'], incident_actions: ['PRIVATE_JOURNAL'], payments: ['PRIVATE_PAYMENT'], family_id: 'PRIVATE_OWNER' });
    const html = await render('Show', { resident: value, as_of: instant });
    assert.doesNotMatch(html, /PRIVATE_|href="\/(?:admin|monitoring|notifications)|<video|type="file"/);
    assert.doesNotMatch(html, /href="[^"]*\/(?:edit|create|cctv|gait|incidents|billing|payments)"/);
    assert.equal(inputs(html).length, 0);
});
test('nama/error di-escape, tidak disembunyikan atau menjadi HTML', async () => {
    const html = await render('Show', { resident: resident({ name: '<img src=x onerror=fixture>' }), as_of: instant });
    assert.match(html, /&lt;img src=x onerror=fixture&gt;/);
    assert.doesNotMatch(html, /<img\b|truncate|line-clamp/);
});
test('label dan heading berjenjang terhubung dengan target yang ada', async () => {
    const html = await render('Index', props('Index'));
    for (const match of html.matchAll(/<label[^>]*for="([^"]+)"/g)) assert.ok(html.includes('id="' + match[1] + '"'));
    const show = await render('Show', props('Show'));
    const portal = show.match(/<div class="ui-family-page">([\s\S]*?)<\/main>/)?.[1];
    assert.ok(portal);
    for (const match of portal.matchAll(/aria-labelledby="([^"]+)"/g)) assert.ok(portal.includes('id="' + match[1] + '"'));
    assert.match(show, /<h2[^>]*>Biodata lansia<\/h2>/);
    assert.match(show, /<h3>Layanan yang tercatat<\/h3>/);
    assert.match(show, /<h3>Kamar yang tercatat<\/h3>/);
});
test('CSS keluarga terisolasi, reflow dan target44px tanpa animasi/overflow hiding', () => {
    const css = fs.readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const family = css.slice(css.indexOf('/* UI-6:'));
    assert.match(family, /--color-ui-primary/);
    assert.match(family, /minmax\(0, 1fr\)/);
    assert.match(family, /flex-wrap: wrap/);
    assert.match(family, /min-height: var\(--spacing-ui-control\)/);
    assert.match(family, /overflow-wrap: anywhere/);
    assert.match(family, /:focus-visible/);
    assert.match(family, /@media \(min-width: 48rem\)/);
    assert.match(family, /@media \(min-width: 64rem\)/);
    assert.doesNotMatch(family, /\.ui-shell|\.ui-management|\.ui-incident|animation:|transition:|overflow: hidden|text-overflow:|line-clamp|#[0-9a-f]{6}/);
});

function walk(node, visit) {
    if (!node || typeof node !== 'object') return;
    if (node.type) visit(node);
    for (const value of Object.values(node)) {
        if (Array.isArray(value)) value.forEach((child) => walk(child, visit));
        else if (value && typeof value === 'object') walk(value, visit);
    }
}
function actualFunction(path, name, bindings) {
    const code = fs.readFileSync(new URL('../../resources/js/' + path + '.jsx', import.meta.url), 'utf8');
    const ast = parseAst(code, { lang: 'jsx' }, path + '.jsx');
    let found;
    walk(ast, (node) => {
        if (node.type === 'FunctionDeclaration' && node.id?.name === name) found = node;
        if (name === 'effect' && node.type === 'CallExpression' && node.callee.name === 'useEffect') found = node.arguments[0];
    });
    assert.ok(found);
    return Function(...Object.keys(bindings), 'return (' + code.slice(found.start, found.end) + ')')(...Object.values(bindings));
}
for (const search of ['', '0', '%_', 'dua kata']) {
    test('GET filter aktual mempertahankan payload/options/error/loading: ' + JSON.stringify(search), () => {
        const calls = [], loading = [], errors = [];
        const bindings = { search, status: 'inactive', perPage: 30, setError: (value) => errors.push(value), setLoading: (value) => loading.push(value), router: { get: (...args) => calls.push(args) } };
        let prevented = false;
        actualFunction('pages/Family/Residents/Index', 'submit', bindings)({ preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(calls.length, 1);
        const [url, payload, options] = calls[0];
        assert.equal(url, '/keluarga/residents');
        assert.deepEqual(payload, { search, status: 'inactive', per_page: 30 });
        assert.equal(options.preserveState, true);
        assert.equal(options.replace, true);
        assert.deepEqual(Object.keys(options).sort(), ['onFinish', 'onHttpException', 'onNetworkError', 'onStart', 'preserveState', 'replace'].sort());
        assert.deepEqual(loading, []);
        assert.deepEqual(errors, ['']);
        options.onStart();
        assert.deepEqual(loading, [true]);
        assert.equal(options.onNetworkError(), false);
        assert.equal(options.onHttpException(), false);
        options.onFinish();
        assert.deepEqual(loading, [true, false]);
        assert.equal(errors.at(-1), 'Daftar belum dapat dimuat. Coba kembali.');
        assert.equal(payload.search, search);
    });
}
test('refresh aktual tetap reload manual preserveScroll, tidak polling atau navigasi mutation', () => {
    const calls = [], loading = [], errors = [];
    const bindings = { setError: (value) => errors.push(value), setLoading: (value) => loading.push(value), router: { reload: (options) => calls.push(options) } };
    actualFunction('components/Family/FamilyRefresh', 'refresh', bindings)();
    assert.equal(calls.length, 1);
    const options = calls[0];
    assert.equal(options.preserveScroll, true);
    assert.deepEqual(Object.keys(options).sort(), ['onFinish', 'onHttpException', 'onNetworkError', 'onStart', 'preserveScroll'].sort());
    assert.deepEqual(loading, []);
    options.onStart();
    assert.equal(options.onNetworkError(), false);
    assert.equal(options.onHttpException(), false);
    options.onFinish();
    assert.deepEqual(loading, [true, false]);
    assert.deepEqual(errors, ['', 'Data belum dapat diperbarui. Coba kembali atau hubungi Admin panti.', 'Data belum dapat diperbarui. Coba kembali atau hubungi Admin panti.']);
});
test('effect fokus aktual menuju kontrol atau summary, tidak mencuri fokus saat loading/bersih', () => {
    let focused;
    const summary = { focus: () => { focused = 'summary'; } };
    const form = { elements: { namedItem: (field) => field === 'search' ? { focus: () => { focused = field; } } : null } };
    const bindings = { loading: false, hasErrors: true, error: '', errors: { search: 'Periksa pencarian' }, formRef: { current: form }, errorRef: { current: summary }, focusFormErrors };
    const effect = (overrides = {}) => actualFunction('pages/Family/Residents/Index', 'effect', { ...bindings, ...overrides })();
    effect();
    assert.equal(focused, 'search');
    effect({ errors: { filters: 'Filter salah' } });
    assert.equal(focused, 'summary');
    focused = undefined;
    effect({ loading: true });
    assert.equal(focused, undefined);
    effect({ hasErrors: false });
    assert.equal(focused, undefined);
    effect({ error: 'Request gagal' });
    assert.equal(focused, 'summary');
    focused = undefined;
    actualFunction('components/Family/FamilyRefresh', 'effect', { error: '', errorRef: { current: summary } })();
    assert.equal(focused, undefined);
    actualFunction('components/Family/FamilyRefresh', 'effect', { error: 'Request gagal', errorRef: { current: summary } })();
    assert.equal(focused, 'summary');
});
