import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const id = '12345678-1234-4234-8234-123456789abc';
const otherId = '12345678-1234-4234-8234-123456789abd';
const occurred = '2026-11-11T02:03:04.123456Z';
const received = '2026-11-11T02:03:05.654321Z';
const acted = '2026-11-11T03:00:00.000001Z';
const version = 'a'.repeat(64);
let vite;
const components = {};

before(async () => {
    vite = await createServer({
        root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    for (const name of ['IncidentActions', 'IncidentResidentSelector', 'IncidentStatus', 'IncidentContext', 'IncidentTimeline']) components[name] = (await vite.ssrLoadModule('/resources/js/components/Incidents/' + name + '.jsx')).default;
    components.ReadAction = (await vite.ssrLoadModule('/resources/js/components/Notifications/NotificationReadAction.jsx')).default;
    components.Show = (await vite.ssrLoadModule('/resources/js/pages/Monitoring/Incidents/Show.jsx')).default;
    components.Incidents = (await vite.ssrLoadModule('/resources/js/pages/Monitoring/Incidents/Index.jsx')).default;
    components.Notifications = (await vite.ssrLoadModule('/resources/js/pages/Notifications/Index.jsx')).default;
});
after(async () => { await vite?.close(); });

async function render(name, props, { role = 'perawat', errors = {}, url = '/monitoring/incidents/' + id } = {}) {
    const Page = () => React.createElement(components[name], props);
    const result = await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: { id: otherId, role, name: 'Akun uji', home_url: '/' + role } }, flash: {}, errors }, url, version: null },
        resolve: () => Page, render: renderToString,
        setup: ({ App, props: appProps }) => React.createElement(App, appProps),
    });
    return result.body.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
}
const text = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
const freeze = (value) => {
    if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); }
    return value;
};
function incident(status = 'pending', verified = false, role = 'perawat') {
    return {
        id, status, is_verified: verified, detail_url: '/monitoring/incidents/' + id,
        occurred_at: occurred, received_at: received, camera_name: 'Kamera fixture',
        context: { state: 'tracked', room_code: 'R-UJI', room_name: 'Kamar fixture', class_name: 'VIP fixture' },
        identity_name: verified ? 'Lansia fixture' : null,
        acknowledged_at: status === 'pending' ? null : acted, verified_at: verified ? acted : null,
        resolved_at: ['resolved', 'false_alarm'].includes(status) ? acted : null,
        responder_name: status === 'pending' ? null : 'Perawat fixture', verifier_name: verified ? 'Verifikator fixture' : null,
        legacy_notes: null, version: role === 'perawat' ? version : null,
        allowed_actions: role !== 'perawat' ? [] : status === 'pending' ? ['acknowledge'] : status === 'acknowledged' ? verified ? ['resolve'] : ['verify', 'false_alarm'] : [],
    };
}
function page(data = [], overrides = {}) {
    return { data, total: data.length, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null, ...overrides };
}
function notification(read = false, overrides = {}) {
    return { id: read ? otherId : id, message: 'Dugaan jatuh memerlukan tindak lanjut petugas.', created_at: occurred, read_at: read ? received : null, incident: { status: 'pending', is_verified: false, detail_url: '/monitoring/incidents/' + id }, ...overrides };
}
const inputTags = (html) => [...html.matchAll(/<(?:input|textarea|select)\b[^>]*>/g)].map((match) => match[0]);

for (const [status, verified, available] of [
    ['pending', false, ['acknowledge']],
    ['acknowledged', false, ['verify', 'false_alarm']],
    ['acknowledged', true, ['resolve']],
    ['resolved', true, []],
    ['false_alarm', false, []],
]) {
    test('form Perawat mengikuti allowed_actions ' + status + '/' + verified, async () => {
        const value = freeze(incident(status, verified));
        const before = JSON.stringify(value);
        const html = await render('IncidentActions', { incident: value });
        assert.equal(JSON.stringify(value), before);
        if (!available.length) {
            assert.doesNotMatch(html, /<form|<textarea|type="submit"/);
            assert.match(html, /sudah ditutup dan tidak dapat dibuka/);
            return;
        }
        const select = html.match(/<select[^>]*name="action-choice"[\s\S]*?<\/select>/)[0];
        const choices = [...select.matchAll(/<option value="([^"]+)"/g)].map((match) => match[1]);
        assert.deepEqual(choices, available);
        const notes = inputTags(html).find((tag) => tag.includes('name="notes"'));
        assert.match(notes, /maxLength="2000"/);
        assert.equal(notes.includes('required=""'), available[0] !== 'acknowledge');
        const names = inputTags(html).map((tag) => tag.match(/name="([^"]+)"/)?.[1]);
        assert.equal(names.includes('identity_mode'), available[0] === 'verify');
        assert.match(html, /name="confirm"/);
        assert.match(html, /ui-incident-submit/);
        assert.doesNotMatch(html, /Perubahan belum disimpan|disabled="" aria-busy="true"/);
    });
}

test('Admin tetap baca saja meski props allowed_actions tidak sengaja berisi tindakan', async () => {
    const value = { ...incident('acknowledged'), version: null };
    const html = await render('IncidentActions', { incident: value }, { role: 'admin' });
    assert.match(html, /Pengawasan Admin · baca saja/);
    assert.doesNotMatch(html, /<form|<textarea|<select|type="submit"/);
});

test('field notes, identity dan confirm memiliki label serta error association yang valid', async () => {
    const html = await render('IncidentActions', { incident: incident('acknowledged') }, { errors: { notes: 'Catatan wajib.', identity_mode: 'Identitas tidak valid.', confirm: 'Konfirmasi diperlukan.', expected_version: 'Versi berubah.' } });
    for (const name of ['notes', 'identity_mode', 'confirm']) {
        const field = inputTags(html).find((tag) => tag.includes('name="' + name + '"'));
        const fieldId = field.match(/\bid="([^"]+)"/)[1];
        const described = field.match(/aria-describedby="([^"]+)"/)[1].split(' ');
        assert.ok(html.includes('for="' + fieldId + '"'));
        assert.ok(field.includes('aria-invalid="true"'));
        for (const description of described) assert.ok(html.includes('id="' + description + '"'));
    }
    assert.match(html, /role="alert" tabindex="-1"/);
    assert.match(html, /Versi berubah/);
    assert.doesNotMatch(html, /<input[^>]*name="expected_version"|<input[^>]*name="request_id"/);
});

test('error konflik tetap terbaca ketika server tidak lagi menyediakan action', async () => {
    const html = await render('IncidentActions', { incident: incident('resolved', true) }, { errors: { incident: 'Versi berubah; periksa detail terbaru.' } });
    assert.match(html, /role="alert" tabindex="-1"/);
    assert.match(html, /Versi berubah; periksa detail terbaru/);
    assert.doesNotMatch(html, /type="submit"/);
});

test('picker kandidat tidak memuat atau memilih lansia otomatis', async () => {
    const html = await render('IncidentResidentSelector', { incidentId: id, selectedId: '', onSelect() {}, disabled: false, error: 'Kandidat tidak sah.' });
    assert.match(html, /Kandidat dari histori kamar/);
    assert.match(html, /Tidak ada kandidat yang dipilih otomatis/);
    assert.match(html, /name="candidate-search"/);
    assert.match(html, /Cari \/ muat kandidat/);
    assert.match(html, /Kandidat tidak sah/);
    assert.doesNotMatch(html, /name="elderly_id"|<option|Lansia fixture/);
});

for (const role of ['admin', 'perawat']) {
    test('detail ' + role + ' memisahkan kejadian, verifikasi, penanganan dan jurnal', async () => {
        const value = freeze(incident('acknowledged', true, role));
        const props = freeze({ incident: value, actions: page() });
        const html = await render('Show', props, { role });
        assert.match(html, /Informasi kejadian<\/h2>/);
        assert.match(html, /Verifikasi<\/h2>/);
        assert.match(html, /Penanganan<\/h2>/);
        assert.match(html, /Jurnal tindakan<\/h2>/);
        assert.match(html, new RegExp('dateTime="' + occurred + '"'));
        assert.match(html, new RegExp('dateTime="' + received + '"'));
        assert.match(html, /Lansia fixture/);
        assert.match(html, /Verifikator fixture/);
        assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, 1);
        assert.match(html, /Belum ada tindakan pada halaman jurnal ini/);
        if (role === 'admin') assert.doesNotMatch(html, /<textarea|type="submit"/);
        else assert.match(html, /Selesaikan penanganan/);
    });
}

for (const state of ['legacy', 'unavailable']) {
    test('detail konteks ' + state + ' tidak mengarang lokasi atau identitas', async () => {
        const value = { ...incident(), context: { state, room_code: 'JANGAN-TAMPIL', room_name: 'Lokasi rahasia fixture', class_name: 'Kelas rahasia fixture' } };
        const html = await render('Show', { incident: value, actions: page() });
        assert.match(html, /Identitas belum diketahui/);
        assert.doesNotMatch(html, /JANGAN-TAMPIL|Lokasi rahasia fixture|Kelas rahasia fixture/);
        assert.match(html, state === 'legacy' ? /Legacy · lokasi historis tidak tersedia/ : /Konteks historis tidak konsisten/);
    });
}

test('status terverifikasi tidak menambahkan langkah jurnal yang tidak tersimpan', async () => {
    const html = await render('Show', { incident: incident('resolved', true, 'admin'), actions: page() }, { role: 'admin' });
    assert.match(html, /Belum ada tindakan pada halaman jurnal ini/);
    assert.doesNotMatch(html, /ui-incident-timeline__marker|Urutan 1/);
});

test('timeline memakai urutan, pelaku, notes dan timestamp server tanpa sortir ulang', async () => {
    const journal = freeze(page([
        { id, sequence: 1, action: 'acknowledge', from_status: 'pending', to_status: 'acknowledged', actor_name: 'Petugas pertama fixture', acted_at: acted, identity_name: null, notes: 'Catatan baris pertama\nBaris kedua.' },
        { id: otherId, sequence: 2, action: 'verify', from_status: 'acknowledged', to_status: 'acknowledged', actor_name: 'Petugas berikut fixture', acted_at: '2026-11-11T03:01:00.000001Z', identity_name: null, notes: 'Catatan verifikasi fixture.' },
    ]));
    const before = JSON.stringify(journal);
    const html = await render('IncidentTimeline', { journal });
    assert.match(html, /<ol class="ui-incident-timeline"/);
    assert.ok(html.indexOf('Petugas pertama fixture') < html.indexOf('Petugas berikut fixture'));
    assert.match(html, /Catatan baris pertama\nBaris kedua/);
    assert.match(html, /Identitas belum diketahui/);
    assert.match(html, new RegExp('dateTime="' + acted + '"'));
    assert.match(text(html), /Diakui · belum selesai → Diakui · belum selesai/);
    assert.equal(JSON.stringify(journal), before);
});

test('timeline menampilkan unknown dan escaping sebagai teks, tanpa langkah rekaan', async () => {
    const html = await render('IncidentTimeline', { journal: page([{ id, sequence: 1, action: 'future', from_status: 'future', to_status: 'future', actor_name: '<script>fixture</script>', acted_at: acted, notes: 'Nama panjang '.repeat(20) }]) });
    assert.match(html, /Tindakan tidak dikenal|Status tidak dikenal/);
    assert.match(html, /&lt;script&gt;fixture&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>|line-clamp|truncate/);
});

test('halaman semua insiden menjaga filter, status, link detail dan sorting server', async () => {
    const first = { ...incident('acknowledged'), id, occurred_at: received, camera_name: 'Urutan pertama fixture' };
    const second = { ...incident('pending'), id: otherId, camera_name: 'Urutan kedua fixture', detail_url: '/monitoring/incidents/' + otherId };
    const props = freeze({ records: page([first, second]), filters: { status: '', per_page: 15 } });
    const html = await render('Incidents', props, { url: '/monitoring/incidents' });
    assert.ok(html.indexOf('Urutan pertama fixture') < html.indexOf('Urutan kedua fixture'));
    assert.match(html, /role="region" aria-label="Insiden seluruh waktu"/);
    assert.equal((html.match(/<th scope="col"/g) ?? []).length, 4);
    assert.match(html, /name="status"/);
    assert.match(html, /name="per_page"/);
    assert.doesNotMatch(html, /name="search"|name="range"|name="date_from"|bulk/i);
    assert.match(html, /href="\/monitoring\/incidents\/12345678/);
    assert.match(html, /Diakui · belum selesai/);
    assert.match(html, /Menunggu tindak lanjut/);
});

for (const status of ['pending', 'acknowledged', 'resolved', 'false_alarm']) {
    test('badge status ' + status + ' mempunyai label teks dan bukan alarm live', async () => {
        const html = await render('IncidentStatus', { status });
        assert.match(html, /ui-status/);
        assert.doesNotMatch(html, /role="alert"|aria-live|kondisi aman/i);
    });
}

test('daftar insiden empty tetap menjelaskan filter dan pagination nonaktif', async () => {
    const html = await render('Incidents', { records: page(), filters: { status: 'resolved', per_page: 15 } });
    assert.match(html, /Tidak ada insiden pada halaman dan filter ini/);
    assert.match(html, /aria-disabled="true"/);
    assert.doesNotMatch(html, /<table|kondisi aman/i);
});

test('pagination insiden dan jurnal meneruskan URL server termasuk filter/page', async () => {
    const previous = '/monitoring/incidents?status=pending&per_page=15&page=1';
    const rows = [incident()];
    const html = await render('Incidents', { records: page(rows, { total: 16, current_page: 2, last_page: 2, prev_page_url: previous }), filters: { status: 'pending', per_page: 15 } });
    assert.ok(html.includes('href="' + previous.replaceAll('&', '&amp;') + '"'));
    assert.match(text(html), /16 data · Halaman 2 dari 2/);
    const journalUrl = '/monitoring/incidents/' + id + '?page=2';
    const timeline = await render('IncidentTimeline', { journal: page([], { total: 16, current_page: 3, last_page: 2, prev_page_url: journalUrl }) });
    assert.ok(timeline.includes('href="' + journalUrl + '"'));
});

for (const read of [false, true]) {
    test('notifikasi ' + (read ? 'dibaca' : 'belum dibaca') + ' mengikuti read_at server', async () => {
        const value = freeze(notification(read));
        const html = await render('Notifications', { records: page([value]), filters: { state: read ? 'read' : 'unread', per_page: 15 }, as_of: received }, { url: '/notifications', role: 'admin' });
        assert.match(html, read ? /Sudah dibaca/ : /Belum dibaca/);
        assert.match(html, /Membaca notifikasi tidak mengakui atau menyelesaikan insiden/);
        assert.match(html, /href="\/monitoring\/incidents\/12345678/);
        assert.match(html, new RegExp('dateTime="' + value.created_at + '"'));
        assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, 1);
        if (read) {
            assert.match(html, new RegExp('dateTime="' + value.read_at + '"'));
            assert.doesNotMatch(html, />Tandai dibaca<\/button>/);
        } else assert.match(html, />Tandai dibaca<\/button>/);
        assert.match(html, /Tandai seluruh notifikasi insiden saya/);
        const confirm = inputTags(html).find((tag) => tag.includes('name="confirm"'));
        assert.ok(confirm.includes('type="checkbox"'));
        assert.ok(confirm.includes('required=""'));
    });
}

test('notifikasi tanpa konteks tidak mengarang detail URL atau data recipient', async () => {
    const value = notification(false, { incident: null, message: 'Notifikasi insiden.', notifiable_id: 'JANGAN-RECIPIENT', private_metadata: 'JANGAN-METADATA' });
    const html = await render('Notifications', { records: page([value]), filters: { state: 'all', per_page: 15 }, as_of: received }, { role: 'perawat' });
    assert.doesNotMatch(html, /Buka detail insiden|JANGAN-RECIPIENT|JANGAN-METADATA/);
    assert.match(html, /Notifikasi insiden/);
    assert.match(html, /15 detik/);
});

test('halaman notifikasi memakai filter baca/pagination, tidak menambah search atau polling daftar', async () => {
    const previous = '/notifications?state=unread&per_page=15&page=1';
    const props = freeze({ records: page([notification()], { total: 16, current_page: 2, last_page: 2, prev_page_url: previous }), filters: { state: 'unread', per_page: 15 }, as_of: received });
    const html = await render('Notifications', props);
    assert.ok(html.includes('href="' + previous.replaceAll('&', '&amp;') + '"'));
    for (const value of ['all', 'unread', 'read']) assert.ok(html.includes('value="' + value + '"'));
    assert.match(html, /daftar dimuat ulang secara manual/);
    assert.doesNotMatch(html, /name="search"|name="recipient"|name="notifiable_id"/);
    assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, 1);
});

test('error filter insiden dan konfirmasi baca-all terhubung ke field dan summary', async () => {
    const html = await render('Incidents', { records: page(), filters: { status: '', per_page: 15 } }, { errors: { status: 'Pilihan tidak valid.' } });
    const status = inputTags(html).find((tag) => tag.includes('name="status"'));
    assert.match(status, /aria-invalid="true"/);
    const errorId = status.match(/aria-describedby="([^"]+)"/)[1];
    assert.ok(html.includes('id="' + errorId + '"'));
    const notifications = await render('Notifications', { records: page(), filters: { state: 'all', per_page: 15 }, as_of: received, errors: { confirm: 'Konfirmasikan tindakan.' } });
    const confirm = inputTags(notifications).find((tag) => tag.includes('name="confirm"'));
    assert.match(confirm, /aria-invalid="true"/);
    const confirmId = confirm.match(/aria-describedby="([^"]+)"/)[1];
    assert.ok(notifications.includes('id="' + confirmId + '"'));
    assert.match(notifications, /role="alert" tabindex="-1"/);
});

test('notifikasi kosong tidak membuat pesan aman atau link insiden rekaan', async () => {
    const html = await render('Notifications', { records: page(), filters: { state: 'unread', per_page: 15 }, as_of: received });
    assert.match(html, /Tidak ada notifikasi pada halaman dan filter ini/);
    assert.match(html, /Periksa juga antrean insiden terbuka/);
    assert.doesNotMatch(html, /Buka detail insiden|kondisi aman/i);
});

test('notes legacy dan pesan notifikasi panjang tetap utuh sebagai teks aman', async () => {
    const notes = 'Catatan fixture panjang '.repeat(15) + '<script>fixture</script>';
    const show = await render('Show', { incident: { ...incident('false_alarm', false, 'admin'), legacy_notes: notes }, actions: page() }, { role: 'admin' });
    assert.match(show, /Catatan legacy tersimpan/);
    assert.match(show, /&lt;script&gt;fixture&lt;\/script&gt;/);
    assert.doesNotMatch(show, /<script>|truncate|line-clamp/);
    const notificationHtml = await render('Notifications', { records: page([notification(false, { message: notes })]), filters: { state: 'all', per_page: 15 }, as_of: received });
    assert.match(notificationHtml, /&lt;script&gt;fixture&lt;\/script&gt;/);
});
