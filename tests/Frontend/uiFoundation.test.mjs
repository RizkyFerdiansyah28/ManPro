import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from './isolatedVite.mjs';
import react from '@vitejs/plugin-react';

let vite;
const components = {};
const render = (Component, props, children) => renderToStaticMarkup(React.createElement(Component, props, children));

before(async () => {
    vite = await createServer({
        root: fileURLToPath(new URL('../../', import.meta.url)),
        configFile: false,
        envDir: false,
        plugins: [react()],
        server: { middlewareMode: true, watch: null, hmr: false, ws: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    for (const [name, path] of Object.entries({
        Button: 'ui/Button', StatusBadge: 'ui/StatusBadge', TableFrame: 'ui/TableFrame', EmptyState: 'ui/EmptyState',
        FormField: 'FormField', ServiceBenefits: 'ServiceBenefits', MonitoringActivityTable: 'Monitoring/MonitoringActivityTable',
    })) {
        components[name] = (await vite.ssrLoadModule('/resources/js/components/' + path + '.jsx')).default;
    }
});

after(async () => { await vite?.close(); });

test('tombol tindakan tidak menjadi submit secara tidak sengaja', () => {
    const html = render(components.Button, {}, 'Tutup masa tinggal');
    assert.match(html, /type="button"/);
    assert.doesNotMatch(html, /disabled|aria-busy/);
    assert.match(html, />Tutup masa tinggal<\/button>/);
});

test('submit eksplisit tetap tersedia sebelum request dimulai', () => {
    const html = render(components.Button, { type: 'submit', name: 'action', value: 'login' }, 'Masuk');
    assert.match(html, /type="submit"/);
    assert.match(html, /name="action"/);
    assert.match(html, /value="login"/);
    assert.doesNotMatch(html, /disabled|aria-busy/);
});

test('request aktif menonaktifkan tombol dan memberi status tanpa mengganti label aksi', () => {
    const html = render(components.Button, { type: 'submit', processing: true }, 'Memeriksa akun…');
    assert.match(html, /disabled=""/);
    assert.match(html, /aria-busy="true"/);
    assert.match(html, /<svg aria-hidden="true"/);
    assert.match(html, /Memeriksa akun…/);
});

test('larangan tindakan dari caller tetap dipertahankan', () => {
    const html = render(components.Button, { disabled: true, variant: 'secondary' }, 'Tutup enrollment');
    assert.match(html, /disabled=""/);
    assert.doesNotMatch(html, /aria-busy/);
    assert.match(html, /Tutup enrollment/);
});

test('input menghubungkan label, hint, error dan deskripsi caller bersamaan', () => {
    const html = render(components.FormField, {
        id: 'password', label: 'Kata sandi', type: 'password', autoComplete: 'new-password', required: true,
        hint: 'Minimal 8 karakter.', error: 'Konfirmasi tidak cocok.', 'aria-describedby': 'form-help',
        value: '', onChange() {},
    });
    assert.match(html, /<label for="password"/);
    const input = html.match(/<input[^>]+>/)[0];
    assert.match(input, /id="password"/);
    assert.match(input, /name="password"/);
    assert.match(input, /type="password"/);
    assert.match(html, /autoComplete="new-password"/);
    assert.match(html, /required=""/);
    assert.match(html, /aria-invalid="true"/);
    assert.match(html, /aria-describedby="form-help password-hint password-error"/);
    assert.match(html, /id="password-hint"/);
    assert.match(html, /id="password-error" role="alert"/);
    assert.match(html, /value=""/);
});

test('input opsional tidak mendapat aturan validasi tambahan', () => {
    const html = render(components.FormField, {
        id: 'monthly_price', label: 'Tarif', inputMode: 'decimal', maxLength: 15, value: '0.00', onChange() {},
    });
    assert.match(html, /inputMode="decimal"/);
    assert.match(html, /maxLength="15"/);
    assert.match(html, /value="0.00"/);
    assert.match(html, /aria-invalid="false"/);
    assert.doesNotMatch(html, /required|minLength|pattern|aria-describedby/);
});

test('pesan validasi dirender sebagai teks aman', () => {
    const html = render(components.FormField, { id: 'name', label: 'Nama', error: '<script>fixture</script>' });
    assert.match(html, /&lt;script&gt;fixture&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>/);
});

test('badge statis mempunyai teks dan tidak menjadi alarm live', () => {
    const html = render(components.StatusBadge, { tone: 'neutral' }, 'Tidak');
    assert.match(html, />Tidak<\/span>/);
    assert.doesNotMatch(html, /role="alert"|aria-live|aria-hidden/);
});

test('empty state tidak menambahkan status aman atau aksi buatan', () => {
    const html = render(components.EmptyState, { title: 'Tidak ada rekaman.', description: 'Data tersimpan.' });
    assert.match(html, /role="status"/);
    assert.match(html, /Tidak ada rekaman/);
    assert.doesNotMatch(html, /<button|<a |<svg|kondisi aman/i);
});

test('frame tabel mempunyai region bernama dan tetap mempertahankan tabel semantik', () => {
    const table = React.createElement('table', null, React.createElement('caption', null, 'Rekaman kejadian'));
    const html = render(components.TableFrame, { label: 'Rekaman monitoring' }, table);
    assert.match(html, /role="region" aria-label="Rekaman monitoring"/);
    assert.match(html, /tabindex="0"/);
    assert.match(html, /<table><caption>Rekaman kejadian<\/caption><\/table>/);
    const hint = html.match(/<p id="([^"]+)"/)[1];
    assert.ok(html.includes('aria-describedby="' + hint + '"'));
});

test('dua frame mempunyai ID deskripsi yang berbeda', () => {
    const html = renderToStaticMarkup(React.createElement(React.Fragment, null,
        React.createElement(components.TableFrame, { label: 'Pertama' }),
        React.createElement(components.TableFrame, { label: 'Kedua' })));
    const ids = [...html.matchAll(/<p id="([^"]+)"/g)].map((match) => match[1]);
    assert.equal(ids.length, 2);
    assert.notEqual(ids[0], ids[1]);
});

for (const [code, weekly, daily, cctv] of [['vip', true, true, true], ['class_2', true, false, false], ['class_3', false, false, false]]) {
    test('presentasi manfaat mempertahankan matriks dan props ' + code, () => {
        const props = {
            code, is_canonical: true, staff_ai_monitoring: true,
            approved_benefits: {
                room_type: code === 'vip' ? 'private' : 'shared',
                instant_verified_emergency: true, verified_incident_summary: true, monthly_gait_report: true,
                weekly_gait_report: weekly, daily_gait_report: daily, family_live_cctv: cctv,
            },
        };
        const before = structuredClone(props);
        const html = render(components.ServiceBenefits, { serviceClass: props });
        const rows = [...html.matchAll(/<dt[^>]*>(.*?)<\/dt><dd[^>]*><span[^>]*>(.*?)<\/span><\/dd>/g)];
        const values = Object.fromEntries(rows.map((match) => [match[1], match[2]]));
        assert.equal(rows.length, 8);
        assert.equal(values['Monitoring AI oleh petugas'], 'Ya');
        assert.equal(values['Notifikasi darurat instan ke keluarga (setelah verifikasi)'], 'Ya');
        assert.equal(values['Riwayat insiden terverifikasi'], 'Ya');
        assert.equal(values['Rekap gait bulanan'], 'Ya');
        assert.equal(values['Rekap gait mingguan'], weekly ? 'Ya' : 'Tidak');
        assert.equal(values['Laporan gait harian'], daily ? 'Ya' : 'Tidak');
        assert.equal(values['CCTV live untuk keluarga'], cctv ? 'Ya' : 'Tidak');
        assert.deepEqual(props, before);
    });
}

test('rekaman kosong mempertahankan pesan domain dan tidak mengarang data', () => {
    const html = render(components.MonitoringActivityTable, { records: [], emptyMessage: 'Tidak ada insiden pending tercatat.' });
    assert.match(html, /Tidak ada insiden pending tercatat/);
    assert.match(html, /Tidak ada event buatan/);
    assert.doesNotMatch(html, /<table|<a |<button/);
});

test('rekaman unidentified tetap unknown dan memakai konteks legacy', () => {
    const records = [{
        id: 'fixture', occurred_at: '2026-11-12T00:00:00Z', received_at: '2026-11-12T00:00:01Z',
        event_type: 'gait', camera: { name: 'Kamera uji' }, context: { state: 'legacy' },
        gait: { category: 'walking_normal' }, identity: { state: 'unknown' },
    }];
    const before = structuredClone(records);
    const html = render(components.MonitoringActivityTable, { records });
    assert.match(html, /<table/);
    assert.match(html, /<caption/);
    assert.match(html, /scope="col"/);
    assert.match(html, /role="region"/);
    assert.match(html, /Identitas belum diketahui/);
    assert.match(html, /lokasi historis tidak tersedia/);
    assert.match(html, /Kategori observasi, bukan diagnosis/);
    assert.deepEqual(records, before);
});
