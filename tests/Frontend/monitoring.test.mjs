import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { initialMonitoringFilters, updateMonitoringRange, updateMonitoringEventType, monitoringRangeLabel } from '../../resources/js/utils/monitoringFilters.js';

const id = '12345678-1234-4234-8234-123456789abc';
const occurred = '2026-11-11T02:03:04.123456Z';
const received = '2026-11-11T02:03:05.654321Z';
const asOf = '2026-11-12T02:03:04.000001Z';
let vite;
const components = {};
before(async () => {
    vite = await createServer({
        root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    for (const name of ['MonitoringDashboard', 'MonitoringStats', 'MonitoringHeader', 'MonitoringFilters', 'MonitoringActivityTable', 'MonitoringSection']) {
        components[name] = (await vite.ssrLoadModule('/resources/js/components/Monitoring/' + name + '.jsx')).default;
    }
    for (const name of ['Records', 'OpenIncidents']) components[name] = (await vite.ssrLoadModule('/resources/js/pages/Monitoring/' + name + '.jsx')).default;
    components.AdminDashboard = (await vite.ssrLoadModule('/resources/js/pages/Admin/Monitoring/Dashboard.jsx')).default;
    components.CaregiverDashboard = (await vite.ssrLoadModule('/resources/js/pages/Caregiver/Monitoring/Dashboard.jsx')).default;
});
after(async () => { await vite?.close(); });

async function render(name, props, { role = 'admin', errors = {}, url = '/admin' } = {}) {
    const Page = () => React.createElement(components[name], props);
    return (await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: { id, role, name: 'Akun uji', home_url: '/' + role } }, flash: {}, errors }, url, version: null },
        resolve: () => Page, render: renderToString,
        setup: ({ App, props: appProps }) => React.createElement(App, appProps),
    })).body;
}
const text = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
const controls = (html) => [...html.matchAll(/<(?:input|select)\b[^>]*\bname="([^"]+)"/g)].map((match) => match[1]);
const freeze = (value) => {
    if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); }
    return value;
};
function record(overrides = {}) {
    return {
        id, event_type: 'fall', occurred_at: occurred, received_at: received,
        camera: { id, name: 'Kamera uji', configuration_url: '/admin/cameras/' + id },
        context: { state: 'tracked', room: { id, code: 'R-UJI', name: 'Kamar uji' }, service_class: { code: 'vip', name: 'VIP uji' } },
        identity: { state: 'unknown', basis: 'unknown' }, gait: null,
        fall: { status: 'pending', is_verified: false, verified_at: null, detail_url: '/monitoring/incidents/' + id },
        ...overrides,
    };
}
function dashboard() {
    return {
        filters: { range: '7d' }, as_of: asOf,
        stats: { fall_detections: 17, gait_observations: 13, pending: 5, acknowledged: 2, resolved: 7, false_alarm: 3, verified: 8 },
        cameras: { registered: 4, active: 3, inactive: 1 },
        open_incidents: { pending: 23, acknowledged: 19 },
        pending_preview: [record({ id: 'old-first', camera: { name: 'Pending urutan pertama' } }), record({ id: 'old-second', camera: { name: 'Pending urutan kedua' } })],
        recent: [record({ id: 'recent', camera: { name: 'Aktivitas terbaru uji' } })],
    };
}
function page(data, overrides = {}) {
    return {
        data, total: 31, current_page: 2, last_page: 3,
        prev_page_url: '/monitoring/records?range=7d&class_code=class_2&page=1',
        next_page_url: '/monitoring/records?range=7d&class_code=class_2&page=3',
        ...overrides,
    };
}

for (const [role, component] of [['admin', 'AdminDashboard'], ['perawat', 'CaregiverDashboard']]) {
    test('dashboard ' + role + ' memprioritaskan antrean dan mempertahankan props/urutan server', async () => {
        const props = freeze(dashboard());
        const before = JSON.stringify(props);
        const html = await render(component, props, { role, url: '/' + role });
        assert.equal(JSON.stringify(props), before);
        assert.ok(html.indexOf('Antrean insiden terbuka</h2>') < html.indexOf('Aktivitas periode terpilih</h2>'));
        assert.ok(html.indexOf('Aktivitas periode terpilih</h2>') < html.indexOf('Aktivitas terbaru</h2>'));
        assert.ok(html.indexOf('Pending urutan pertama') < html.indexOf('Pending urutan kedua'));
        assert.match(html, /href="\/monitoring\/open-incidents\?status=pending"/);
        assert.match(html, /href="\/monitoring\/open-incidents\?status=acknowledged"/);
        assert.match(html, /ui-monitoring-queue-count__value">23</);
        assert.match(html, /ui-monitoring-queue-count__value">19</);
        assert.match(html, /href="\/monitoring\/records\?range=7d"/);
        assert.match(html, /href="\/monitoring\/incidents\/12345678/);
        assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, 1);
        assert.doesNotMatch(html, /kondisi aman|kondisi stabil|kamera online|sistem sinkron/i);
        const buttons = [...html.matchAll(/<button\b[^>]*>(.*?)<\/button>/g)].map((match) => text(match[1]));
        assert.ok(buttons.every((label) => !/acknowledge|verifikasi|resolve|selesaikan|darurat/i.test(label)));
        if (role === 'admin') {
            assert.match(html, /Pengawasan Admin · baca saja/);
            assert.ok(html.indexOf('Aktivitas terbaru</h2>') < html.indexOf('>Kamera</h2>'));
            assert.match(html, /href="\/admin\/cameras"[^>]*>Kelola kamera/);
            assert.match(html, /Keaktifan administrasi tidak menunjukkan konektivitas kamera/);
        } else {
            assert.match(html, /Penanganan oleh Perawat melalui detail insiden/);
            assert.doesNotMatch(html, />Kamera<\/h2>|Aktif administrasi|Kelola kamera/);
        }
    });
}

test('statistik periode menampilkan tujuh nilai server tanpa digabung dengan antrean', async () => {
    const stats = freeze(dashboard().stats);
    const html = await render('MonitoringStats', { stats });
    const values = Object.fromEntries([...html.matchAll(/<dt>(.*?)<\/dt><dd>(.*?)<\/dd>/g)].map((match) => [match[1], match[2]]));
    assert.deepEqual(values, {
        'Deteksi fall': '17', 'Observasi gait': '13', 'Menunggu tindak lanjut': '5',
        'Diakui · belum selesai': '2', 'Selesai': '7', 'Alarm palsu': '3',
    });
    assert.match(text(html), /Tercatat terverifikasi: 8/);
    assert.match(text(html), /Verifikasi adalah atribut terpisah dari status/);
    assert.match(text(html), /Pending bukan fall terkonfirmasi/);
    assert.doesNotMatch(html, /ui-monitoring-queue-count|Aktif administrasi/);
});

test('angka nol tidak menjadi klaim aman dan nilai hilang tidak difabrikasi menjadi nol', async () => {
    const stats = Object.fromEntries(Object.keys(dashboard().stats).map((key) => [key, 0]));
    const html = await render('MonitoringStats', { stats });
    assert.equal((html.match(/<dd>0<\/dd>/g) ?? []).length, 6);
    assert.doesNotMatch(html, /kondisi aman|normal seluruh|tidak ada risiko/i);
    const missing = await render('MonitoringStats', { stats: {} });
    assert.match(missing, /Tidak tersedia/);
    assert.doesNotMatch(missing, /<dd>0<\/dd>/);
});

test('header mempunyai satu zona WIB, waktu asli dan refresh manual', async () => {
    const html = await render('MonitoringHeader', { title: 'Judul uji', description: 'Deskripsi uji', asOf });
    assert.match(html, /<h1>Judul uji<\/h1>/);
    assert.equal((text(html).match(/WIB/g) ?? []).length, 1);
    assert.match(html, new RegExp('dateTime="' + asOf + '"'));
    assert.match(html, /type="button"[^>]*>Muat ulang data<\/button>/);
    assert.match(html, /role="status"/);
    assert.match(html, /Pembaruan manual/);
    assert.doesNotMatch(html, /disabled=""|<svg|pemantauan langsung|live feed/i);
});

for (const status of ['pending', 'acknowledged', 'resolved', 'false_alarm']) {
    test('tabel mempertahankan status ' + status + ' dan verifikasi terpisah', async () => {
        const verified = status === 'acknowledged' || status === 'resolved';
        const props = freeze({ records: [record({ fall: { status, is_verified: verified, verified_at: verified ? received : null, detail_url: '/monitoring/incidents/' + id } })] });
        const before = JSON.stringify(props);
        const html = await render('MonitoringActivityTable', props);
        assert.equal(JSON.stringify(props), before);
        const labels = { pending: 'Menunggu tindak lanjut', acknowledged: 'Diakui · belum selesai', resolved: 'Selesai', false_alarm: 'Alarm palsu' };
        assert.match(text(html), new RegExp(labels[status]));
        assert.match(text(html), new RegExp(verified ? 'Tercatat terverifikasi' : 'Belum/tidak terverifikasi'));
        assert.match(html, /class="ui-status/);
        assert.match(html, /href="\/monitoring\/incidents\/12345678/);
        assert.doesNotMatch(html, /<button|method="post"/);
    });
}

test('tabel menyimpan kedua timestamp, caption, lima kolom dan region scroll', async () => {
    const html = await render('MonitoringActivityTable', { records: [record()] });
    assert.match(html, /role="region" aria-label="Rekaman monitoring"/);
    assert.match(html, /tabindex="0"/);
    assert.match(html, /<caption[^>]*>Rekaman monitoring dengan waktu kejadian dan lokasi historis<\/caption>/);
    assert.equal((html.match(/<th scope="col"/g) ?? []).length, 5);
    assert.match(html, new RegExp('dateTime="' + occurred + '" title="' + occurred + '"'));
    assert.match(html, new RegExp('dateTime="' + received + '" title="' + received + '"'));
    assert.match(text(html), /Kejadian/);
    assert.match(text(html), /Diterima/);
    assert.match(text(html), /R-UJI · Kamar uji/);
    assert.match(text(html), /VIP uji/);
});

for (const [category, label] of [
    ['walking_normal', 'Berjalan normal'], ['walking_abnormal', 'Berjalan tidak normal'], ['no_walking_detected', 'Tidak terdeteksi aktivitas berjalan'],
]) {
    test('kategori gait ' + category + ' tetap observasi nonklinis', async () => {
        const html = await render('MonitoringActivityTable', { records: [record({ event_type: 'gait', fall: null, gait: { category } })] });
        assert.ok(text(html).includes(label));
        assert.match(text(html), /Kategori observasi, bukan diagnosis/);
        assert.doesNotMatch(html, /Detail insiden|kondisi aman|skor klinis|cadence|stride/i);
    });
}

for (const state of ['legacy', 'unavailable']) {
    test('konteks ' + state + ' tidak menampilkan lokasi atau identitas perkiraan', async () => {
        const html = await render('MonitoringActivityTable', {
            records: [record({ context: { state, room: { code: 'JANGAN-TAMPIL', name: 'Lokasi tersembunyi' }, service_class: { name: 'Kelas tersembunyi' } }, identity: { state: 'unknown', name: 'Nama tersembunyi' } })],
        });
        assert.match(text(html), new RegExp(state === 'legacy' ? 'Legacy · lokasi historis tidak tersedia' : 'Konteks historis tidak konsisten; lokasi tidak ditampilkan'));
        assert.match(text(html), /Identitas belum diketahui/);
        assert.doesNotMatch(html, /JANGAN-TAMPIL|Lokasi tersembunyi|Kelas tersembunyi|Nama tersembunyi/);
    });
}

test('tautan konfigurasi dan profil hanya mengikuti props yang diizinkan server', async () => {
    const known = record({ identity: { state: 'known', name: 'Lansia uji', basis: 'verified_incident', resident_url: '/admin/residents/' + id } });
    const admin = await render('MonitoringActivityTable', { records: [known] });
    assert.match(admin, /href="\/admin\/residents\/12345678/);
    assert.match(admin, /href="\/admin\/cameras\/12345678/);
    const caregiver = structuredClone(known);
    delete caregiver.identity.resident_url;
    delete caregiver.camera.configuration_url;
    const html = await render('MonitoringActivityTable', { records: [caregiver] }, { role: 'perawat' });
    assert.match(html, /Lansia uji/);
    assert.doesNotMatch(html, /href="\/admin/);
});

test('atribusi VIP tetap disebut asumsi kamar, tidak menjadi identifikasi visual', async () => {
    const html = await render('MonitoringActivityTable', { records: [record({ event_type: 'gait', fall: null, gait: { category: 'walking_normal' }, identity: { state: 'known', name: 'Lansia uji', basis: 'vip_room_single_resident' } })] });
    assert.match(text(html), /Atribusi berdasarkan kamar VIP/);
    assert.doesNotMatch(html, /wajah dikenali|identifikasi visual berhasil/i);
});

test('kategori/status baru tidak dilabeli sebagai nilai dikenal atau aman', async () => {
    const html = await render('MonitoringActivityTable', { records: [
        record({ id: 'unknown-event', event_type: 'future', fall: null, gait: null }),
        record({ id: 'unknown-status', fall: { status: 'future', is_verified: false, detail_url: '/monitoring/incidents/' + id } }),
        record({ id: 'unknown-category', event_type: 'gait', fall: null, gait: { category: 'future' } }),
    ] });
    assert.match(html, /Jenis event tidak dikenal/);
    assert.match(html, /Status insiden tidak dikenal/);
    assert.match(html, /Kategori gait tidak dikenal/);
    assert.match(html, /Data rincian belum tersedia/);
});

test('nama panjang dan markup tetap berupa teks aman tanpa memotong data', async () => {
    const name = 'Kamar uji dengan nama panjang '.repeat(8) + '<script>fixture</script>';
    const html = await render('MonitoringActivityTable', { records: [record({ camera: { name } })] });
    assert.ok(html.includes(name.replace('<script>', '&lt;script&gt;').replace('</script>', '&lt;/script&gt;')));
    assert.doesNotMatch(html, /<script>|line-clamp|truncate/);
});

test('empty state tidak menambahkan tabel, tautan atau klaim aman', async () => {
    const html = await render('MonitoringActivityTable', { records: [], emptyMessage: 'Tidak ada pending pada hasil ini.' });
    assert.match(html, /role="status"/);
    assert.match(html, /Tidak ada pending pada hasil ini/);
    assert.doesNotMatch(html, /<table|<a |<button|kondisi aman/i);
});

for (const [range, label] of [['24h', '24 jam terakhir'], ['7d', '7 hari terakhir'], ['30d', '30 hari terakhir']]) {
    test('label dan state periode ' + range + ' dipertahankan', () => {
        const initial = initialMonitoringFilters({ range }, 'dashboard');
        assert.deepEqual(initial, { range, date_from: '', date_to: '' });
        assert.equal(monitoringRangeLabel(initial), label);
    });
}

test('tanggal khusus tidak bergeser zona dan fallback tidak mengarang periode', () => {
    assert.equal(monitoringRangeLabel({ range: 'custom', date_from: '2026-11-01', date_to: '2026-11-12' }), '1 Nov 2026 – 12 Nov 2026 (WIB)');
    assert.equal(monitoringRangeLabel({ range: 'custom' }), 'Tanggal khusus (WIB)');
    assert.equal(monitoringRangeLabel({ range: 'unknown' }), 'Periode sesuai filter');
});

test('state filter records menjaga allowlist, UUID scopes dan reset halaman saat reapply', () => {
    const filters = freeze({ range: 'custom', date_from: '2026-11-01', date_to: '2026-11-12', search: 'Kamar A & B', class_code: 'class_2', camera_id: id, room_id: id, per_page: 30, event_type: 'fall', fall_status: 'acknowledged', gait_category: '', verification: 'verified', identity: 'known', location: 'tracked', page: 9, arbitrary: 'ditolak' });
    const data = initialMonitoringFilters(filters);
    assert.deepEqual(data, { range: 'custom', date_from: '2026-11-01', date_to: '2026-11-12', search: 'Kamar A & B', class_code: 'class_2', camera_id: id, room_id: id, per_page: 30, event_type: 'fall', fall_status: 'acknowledged', gait_category: '', verification: 'verified', identity: 'known', location: 'tracked' });
    assert.equal(new URLSearchParams(data).get('search'), 'Kamar A & B');
    assert.equal(new URLSearchParams(data).get('camera_id'), id);
});

test('pergantian rentang menghapus hanya tanggal; pergantian event membersihkan dependensi', () => {
    const before = freeze(initialMonitoringFilters({ range: 'custom', date_from: '2026-11-01', date_to: '2026-11-12', camera_id: id, room_id: id, search: 'uji', event_type: 'fall', fall_status: 'pending', verification: 'unverified', identity: 'unknown', location: 'legacy' }));
    const ranged = updateMonitoringRange(before, '7d');
    assert.deepEqual(ranged, { ...before, range: '7d', date_from: '', date_to: '' });
    const event = updateMonitoringEventType(before, 'gait');
    assert.deepEqual(event, { ...before, event_type: 'gait', fall_status: '', verification: '', gait_category: '' });
    assert.equal(before.event_type, 'fall');
    assert.equal(before.date_from, '2026-11-01');
});

test('open filter tidak menyertakan periode atau filter records walau diberikan pada input', () => {
    const data = initialMonitoringFilters({ range: '7d', date_from: '2026-11-01', event_type: 'fall', status: 'acknowledged', camera_id: id, room_id: id, class_code: 'class_3', page: 3 }, 'open');
    assert.deepEqual(data, { search: '', class_code: 'class_3', camera_id: id, room_id: id, per_page: 15, status: 'acknowledged' });
});

for (const [eventType, expected, absent] of [
    ['fall', ['fall_status', 'verification'], ['gait_category']],
    ['gait', ['gait_category'], ['fall_status', 'verification']],
    ['', [], ['fall_status', 'verification', 'gait_category']],
]) {
    test('form records menyediakan kontrol berlabel dan conditional event ' + eventType, async () => {
        const filters = { range: 'custom', date_from: '2026-11-01', date_to: '2026-11-12', event_type: eventType, camera_id: id, room_id: id };
        const html = await render('MonitoringFilters', { filters, url: '/monitoring/records', classes: [{ code: 'class_2', name: 'Kelas dua uji' }] });
        const names = controls(html);
        for (const name of ['range', 'date_from', 'date_to', 'search', 'class_code', 'per_page', 'event_type', 'identity', 'location', ...expected]) {
            assert.ok(names.includes(name), name);
            assert.ok(html.includes('for="' + name + '"'), name);
        }
        for (const name of absent) assert.ok(!names.includes(name), name);
        assert.match(html, /type="date"[^>]*required=""/);
        assert.match(html, /type="search"[^>]*maxLength="100"/);
        assert.match(html, /Filter kamera/);
        assert.match(html, /Filter kamar historis/);
        assert.match(html, /type="submit"[^>]*>Terapkan filter/);
        assert.match(html, /type="button"[^>]*>Reset filter/);
        assert.match(html, /Kelas dua uji/);
        assert.doesNotMatch(html, /name="page"|name="arbitrary"/);
    });
}

test('form open tidak menawarkan filter waktu dan hanya dua status terbuka', async () => {
    const html = await render('MonitoringFilters', { filters: { status: 'acknowledged' }, url: '/monitoring/open-incidents', mode: 'open' });
    assert.deepEqual(controls(html), ['search', 'class_code', 'per_page', 'status']);
    assert.match(html, /value="acknowledged" selected=""/);
    assert.doesNotMatch(html, /value="resolved"|value="false_alarm"|name="range"|name="date_from"/);
    assert.match(html, /tidak mengikuti filter waktu dashboard/);
});

test('validasi filter terhubung ke kontrol dan error umum tetap dapat dibaca', async () => {
    const html = await render('MonitoringFilters', { filters: { range: 'custom' }, url: '/monitoring/records' }, {
        errors: { range: 'Pilihan filter tidak valid.', date_to: 'Rentang maksimal 90 hari.', filters: 'Ada filter yang tidak diizinkan.' },
    });
    const fields = [...html.matchAll(/<(?:input|select)\b[^>]*>/g)].map((match) => match[0]);
    const range = fields.find((tag) => tag.includes('name="range"'));
    assert.ok(range.includes('aria-invalid="true"'));
    assert.ok(range.includes('aria-describedby="range-error"'));
    assert.match(html, /id="range-error"/);
    const dateTo = fields.find((tag) => tag.includes('name="date_to"'));
    assert.ok(dateTo.includes('aria-invalid="true"'));
    assert.ok(dateTo.includes('aria-describedby="date_to-error"'));
    assert.match(html, /role="alert" tabindex="-1"/);
    assert.match(html, /Rentang maksimal 90 hari/);
    assert.match(html, /Ada filter yang tidak diizinkan/);
});

for (const component of ['Records', 'OpenIncidents']) {
    test(component + ' mempertahankan pagination, URL filter dan urutan records', async () => {
        const base = component === 'Records' ? '/monitoring/records' : '/monitoring/open-incidents';
        const query = component === 'Records' ? 'range=7d&class_code=vip' : 'status=acknowledged&class_code=vip';
        const incident = component === 'Records' ? {} : { fall: { status: 'acknowledged', is_verified: false, verified_at: null, detail_url: '/monitoring/incidents/' + id } };
        const props = freeze({
            records: page([record({ id: 'first', camera: { name: 'Urutan server pertama' }, ...incident }), record({ id: 'second', camera: { name: 'Urutan server kedua' }, ...incident })], {
                prev_page_url: base + '?' + query + '&page=1', next_page_url: base + '?' + query + '&page=3',
            }),
            filters: component === 'Records' ? { range: '7d', class_code: 'vip' } : { status: 'acknowledged', class_code: 'vip' }, as_of: asOf,
            classes: [{ code: 'vip', name: 'VIP uji' }, { code: 'class_2', name: 'Kelas 2 uji' }, { code: 'class_3', name: 'Kelas 3 uji' }],
        });
        const before = JSON.stringify(props);
        const html = await render(component, props, { url: component === 'Records' ? '/monitoring/records' : '/monitoring/open-incidents' });
        assert.equal(JSON.stringify(props), before);
        assert.ok(html.indexOf('Urutan server pertama') < html.indexOf('Urutan server kedua'));
        for (const target of [props.records.prev_page_url, props.records.next_page_url]) assert.ok(html.includes('href="' + target.replaceAll('&', '&amp;') + '"'));
        assert.match(text(html), /31 data · Halaman 2 dari 3/);
        if (component === 'OpenIncidents') {
            assert.match(html, /Urutan kejadian tertua · seluruh waktu/);
            assert.doesNotMatch(html, /name="range"|name="date_from"/);
        } else assert.match(html, /Periode terpilih: 7 hari terakhir/);
    });

    test(component + ' empty dan halaman di luar hasil tetap dijelaskan', async () => {
        const props = { records: page([], { total: 9, current_page: 3, last_page: 1, prev_page_url: null, next_page_url: null }), filters: {}, as_of: asOf, classes: [] };
        const html = await render(component, props);
        assert.match(html, /Halaman ini di luar hasil yang tersedia/);
        assert.match(html, /aria-disabled="true"/);
        assert.doesNotMatch(html, /<table|kondisi aman/i);
    });
}
