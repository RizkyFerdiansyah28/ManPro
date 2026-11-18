import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from './isolatedVite.mjs';
import react from '@vitejs/plugin-react';

const id = '12345678-1234-4234-8234-123456789abc';
const second = '12345678-1234-4234-8234-123456789abd';
const long = 'Nama fixture panjang tanpa potongan ' + 'abcdefghij'.repeat(26);
const pages = ['Accounts/Index', 'Accounts/Create', 'Accounts/ResetPassword', 'Residents/Index', 'Residents/Create', 'Residents/Edit', 'Residents/Show', 'ServiceClasses/Index', 'ServiceClasses/Edit', 'ServiceClasses/Show', 'Rooms/Index', 'Rooms/Create', 'Rooms/Edit', 'Rooms/Show', 'Cameras/Index', 'Cameras/Create', 'Cameras/Edit', 'Cameras/Show', 'Operations/Index', 'Operations/CreateEnrollment', 'Operations/StartStay'];
let vite;
const components = {};
let ManagementControl;
before(async () => {
    vite = await createServer({ root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false }, optimizeDeps: { noDiscovery: true, include: [] } });
    for (const path of pages) components[path] = (await vite.ssrLoadModule('/resources/js/pages/Admin/' + path + '.jsx')).default;
    for (const path of ['RoomFields', 'CameraFields', 'CameraRoomSelector', 'FamilySelector', 'OperationSummary', 'CloseOperationAction', 'Management/ManagementForm', 'Management/ManagementActionFeedback']) {
        const module = await vite.ssrLoadModule('/resources/js/components/' + path + '.jsx');
        components[path] = module.default;
        if (path === 'Management/ManagementForm') ManagementControl = module.ManagementControl;
    }
});
after(async () => { await vite?.close(); });

async function render(name, props, options = {}) {
    const Page = () => React.createElement(components[name], props);
    const result = await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: { id: second, name: 'Admin fixture', role: options.role ?? 'admin', home_url: '/admin' } }, flash: {}, errors: {} }, url: '/admin/' + name.toLowerCase(), version: null },
        resolve: () => Page, render: renderToString, setup: ({ App, props: appProps }) => React.createElement(App, appProps),
    });
    return result.body.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
}
const text = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
const inputs = (html) => [...html.matchAll(/<(?:input|select|textarea)\b[^>]*>/g)].map((match) => match[0]);
const named = (html, name) => inputs(html).find((tag) => tag.includes('name="' + name + '"'));
const freeze = (value) => { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; };
const page = (data = [], overrides = {}) => ({ data, total: data.length, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null, ...overrides });
const family = { id: second, name: 'Keluarga fixture', email: 'family@example.test', is_active: false };
const resident = { id, name: long, birth_date: '1950-01-02', age: 76, is_active: true, family };
function serviceClass(code = 'vip', overrides = {}) {
    const benefits = { room_type: code === 'vip' ? 'private' : 'shared', instant_verified_emergency: true, verified_incident_summary: true, monthly_gait_report: true,
        weekly_gait_report: code !== 'class_3', daily_gait_report: code === 'vip', family_live_cctv: code === 'vip' };
    return { id: code === 'vip' ? id : second, code, name: code === 'vip' ? 'VIP fixture' : code === 'class_2' ? 'Kelas 2 fixture' : 'Kelas 3 fixture', is_active: true, monthly_price: '1234567.89',
        ...benefits, approved_benefits: benefits, staff_ai_monitoring: true, is_canonical: true,
        rooms_count: 3, active_rooms_count: 2, active_stays_count: 1, active_enrollments_count: 2, cameras_count: 1, ...overrides };
}
const room = { id: second, code: 'UJI-01', name: long, capacity: 1, active_occupancy: 1, is_active: true, has_stay_history: true, has_camera: true, service_class: serviceClass() };
const camera = { id, name: long, source_type: 'rtsp', is_active: true, room, detections_count: 4, has_source_reference: true, version: 'a'.repeat(64) };
const enrollment = { id: second, started_at: '2026-01-02T01:02:03Z', ended_at: null, monthly_price: '321000.05', service_class: serviceClass('vip', { monthly_price: '999999.99' }) };
const stay = { id, started_at: '2026-01-02T02:02:03Z', ended_at: null, room };
const operations = { stay, enrollment };
const account = { id, name: long, email: 'fixture@example.test', role: 'perawat', is_active: true, can: { updateStatus: true, resetPassword: true } };
function props(name) {
    const classes = [serviceClass(), serviceClass('class_2'), serviceClass('class_3')];
    return { accounts: page([account]), account, resident, residents: page([resident]), filters: { search: 'fixture', role: '', status: '', service_class_id: '', room_id: '' },
        classes, serviceClass: classes[0], rooms: name === 'Operations/StartStay' ? page([{ ...room, occupancy: 0, available: 1, eligible_capacity: true }]) : page([room]), room,
        cameras: page([camera]), camera, operations: name === 'Operations/StartStay' ? { stay: null, enrollment } : operations,
        stays: page([stay]), enrollments: page([enrollment]) };
}

for (const name of pages) {
    test('halaman aktif memakai shell, header, dan data utuh: ' + name, async () => {
        const value = freeze(props(name));
        const before = JSON.stringify(value);
        const html = await render(name, value);
        assert.equal(JSON.stringify(value), before);
        assert.match(html, /class="ui-management-page"/);
        assert.match(html, /class="ui-management-header"/);
        assert.equal((html.match(/<h1\b/g) ?? []).length, 1);
        assert.doesNotMatch(html, /<video\b|<iframe\b|<canvas\b|type="file"|href="[^"]*\/delete/);
        assert.doesNotMatch(html, /truncate|text-ellipsis|line-clamp/);
        if (/Index|Show|ResetPassword/.test(name) && !name.startsWith('ServiceClasses')) assert.ok(text(html).includes(long), name);
    });
}

for (const [name, key, caption] of [
    ['Accounts/Index', 'accounts', 'Daftar akun pengguna dan pengaturan akses'], ['Residents/Index', 'residents', 'Daftar lansia'],
    ['Rooms/Index', 'rooms', 'Daftar kamar dan okupansi aktif'], ['Cameras/Index', 'cameras', 'Daftar konfigurasi kamera'],
]) {
    test('tabel lokal, seluruh kolom, dan pagination server dipertahankan: ' + name, async () => {
        const value = props(name);
        const next = '/admin/fixture?search=dua%20kata&status=inactive&page=3&room_id=' + second;
        value[key] = page(value[key].data, { total: 41, current_page: 2, last_page: 3, prev_page_url: '/admin/fixture?page=1', next_page_url: next });
        const html = await render(name, value);
        assert.match(html, /role="region"[^>]*tabindex="0"/);
        assert.ok(html.includes('aria-label="' + caption + '"'));
        assert.ok(html.includes('<caption class="sr-only">' + caption + '</caption>'));
        assert.ok((html.match(/scope="col"/g) ?? []).length >= 4);
        assert.ok(html.includes(next.replaceAll('&', '&amp;')));
        assert.match(text(html), /41 .*Halaman 2 dari 3/);
    });
    test('kosong tidak mengarang data atau aksi: ' + name, async () => {
        const html = await render(name, { ...props(name), [key]: page() });
        assert.match(html, /class="ui-empty-state"/);
        assert.match(text(html), /Belum ada/);
        assert.doesNotMatch(html, /<table\b|aman seluruhnya|Hapus data/);
    });
}

test('akun protected mengikuti can server, role dan status tetap terpisah', async () => {
    const html = await render('Accounts/Index', { ...props('Accounts/Index'), accounts: page([
        { ...account, role: 'admin', can: { updateStatus: false, resetPassword: false } },
        { ...account, id: second, name: 'Keluarga fixture', role: 'keluarga', is_active: false, can: { updateStatus: true, resetPassword: false } },
    ]) });
    assert.match(text(html), /Akun dilindungi/);
    assert.match(text(html), /Keluarga.*Nonaktif.*Aktifkan/);
    assert.doesNotMatch(html, /Reset sandi|Nonaktifkan/);
});
test('password tetap minimum 8 dan maksimum existing, tanpa aturan browser tambahan', async () => {
    for (const name of ['Accounts/Create', 'Accounts/ResetPassword']) {
        const html = await render(name, props(name));
        assert.match(text(html), /Minimal 8 karakter/);
        assert.match(text(html), /huruf besar.*kecil.*angka/);
        assert.match(text(html), /72 byte/);
        assert.match(named(html, 'password'), /type="password"/);
        assert.match(named(html, 'password'), /maxLength="72"/i);
        assert.doesNotMatch(named(html, 'password'), /minlength|pattern/i);
    }
});
test('ownership keluarga nonaktif tetap ditampilkan, biodata dan perpindahan adalah form terpisah', async () => {
    const html = await render('Residents/Edit', props('Residents/Edit'));
    assert.match(text(html), /Saat ini: Keluarga fixture/);
    assert.match(text(html), /hubungan tetap dipertahankan/);
    assert.match(html, /aria-label="Biodata lansia"/);
    assert.match(html, /aria-label="Ubah keluarga penanggung jawab"/);
    assert.match(named(html, 'confirm_reassignment'), /type="checkbox"/);
    assert.match(html, /aria-describedby="family-selector-help family-selector-error"/);
    assert.match(html, /Ubah keluarga<\/button>/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>Ubah keluarga/);
});

for (const [code, yes, no] of [['vip', 8, 0], ['class_2', 5, 3], ['class_3', 4, 4]]) {
    test('manfaat ' + code + ' read-only, matriks final dan harga tetap ditampilkan', async () => {
        const value = serviceClass(code);
        const html = await render('ServiceClasses/Show', { serviceClass: value });
        assert.equal((html.match(/>Ya<\/span>/g) ?? []).length, yes);
        assert.equal((html.match(/>Tidak<\/span>/g) ?? []).length, no);
        assert.match(text(html), /Rp 1\.234\.567,89/);
        assert.match(text(html), /belum dijalankan oleh halaman ini/);
        assert.match(text(html), /terlepas dari status pembayaran/);
        assert.equal(inputs(html).length, 0);
    });
}
test('kelas NULL berbeda dari nol; legacy tidak canonical tidak memperoleh izin simpan', async () => {
    const html = await render('ServiceClasses/Index', { classes: [serviceClass('vip', { monthly_price: null }), serviceClass('class_2', { monthly_price: '0.00', is_canonical: false })] });
    assert.match(text(html), /Belum diatur/);
    assert.match(text(html), /Rp 0,00/);
    assert.match(text(html), /Konfigurasi manfaat perlu diperiksa/);
    const edit = await render('ServiceClasses/Edit', { serviceClass: serviceClass('vip', { is_canonical: false }) });
    assert.match(edit, /<button[^>]*disabled=""[^>]*>Simpan perubahan/);
    assert.deepEqual(inputs(edit).map((tag) => tag.match(/name="([^"]+)"/)?.[1]), ['name', 'monthly_price']);
});
test('kapasitas, okupansi, histori dan kamera tetap dibedakan', async () => {
    const html = await render('Rooms/Show', { room: { ...room, capacity: 3, active_occupancy: 2, is_active: false } });
    assert.match(text(html), /Nonaktif/);
    assert.match(text(html), /Kapasitas 3 penghuni/);
    assert.match(text(html), /Penghuni aktif 2/);
    assert.match(text(html), /Riwayat masa tinggal Tersedia/);
    assert.match(text(html), /Kamera tertaut Ada/);
});
for (const reason of ['has_stay_history', 'has_camera']) {
    test('kelas kamar tetap terkunci karena ' + reason, async () => {
        const value = { ...room, has_stay_history: false, has_camera: false, [reason]: true };
        const form = { data: { code: 'UJI', name: 'Fixture', service_class_id: id, capacity: '1' }, errors: {}, setData() {} };
        const html = await render('RoomFields', { room: value, classes: [serviceClass()], form });
        assert.match(named(html, 'service_class_id'), /disabled=""/);
        assert.match(named(html, 'capacity'), /min="1"/);
        assert.match(named(html, 'capacity'), /max="2147483647"/);
        assert.match(text(html), /VIP harus berkapasitas tepat 1/);
    });
}
test('kamera hanya status administrasi, tidak memantulkan sumber atau membuat halaman periode', async () => {
    const html = await render('Cameras/Show', { camera: { ...camera, source_reference: 'SECRET_MUST_NOT_RENDER', operating_periods: [{ opened_at: 'SECRET_PERIOD_PROP' }] } });
    assert.match(text(html), /Status administrasi Aktif/);
    assert.match(text(html), /tidak menyatakan kamera online/);
    assert.match(text(html), /nilai disembunyikan/);
    assert.doesNotMatch(html, /SECRET_|<video|<iframe|\/operating-periods|type="datetime/);
    const edit = await render('Cameras/Edit', { camera: { ...camera, source_reference: 'SECRET_MUST_NOT_RENDER' } });
    assert.equal(named(edit, 'source_reference'), undefined);
    assert.match(named(edit, 'source_type'), /disabled=""/);
    assert.match(named(edit, 'source_action'), /disabled=""/);
    assert.match(text(edit), /Kamar dipertahankan/);
    assert.doesNotMatch(edit, /SECRET_MUST_NOT_RENDER/);
});
test('kamera nonaktif dengan deteksi mengunci tipe dan kamar, penggantian sumber tetap eksplisit', async () => {
    const value = { ...camera, is_active: false };
    const form = { data: { room_id: room.id, name: 'Fixture', source_type: 'rtsp', source_action: 'replace', source_reference: '' }, errors: {}, setData() {} };
    const html = await render('CameraFields', { camera: value, form });
    assert.match(named(html, 'source_type'), /disabled=""/);
    assert.doesNotMatch(named(html, 'source_action'), /disabled/);
    assert.match(named(html, 'source_reference'), /type="password"/);
    assert.match(named(html, 'source_reference'), /value=""/);
    assert.match(text(html), /Disimpan terenkripsi tanpa koneksi/);
});
test('jenis sumber legacy tidak diinterpretasi sebagai kamera online', async () => {
    const html = await render('Cameras/Show', { camera: { ...camera, source_type: 'legacy_unknown', has_source_reference: false } });
    assert.match(text(html), /Jenis sumber tidak dikenali/);
    assert.match(text(html), /tidak menyatakan kamera online/);
    assert.match(text(html), /Belum diatur/);
});
test('enrollment kosong, aktif, kelas nonaktif, NULL, dan legacy tetap diblokir sesuai kontrak', async () => {
    const html = await render('Operations/CreateEnrollment', { resident, operations: { stay: null, enrollment: null }, classes: [serviceClass('vip', { monthly_price: null }), serviceClass('class_2', { is_active: false }), serviceClass('class_3', { is_canonical: false })] });
    assert.equal((html.match(/<option[^>]*disabled=""/g) ?? []).length, 3);
    assert.equal(named(html, 'monthly_price'), undefined);
    assert.match(named(html, 'confirm_start'), /required=""/);
    assert.match(text(html), /Tarif kosong ditolak/);
    const blocked = await render('Operations/CreateEnrollment', props('Operations/CreateEnrollment'));
    assert.match(named(blocked, 'service_class_id'), /disabled=""/);
    assert.match(blocked, /<button[^>]*disabled=""[^>]*>Buat enrollment/);
});
test('stay memerlukan enrollment, kamar penuh atau kapasitas tidak sah tidak dapat dipilih', async () => {
    const value = props('Operations/StartStay');
    value.rooms = page([{ ...room, available: 0, occupancy: 1, eligible_capacity: true }, { ...room, id, available: 1, occupancy: 0, eligible_capacity: false }]);
    const html = await render('Operations/StartStay', value);
    const radios = inputs(html).filter((tag) => tag.includes('type="radio"'));
    assert.equal(radios.length, 2);
    assert.ok(radios.every((tag) => tag.includes('disabled=""') && tag.includes('required=""')));
    assert.match(text(html), /Ketersediaan diperiksa ulang/);
    const blocked = await render('Operations/StartStay', { ...value, operations: { stay: null, enrollment: null } });
    assert.match(blocked, /<fieldset[^>]*disabled=""/);
    assert.match(text(blocked), /Belum ada enrollment aktif/);
});
test('histori mempertahankan snapshot, dua timestamp dan pagination independen', async () => {
    const staysUrl = '/admin/residents/' + id + '/operations?stays_page=2&enrollments_page=1';
    const enrollmentsUrl = '/admin/residents/' + id + '/operations?stays_page=1&enrollments_page=2';
    const html = await render('Operations/Index', { resident, operations, stays: page([stay], { next_page_url: staysUrl }), enrollments: page([enrollment], { next_page_url: enrollmentsUrl }) });
    assert.match(text(html), /Snapshot: Rp 321\.000,05/);
    assert.doesNotMatch(text(html), /Snapshot: Rp 999\.999,99/);
    assert.match(text(html), /Mulai:.*WIB.*Selesai: Masih aktif/);
    assert.ok(html.includes(staysUrl.replaceAll('&', '&amp;')));
    assert.ok(html.includes(enrollmentsUrl.replaceAll('&', '&amp;')));
    assert.match(html, /<button[^>]*disabled=""[^>]*>Tutup enrollment/);
});
test('riwayat kosong dan NULL tarif tidak diubah menjadi harga/penempatan fiktif', async () => {
    const html = await render('Operations/Index', { resident, operations: { stay: null, enrollment: { ...enrollment, monthly_price: null } }, stays: page(), enrollments: page() });
    assert.match(text(html), /Belum ada masa tinggal aktif/);
    assert.match(text(html), /Snapshot tarif: Belum diatur/);
    assert.match(text(html), /Belum ada riwayat masa tinggal/);
    assert.match(text(html), /Belum ada riwayat enrollment/);
});

test('summary error aman, kontrol terkait, processing menonaktifkan form, dan input tetap ada', async () => {
    const errors = { service_class_id: '<script>fixture</script>', confirm_start: 'Konfirmasi wajib', version: 'Versi berubah' };
    const form = { data: { service_class_id: id }, errors, processing: true, isDirty: true, recentlySuccessful: false };
    const children = [React.createElement(ManagementControl, { key: 'class', as: 'select', id: 'class', field: 'service_class_id', value: id, onChange() {} }, React.createElement('option', { value: id }, 'Fixture')),
        React.createElement('label', { key: 'choice' }, React.createElement(ManagementControl, { type: 'checkbox', field: 'confirm_start', checked: false, onChange() {} }), 'Konfirmasi')];
    const html = await render('Management/ManagementForm', { form, label: 'Form fixture', onSubmit() {}, children });
    assert.match(html, /aria-busy="true"/);
    assert.match(html, /<fieldset disabled=""/);
    assert.match(html, /tabindex="-1" role="alert"/);
    assert.match(html, /&lt;script&gt;fixture&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script/);
    const control = named(html, 'service_class_id');
    assert.match(control, /aria-invalid="true"/);
    const errorId = control.match(/aria-describedby="([^"]+)"/)[1];
    assert.ok(html.includes('id="' + errorId + '"'));
    const choiceId = named(html, 'confirm_start').match(/aria-describedby="([^"]+)"/)[1];
    assert.ok(html.includes('id="' + choiceId + '"'));
    assert.match(html, /value="12345678-1234-4234-8234-123456789abc" selected=""/);
    assert.match(text(html), /Ada perubahan yang belum disimpan/);
});
test('filter tidak mendapat guard editor atau pesan isian belum disimpan', async () => {
    const html = await render('Management/ManagementForm', { form: { errors: {}, isDirty: true, processing: false }, label: 'Filter fixture', kind: 'filters', onSubmit() {} });
    assert.doesNotMatch(html, /belum disimpan|role="alert"/);
});
test('feedback aksi menampilkan error teks aman dan tujuan fokus tanpa status sukses optimistis', async () => {
    const html = await render('Management/ManagementActionFeedback', { errors: { status: '<img src=x>' } });
    assert.match(html, /tabindex="-1" role="alert"/);
    assert.match(text(html), /Tindakan belum dapat disimpan/);
    assert.doesNotMatch(html, /<img|Berhasil/);
});
test('CSS management memakai tokens, reflow, target44px, tabel lokal dan tidak menambah animasi', () => {
    const css = fs.readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const management = css.slice(css.indexOf('/* UI-4:'), css.indexOf('@source'));
    assert.match(management, /--color-ui-primary/);
    assert.match(management, /minmax\(0, 1fr\)/);
    assert.match(management, /@media \(min-width: 48rem\)/);
    assert.match(management, /@media \(min-width: 64rem\)/);
    assert.match(management, /min-height: var\(--spacing-ui-control\)/);
    assert.match(management, /overflow-wrap: anywhere/);
    assert.doesNotMatch(management, /animation:|transition:|text-overflow:|line-clamp|overflow: hidden/);
});
