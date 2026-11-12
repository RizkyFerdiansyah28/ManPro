import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup, renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { navigationForRole, navigationContext } from '../../resources/js/utils/navigation.js';
import { openNavigationDialog } from '../../resources/js/utils/navigationDialog.js';

const id = '12345678-1234-4234-8234-123456789abc';
const expected = {
    admin: ['/admin', '/monitoring/records', '/monitoring/open-incidents', '/monitoring/incidents', '/admin/accounts', '/admin/residents', '/admin/service-classes', '/admin/rooms', '/admin/cameras', '/notifications'],
    perawat: ['/perawat', '/monitoring/records', '/monitoring/open-incidents', '/monitoring/incidents', '/notifications'],
    keluarga: ['/keluarga', '/keluarga/residents'],
};
const userFor = (role) => ({ id, role, home_url: '/' + role, name: 'Akun uji dengan nama sangat panjang '.repeat(6) });
let vite;
const components = {};
before(async () => {
    vite = await createServer({
        root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    for (const name of ['SidebarNavigation', 'Breadcrumbs', 'Topbar', 'NavigationDrawer']) {
        components[name] = (await vite.ssrLoadModule('/resources/js/components/navigation/' + name + '.jsx')).default;
    }
    components.AppLayout = (await vite.ssrLoadModule('/resources/js/layouts/AppLayout.jsx')).default;
});
after(async () => { await vite?.close(); });
const render = (name, props) => renderToStaticMarkup(React.createElement(components[name], props));
async function shell(role, url) {
    const Page = () => React.createElement(components.AppLayout, null, React.createElement('h1', null, 'Konten halaman existing'));
    return (await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: userFor(role) }, flash: { status: 'Pesan existing' }, errors: {} }, url, version: null },
        resolve: () => Page, render: renderToString,
        setup: ({ App, props }) => React.createElement(App, props),
    })).body;
}

for (const role of Object.keys(expected)) {
    test('daftar dan markup menu sesuai role ' + role, () => {
        assert.deepEqual(navigationForRole(role).flatMap((group) => group.items.map((item) => item.href)), expected[role]);
        const html = render('SidebarNavigation', { role, activeHref: expected[role][1] });
        assert.deepEqual([...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1]), expected[role]);
        assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
        const activeLink = [...html.matchAll(/<a\b[^>]*>/g)].find((match) => match[0].includes('aria-current="page"'))[0];
        assert.ok(activeLink.includes('href="' + expected[role][1] + '"'));
    });

    test('shell mempertahankan children, flash, logout, dan jumlah Bell ' + role, async () => {
        const html = await shell(role, expected[role][0]);
        assert.match(html, /Konten halaman existing/);
        assert.match(html, /Pesan existing/);
        assert.match(html, /href="#main-content"/);
        assert.match(html, /id="main-content" tabindex="-1"/);
        assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, role === 'keluarga' ? 0 : 1);
        assert.equal((html.match(/>Keluar<\/button>/g) ?? []).length, 1);
        assert.equal((html.match(/<dialog/g) ?? []).length, 1);
        assert.doesNotMatch(html, /<dialog[^>]*\sopen(?:=|>)/);
        if (role === 'keluarga') assert.doesNotMatch(html, /href="\/(?:admin|perawat|monitoring|notifications)/);
        else {
            assert.match(html, /href="\/monitoring\/open-incidents\?status=pending"/);
            assert.match(html, /Pembaruan 15 detik/);
        }
    });

    for (const href of expected[role]) {
        test('route utama exact dengan filter tetap aktif ' + role + ' ' + href, () => {
            const result = navigationContext(role, href + '/?page=2&search=rahasia#hasil');
            assert.equal(result.activeHref, href);
            assert.equal(result.breadcrumbs.at(-1).href, undefined);
            assert.doesNotMatch(JSON.stringify(result.breadcrumbs), /rahasia|page=2|#hasil/);
        });
    }
}

const childRoutes = [
    ['/admin/accounts', 'create'], ['/admin/accounts', id + '/password'],
    ['/admin/residents', 'create'], ['/admin/residents', id], ['/admin/residents', id + '/edit'],
    ['/admin/residents', id + '/operations'], ['/admin/residents', id + '/enrollments/create'], ['/admin/residents', id + '/stays/create'],
    ['/admin/service-classes', id], ['/admin/service-classes', id + '/edit'],
    ['/admin/rooms', 'create'], ['/admin/rooms', id], ['/admin/rooms', id + '/edit'],
    ['/admin/cameras', 'create'], ['/admin/cameras', id], ['/admin/cameras', id + '/edit'],
    ['/monitoring/incidents', id], ['/keluarga/residents', id],
];
for (const [parent, suffix] of childRoutes) {
    test('halaman turunan mengaktifkan satu menu induk: ' + parent + '/' + suffix, () => {
        const role = parent.startsWith('/keluarga') ? 'keluarga' : 'admin';
        const result = navigationContext(role, parent + '/' + suffix + '?filter=privat');
        assert.equal(result.activeHref, parent);
        assert.equal(result.breadcrumbs.at(-1).href, undefined);
        assert.ok(result.breadcrumbs.some((item) => item.href === parent));
        assert.doesNotMatch(result.breadcrumbs.map((item) => item.label).join(' '), /12345678|privat/);
        const html = render('SidebarNavigation', { role, activeHref: result.activeHref });
        assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
    });
}

test('breadcrumb operasional mengikuti hierarki existing tanpa nama atau UUID pada label', () => {
    const result = navigationContext('admin', '/admin/residents/' + id + '/stays/create');
    assert.deepEqual(result.breadcrumbs, [
        { label: 'Beranda', href: '/admin' }, { label: 'Lansia', href: '/admin/residents' },
        { label: 'Detail lansia', href: '/admin/residents/' + id },
        { label: 'Operasional lansia', href: '/admin/residents/' + id + '/operations' }, { label: 'Buat masa tinggal' },
    ]);
    const html = render('Breadcrumbs', { items: result.breadcrumbs });
    assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
    assert.match(html, /<nav aria-label="Jejak navigasi"/);
    assert.doesNotMatch(html, new RegExp('>' + id));
});

test('perawat memiliki parent insiden yang benar dan antrean tidak tumpang tindih', () => {
    assert.equal(navigationContext('perawat', '/monitoring/incidents/' + id).activeHref, '/monitoring/incidents');
    assert.equal(navigationContext('perawat', '/monitoring/open-incidents').activeHref, '/monitoring/open-incidents');
});

test('route tidak dikenal, prefix palsu, cross-role dan endpoint JSON memakai fallback aman', () => {
    for (const [role, path] of [
        ['admin', '/admin/accounts-fake'], ['admin', '/admin/rooms/create/extra'],
        ['admin', '/admin/residents/not-a-uuid'], ['admin', '/notifications/summary'],
        ['admin', '/admin/future'], ['keluarga', '/admin/residents/' + id],
        ['perawat', '/admin'], ['admin', 'https://example.test/admin'],
        ['admin', undefined], ['unknown', '/admin'],
    ]) {
        const result = navigationContext(role, path);
        assert.equal(result.activeHref, null);
        assert.equal(result.title, 'Halaman');
        assert.equal(result.breadcrumbs.at(-1).label, 'Halaman');
    }
    assert.deepEqual(navigationForRole('unknown'), []);
});

test('topbar meneruskan tindakan menu dan logout tanpa menambahkan metode request', () => {
    let logoutCalls = 0;
    let menuCalls = 0;
    const props = {
        user: userFor('admin'), context: navigationContext('admin', '/admin'), menuOpen: true, drawerId: 'drawer-fixture',
        onMenu: () => { menuCalls++; }, onLogout: () => { logoutCalls++; }, logoutProcessing: true,
    };
    const tree = components.Topbar(props);
    const children = tree.props.children;
    children[0].props.onClick();
    children.at(-1).props.children[1].props.onClick();
    assert.equal(menuCalls, 1);
    assert.equal(logoutCalls, 1);
    const html = render('Topbar', props);
    assert.match(html, /aria-expanded="true"/);
    assert.match(html, /aria-controls="drawer-fixture"/);
    assert.match(html, /aria-haspopup="dialog"/);
    assert.match(html, /disabled="" aria-busy="true"/);
    assert.match(html, /Keluar…/);
    assert.match(html, /nama sangat panjang/);
    assert.doesNotMatch(html, /title=|email|@example/);
});

test('drawer mempunyai nama, tombol tutup dan menu sesuai role ketika dibuka', () => {
    const html = render('NavigationDrawer', {
        id: 'drawer-fixture', open: true, user: userFor('keluarga'), activeHref: '/keluarga/residents',
        onClose() {}, triggerRef: { current: null }, mainRef: { current: null },
    });
    assert.match(html, /aria-labelledby="drawer-fixture-title"/);
    assert.match(html, /id="drawer-fixture-title">Menu navigasi/);
    assert.match(html, /data-drawer-close="true"/);
    assert.doesNotMatch(html, /href="\/(?:admin|perawat|monitoring|notifications)/);
});

function dialogFixture({ desktop = false, visibleTrigger = true } = {}) {
    const dialog = new EventTarget();
    const media = new EventTarget();
    media.matches = desktop;
    const calls = { shown: 0, closed: 0, initialFocus: 0, triggerFocus: 0, mainFocus: 0, requested: 0 };
    dialog.ownerDocument = { body: { style: { overflow: 'auto' } } };
    dialog.open = false;
    dialog.showModal = () => { dialog.open = true; calls.shown++; };
    dialog.close = () => { dialog.open = false; calls.closed++; dialog.dispatchEvent(new Event('close')); };
    dialog.querySelector = () => ({ focus: () => { calls.initialFocus++; } });
    const trigger = { isConnected: true, getClientRects: () => visibleTrigger ? [{}] : [], focus: () => { calls.triggerFocus++; } };
    const fallback = { isConnected: true, focus: () => { calls.mainFocus++; } };
    const options = { dialog, trigger, fallback, media, onClose: () => { calls.requested++; } };
    return { ...options, calls, start: () => openNavigationDialog(options) };
}

test('drawer mengunci scroll, memberi fokus awal dan cleanup memulihkan fokus sekali', () => {
    const fixture = dialogFixture();
    const cleanup = fixture.start();
    assert.equal(fixture.dialog.open, true);
    assert.equal(fixture.dialog.ownerDocument.body.style.overflow, 'hidden');
    assert.equal(fixture.calls.initialFocus, 1);
    cleanup(); cleanup();
    assert.equal(fixture.calls.closed, 1);
    assert.equal(fixture.calls.triggerFocus, 1);
    assert.equal(fixture.calls.requested, 0);
    assert.equal(fixture.dialog.ownerDocument.body.style.overflow, 'auto');
    fixture.dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
    fixture.media.dispatchEvent(Object.assign(new Event('change'), { matches: true }));
    assert.equal(fixture.calls.requested, 0);
});

test('Escape native meminta close, mencegah close di luar state React dan tidak berulang', () => {
    const fixture = dialogFixture();
    const cleanup = fixture.start();
    const escape = new Event('cancel', { cancelable: true });
    fixture.dialog.dispatchEvent(escape);
    fixture.dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
    assert.equal(escape.defaultPrevented, true);
    assert.equal(fixture.calls.requested, 1);
    cleanup();
});

test('klik backdrop menutup; klik tanpa pointerdown backdrop tidak menutup', () => {
    const fixture = dialogFixture();
    const cleanup = fixture.start();
    fixture.dialog.dispatchEvent(new Event('click'));
    assert.equal(fixture.calls.requested, 0);
    fixture.dialog.dispatchEvent(new Event('pointerdown'));
    fixture.dialog.dispatchEvent(new Event('click'));
    assert.equal(fixture.calls.requested, 1);
    cleanup();
});

test('perubahan ke desktop meminta close dan fokus kembali ke main jika pemicu tersembunyi', () => {
    const fixture = dialogFixture({ visibleTrigger: false });
    const cleanup = fixture.start();
    fixture.media.dispatchEvent(Object.assign(new Event('change'), { matches: false }));
    assert.equal(fixture.calls.requested, 0);
    fixture.media.dispatchEvent(Object.assign(new Event('change'), { matches: true }));
    assert.equal(fixture.calls.requested, 1);
    cleanup();
    assert.equal(fixture.calls.triggerFocus, 0);
    assert.equal(fixture.calls.mainFocus, 1);
});

test('drawer tidak dibuka pada desktop atau platform tanpa dialog modal', () => {
    for (const nativeDialog of [true, false]) {
        const fixture = dialogFixture({ desktop: nativeDialog });
        if (!nativeDialog) fixture.dialog.showModal = undefined;
        fixture.start()();
        assert.equal(fixture.calls.requested, 1);
        assert.equal(fixture.calls.shown, 0);
        assert.equal(fixture.dialog.ownerDocument.body.style.overflow, 'auto');
    }
});

test('kegagalan membuka dialog memulihkan scroll dan melepas listener', () => {
    const fixture = dialogFixture();
    fixture.dialog.showModal = () => { throw new Error('Dialog tidak dapat dibuka'); };
    const cleanup = fixture.start();
    assert.equal(fixture.calls.requested, 1);
    assert.equal(fixture.dialog.ownerDocument.body.style.overflow, 'auto');
    fixture.media.dispatchEvent(Object.assign(new Event('change'), { matches: true }));
    cleanup();
    assert.equal(fixture.calls.requested, 1);
    assert.equal(fixture.calls.triggerFocus, 1);
});
