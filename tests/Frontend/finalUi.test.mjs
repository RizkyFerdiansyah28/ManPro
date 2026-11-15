import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup, renderToString } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { parseAst } from 'rolldown/parseAst';
import { navigationContext } from '../../resources/js/utils/navigation.js';
import { desktopNavigationQuery, openNavigationDialog } from '../../resources/js/utils/navigationDialog.js';
import focusFormErrors from '../../resources/js/utils/focusFormErrors.js';

const id = '12345678-1234-4234-8234-123456789abc';
const menus = {
    admin: ['/admin', '/monitoring/records', '/monitoring/open-incidents', '/monitoring/incidents', '/admin/accounts', '/admin/residents', '/admin/service-classes', '/admin/rooms', '/admin/cameras', '/admin/billing/invoices', '/notifications'],
    perawat: ['/perawat', '/monitoring/records', '/monitoring/open-incidents', '/monitoring/incidents', '/notifications'],
    keluarga: ['/keluarga', '/keluarga/residents'],
};
const userFor = (role) => ({ id, role, name: 'Nama akun panjang '.repeat(16), home_url: menus[role]?.[0] ?? '/' });
const sources = {};
for (const path of ['layouts/AppLayout', 'components/navigation/NavigationDrawer', 'pages/Auth/Login', 'pages/Error']) {
    const code = fs.readFileSync(new URL('../../resources/js/' + path + '.jsx', import.meta.url), 'utf8');
    sources[path] = { code, ast: parseAst(code, { lang: 'jsx' }) };
}
function walk(node, visit) {
    if (!node || typeof node !== 'object') return;
    if (node.type) visit(node);
    for (const value of Object.values(node)) {
        if (Array.isArray(value)) value.forEach((child) => walk(child, visit));
        else if (value && typeof value === 'object') walk(value, visit);
    }
}
// Execute real callbacks extracted from source; no browser, request, or database involved.
function callback(path, select, bindings) {
    const { code, ast } = sources[path];
    const found = [];
    walk(ast, (node) => { if (select(node)) found.push(node); });
    assert.equal(found.length, 1, 'Expected exactly one actual callback: ' + path);
    return Function(...Object.keys(bindings), 'return (' + code.slice(found[0].start, found[0].end) + ')')(...Object.values(bindings));
}
const links = (html) => [...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1]);
function referencesExist(html) {
    const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
    assert.equal(new Set(ids).size, ids.length, 'No duplicate IDs');
    for (const [, value] of html.matchAll(/aria-(?:labelledby|describedby|controls)="([^"]+)"/g)) {
        for (const target of value.split(/\s+/)) assert.ok(ids.includes(target), 'Missing reference: ' + target);
    }
}

let vite;
const components = {};
before(async () => {
    vite = await createServer({
        root: fileURLToPath(new URL('../../', import.meta.url)), configFile: false, envDir: false,
        plugins: [react()], server: { middlewareMode: true, watch: null, hmr: false, ws: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    for (const [name, path] of Object.entries({
        AppLayout: 'layouts/AppLayout', Drawer: 'components/navigation/NavigationDrawer',
        Login: 'pages/Auth/Login', Error: 'pages/Error',
    })) components[name] = (await vite.ssrLoadModule('/resources/js/' + path + '.jsx')).default;
    components.LoginForm = (await vite.ssrLoadModule('/resources/js/pages/Auth/Login.jsx')).LoginForm;
});
after(async () => { await vite?.close(); });
const render = (name, props) => renderToStaticMarkup(React.createElement(components[name], props));
async function page(name, props, role = null, url = '/') {
    const Page = name === 'AppLayout'
        ? () => React.createElement(components.AppLayout, null, React.createElement('h1', null, 'Konten existing'))
        : components[name];
    // Inspect the rendered UI separately from Inertia's serialized fixture inputs.
    // Backend response sanitization is covered by the PostgreSQL privacy regressions.
    return (await createInertiaApp({
        page: { component: 'Fixture', props: { auth: { user: role ? userFor(role) : null }, errors: {}, flash: { status: 'Pesan existing' }, ...props }, url, version: null },
        resolve: () => Page, render: renderToString,
        setup: ({ App, props: appProps }) => React.createElement(App, appProps),
    })).body.replace(/<script\b[^>]*data-page="app"[\s\S]*?<\/script>/g, '');
}

for (const role of Object.keys(menus)) {
    test('UI-7 sidebar tunggal, identitas, menu dan Bell: ' + role, async () => {
        const html = await page('AppLayout', {}, role, menus[role][0]);
        const aside = html.match(/<aside\b[\s\S]*?<\/aside>/)?.[0];
        assert.ok(aside);
        assert.match(html, /ui-shell--sidebar/);
        assert.deepEqual(links(aside).slice(1), menus[role]);
        assert.equal((html.match(/aria-label="Navigasi utama"/g) ?? []).length, 1);
        assert.equal((html.match(/Ringkasan notifikasi staf/g) ?? []).length, role === 'keluarga' ? 0 : 1);
        const topbar = html.match(/<header class="ui-shell__header">[\s\S]*?<\/header>/)[0];
        assert.doesNotMatch(topbar, /ui-navigation|ui-topbar__family-navigation|Lansia Keluarga/);
        assert.ok(topbar.includes(userFor(role).name));
        assert.match(topbar, /Buka menu navigasi/);
        assert.equal((html.match(/>Keluar<\/button>/g) ?? []).length, 1);
        assert.doesNotMatch(html, /href="\/logout"/);
        assert.match(html, /href="#main-content"/);
        assert.match(html, /id="main-content" tabindex="-1"/);
        assert.match(html, /Pesan existing/);
        referencesExist(html);
    });
}
for (const [url, active] of [
    ['/keluarga', '/keluarga'],
    ['/keluarga/residents?search=privat', '/keluarga/residents'],
    ['/keluarga/residents/' + id, '/keluarga/residents'],
]) {
    test('UI-7 active sidebar keluarga pada ' + url, async () => {
        const html = await page('AppLayout', {}, 'keluarga', url);
        const activeLinks = [...html.matchAll(/<a\b[^>]*aria-current="page"[^>]*>/g)].map((match) => match[0]);
        assert.equal(activeLinks.length, 1);
        assert.ok(activeLinks[0].includes('href="' + active + '"'));
        const context = navigationContext('keluarga', url);
        assert.equal(context.activeHref, active);
        assert.doesNotMatch(JSON.stringify(context.breadcrumbs), /privat|12345678/);
        assert.doesNotMatch(html, /href="\/(?:admin|perawat|monitoring|notifications)/);
    });
}
for (const role of ['unknown', 'constructor']) {
    test('UI-7 role tidak dikenal tidak mendapat navigasi privileged: ' + role, async () => {
        const html = await page('AppLayout', {}, role);
        assert.doesNotMatch(html, /<aside|<dialog|Buka menu navigasi|Ringkasan notifikasi staf/);
        assert.doesNotMatch(html, /href="\/(?:admin|perawat|keluarga|monitoring|notifications)/);
        assert.match(html, /Konten existing/);
    });
}

for (const open of [false, true]) {
    test('UI-7 drawer keluarga memiliki judul valid saat open=' + open, () => {
        const html = render('Drawer', { id: 'family-drawer', open, user: userFor('keluarga'), activeHref: '/keluarga/residents', onClose() {} });
        referencesExist(html);
        assert.match(html, /aria-labelledby="family-drawer-title"/);
        assert.match(html, /id="family-drawer-title">Menu navigasi/);
        const close = html.match(/<button\b[^>]*data-drawer-close[^>]*>/)?.[0];
        assert.ok(close);
        assert.match(close, /type="button"/);
        assert.doesNotMatch(html, /<dialog[^>]*\sopen(?:=|>)/); // opened by showModal(), not SSR.
        assert.deepEqual(links(html), open ? ['/keluarga', ...menus.keluarga] : []);
        assert.doesNotMatch(html, /Ringkasan notifikasi staf|href="\/(?:admin|perawat|monitoring|notifications)/);
    });
}

test('UI-7 logout tetap hanya POST /logout', () => {
    const calls = [];
    callback('layouts/AppLayout', (node) => node.type === 'ArrowFunctionExpression' && node.body?.callee?.object?.name === 'logout',
        { logout: { post: (...args) => calls.push(args) } })();
    assert.deepEqual(calls, [['/logout']]);
});
test('UI-7 perubahan URL/akun/role menutup drawer dan link meneruskan close', () => {
    let count = 0;
    const closeMenu = () => count++;
    const { ast, code } = sources['layouts/AppLayout'];
    const effects = [];
    walk(ast, (node) => { if (node.type === 'CallExpression' && node.callee?.name === 'useEffect') effects.push(node); });
    assert.equal(effects.length, 1);
    const effect = effects[0];
    assert.deepEqual(effect.arguments[1].elements.map((node) => code.slice(node.start, node.end)), ['url', 'user.id', 'user.role', 'closeMenu']);
    Function('closeMenu', 'return (' + code.slice(effect.arguments[0].start, effect.arguments[0].end) + ')')(closeMenu)();
    assert.equal(count, 1);
    const { ast: drawer } = sources['components/navigation/NavigationDrawer'];
    let onNavigate;
    walk(drawer, (node) => { if (node.type === 'JSXAttribute' && node.name.name === 'onNavigate') onNavigate = node.value.expression; });
    assert.equal(onNavigate.name, 'onClose');
});
const formFor = (overrides = {}) => ({
    data: { email: 'fixture@example.test', password: '' }, errors: {}, processing: false, setData() {}, ...overrides,
});
test('UI-7 login memakai input existing, label valid dan tanpa autofocus', async () => {
    const html = await page('Login', {}, null, '/login');
    assert.equal((html.match(/<h1\b/g) ?? []).length, 1);
    assert.equal((html.match(/<form\b/g) ?? []).length, 1);
    const inputs = [...html.matchAll(/<input\b[^>]*>/g)].map((match) => match[0]);
    assert.equal(inputs.length, 2);
    for (const name of ['email', 'password']) {
        assert.match(html, new RegExp('for="' + name + '"'));
        assert.ok(inputs.some((input) => input.includes('name="' + name + '"') && input.includes('required=""')));
    }
    assert.match(inputs[0], /type="email".*autoComplete="username".*maxLength="254"/);
    assert.match(inputs[1], /type="password".*autoComplete="current-password"/);
    assert.doesNotMatch(html, /autofocus|minlength|pattern=|remember|register|forgot|otp/i);
    assert.deepEqual(links(html), ['/']);
    referencesExist(html);
});
test('UI-7 error login tidak hilang/terinterpolasi menjadi markup', () => {
    const errors = { email: 'Email fixture tidak valid.', password: 'Kata sandi <fixture> salah.', account: 'Akun fixture tidak aktif.' };
    const html = render('LoginForm', { form: formFor({ errors }), onSubmit() {} });
    assert.match(html, /role="alert"/);
    assert.match(html, /Kata sandi &lt;fixture&gt; salah\./);
    assert.match(html, /Akun fixture tidak aktif\./);
    for (const name of ['email', 'password']) {
        const input = [...html.matchAll(/<input\b[^>]*>/g)].map((match) => match[0]).find((tag) => tag.includes('name="' + name + '"'));
        assert.match(input, /aria-invalid="true"/);
        assert.ok(input.includes('aria-describedby="' + name + '-error"'));
    }
    referencesExist(html);
});
test('UI-7 login processing menonaktifkan form dan submit', () => {
    const html = render('LoginForm', { form: formFor({ processing: true }), onSubmit() {} });
    assert.match(html, /<form[^>]*aria-busy="true"/);
    assert.match(html, /<fieldset disabled=""/);
    assert.match(html, /<button[^>]*type="submit"[^>]*disabled=""/);
    assert.match(html, /Memeriksa akun…/);
});
test('UI-7 login mempertahankan payload awal dan POST serta reset hanya password', () => {
    const { ast, code } = sources['pages/Auth/Login'];
    let defaults;
    walk(ast, (node) => { if (node.type === 'CallExpression' && node.callee?.name === 'useForm') defaults = node.arguments[0]; });
    assert.deepEqual(Function('return (' + code.slice(defaults.start, defaults.end) + ')')(), { email: '', password: '' });
    const calls = [], resets = [];
    const data = { email: 'fixture@example.test', password: 'Aa123456' };
    const form = { data, post: (...args) => calls.push(args), reset: (...args) => resets.push(args) };
    let prevented = 0;
    callback('pages/Auth/Login', (node) => node.type === 'FunctionDeclaration' && node.id.name === 'submit', { form })({ preventDefault() { prevented++; } });
    assert.equal(prevented, 1);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/login');
    assert.deepEqual(Object.keys(calls[0][1]), ['onFinish']);
    assert.deepEqual(form.data, data);
    assert.deepEqual(resets, []);
    calls[0][1].onFinish();
    assert.deepEqual(resets, [['password']]);
});
for (const [errors, processing, expected] of [
    [{}, false, null], [{ email: 'Tidak valid' }, false, 'email'],
    [{ account: 'Tidak aktif' }, false, 'summary'], [{ password: 'Tidak valid' }, true, null],
]) {
    test('UI-7 fokus login setelah validasi: ' + JSON.stringify({ errors, processing }), () => {
        const focused = [];
        const elements = { namedItem: (name) => ['email', 'password'].includes(name) ? { focus: () => focused.push(name) } : null };
        const { ast, code } = sources['pages/Auth/Login'];
        let effect;
        walk(ast, (node) => { if (node.type === 'CallExpression' && node.callee?.name === 'useEffect') effect = node.arguments[0]; });
        Function('form', 'element', 'summary', 'focusFormErrors', 'return (' + code.slice(effect.start, effect.end) + ')')(
            formFor({ errors, processing }), { current: { elements } }, { current: { focus: () => focused.push('summary') } }, focusFormErrors,
        )();
        assert.deepEqual(focused, expected ? [expected] : []);
    });
}

for (const [status, title] of [
    [403, 'Akses dibatasi'], [404, 'Halaman tidak ditemukan'], [405, 'Metode tidak tersedia'],
    [409, 'Data telah berubah'], [419, 'Sesi perlu diperbarui'], [429, 'Terlalu banyak permintaan'],
    [500, 'Layanan sedang bermasalah'], [503, 'Layanan belum tersedia'],
]) {
    test('UI-7 error ' + status + ' aman untuk guest dan semua role', async () => {
        for (const role of [null, ...Object.keys(menus)]) {
            const html = await page('Error', { status, message: 'rahasia fixture', trace: 'private fixture', source_reference: 'rtsp://fixture.invalid/private' }, role);
            assert.match(html, new RegExp('<h1[^>]*tabindex="-1"[^>]*>' + title + '</h1>'));
            assert.match(html, new RegExp('>' + status + '</span>'));
            assert.deepEqual(links(html), ['/', '/']);
            assert.equal((html.match(/<h1\b/g) ?? []).length, 1);
            assert.doesNotMatch(html, /<button|<form|rahasia fixture|private fixture|rtsp:|maintenance|pemeliharaan/i);
            assert.equal(html.includes('periksa keadaan data sebelum mengulangi tindakan'), [409, 419, 429, 500, 503].includes(status));
            referencesExist(html);
        }
    });
}
for (const status of [401, 'constructor', '<fixture>']) {
    test('UI-7 status tidak didukung memakai pesan generik: ' + status, async () => {
        const html = await page('Error', { status });
        assert.match(html, /Layanan sedang bermasalah/);
        assert.doesNotMatch(html, /Kode respons|constructor|fixture|401/);
        assert.deepEqual(links(html), ['/', '/']);
    });
}
test('UI-7 halaman error memindahkan fokus ke heading tanpa membuat retry', () => {
    const calls = [];
    const { ast, code } = sources['pages/Error'];
    let effect;
    walk(ast, (node) => { if (node.type === 'CallExpression' && node.callee?.name === 'useEffect') effect = node.arguments[0]; });
    Function('heading', 'return (' + code.slice(effect.start, effect.end) + ')')({ current: { focus: () => calls.push('heading') } })();
    assert.deepEqual(calls, ['heading']);
});

test('UI-7 cleanup modal menghapus listener, mempertahankan overflow, dan memulihkan fokus sekali', () => {
    const dialog = new EventTarget(), media = new EventTarget();
    const listeners = new Map();
    for (const [name, target] of [['dialog', dialog], ['media', media]]) {
        const add = target.addEventListener.bind(target), remove = target.removeEventListener.bind(target);
        target.addEventListener = (type, handler) => { listeners.set(name + type, handler); add(type, handler); };
        target.removeEventListener = (type, handler) => { assert.equal(listeners.get(name + type), handler); listeners.delete(name + type); remove(type, handler); };
    }
    const focused = [];
    dialog.ownerDocument = { body: { style: { overflow: 'scroll' } } };
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new Event('close')); };
    dialog.querySelector = () => ({ focus: () => focused.push('close') });
    let closed = 0;
    const trigger = { isConnected: false, getClientRects: () => [1], focus: () => focused.push('trigger') };
    const fallback = { isConnected: true, focus: () => focused.push('main') };
    const cleanup = openNavigationDialog({ dialog, media, trigger, fallback, onClose: () => closed++ });
    assert.equal(dialog.ownerDocument.body.style.overflow, 'hidden');
    assert.equal(listeners.size, 5);
    const escape = new Event('cancel', { cancelable: true });
    dialog.dispatchEvent(escape);
    assert.ok(escape.defaultPrevented);
    assert.equal(closed, 1);
    cleanup();
    cleanup();
    assert.equal(listeners.size, 0);
    assert.equal(dialog.open, false);
    assert.equal(dialog.ownerDocument.body.style.overflow, 'scroll');
    assert.deepEqual(focused, ['close', 'main']);
    dialog.dispatchEvent(new Event('cancel'));
    const desktop = new Event('change');
    desktop.matches = true;
    media.dispatchEvent(desktop);
    assert.equal(closed, 1);
});

test('UI-7 family/staf memakai breakpoint dan lebar sidebar yang sama; token dan reflow tetap', () => {
    const css = fs.readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.equal(desktopNavigationQuery, '(min-width: 80rem)');
    const desktop = css.slice(css.indexOf('@media (min-width: 80rem)'));
    assert.match(desktop, /\.ui-shell--sidebar\s*\{[^}]*grid-template-columns:\s*15rem minmax\(0, 1fr\)/);
    assert.match(desktop, /\.ui-topbar__menu\s*\{[^}]*display: none/);
    assert.doesNotMatch(css, /ui-topbar__family-navigation|ui-navigation--compact/);
    assert.match(css, /\.ui-topbar__identity[^}]*display: block/);
    assert.match(css, /prefers-reduced-motion: reduce/);
    const scoped = css.slice(css.indexOf('/* UI-7:'));
    assert.match(scoped, /max-width: 31rem/);
    assert.match(scoped, /min-height: 100dvh/);
    assert.match(scoped, /overflow-wrap: anywhere/);
    assert.match(scoped, /forced-colors: active/);
    assert.match(scoped, /:focus-visible\s*\{[^}]*outline:/);
    assert.doesNotMatch(scoped, /#[0-9a-f]{3,8}\b|animation:/i);
});
