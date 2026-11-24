import assert from 'node:assert/strict';
import { cpSync, linkSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import test from 'node:test';
import { build } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { c3BuildInputs, c3TailwindInput } from '../Browser/billing-c3-build.mjs';

const projectCss = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const cssEntry = 'resources/css/app.css';

function scratch(t) {
    const parent = realpathSync(tmpdir());
    const root = mkdtempSync(join(parent, 'silansia-c3-build-'));
    t.after(() => {
        assert.equal(realpathSync(root), root);
        assert.equal(dirname(root), parent);
        assert.match(basename(root), /^silansia-c3-build-[a-z0-9]+$/i);
        rmSync(root, { recursive: true });
    });
    return root;
}

function write(root, name, bytes) {
    const path = join(root, name);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, bytes);
    return path;
}

test('C3 CSS closes automatic scanning and rejects a new source or plugin directive', () => {
    const css = c3TailwindInput(projectCss);
    assert.match(css, /tailwindcss' source\(none\)/);
    assert.match(css, /@source '\.\.\/views';/);
    assert.doesNotMatch(css, /storage\/framework\/views/);
    assert.throws(() => c3TailwindInput(projectCss + "\n@source '../../outside';"), /billing_c3_build_css_inputs_unreviewed/);
    assert.throws(() => c3TailwindInput(projectCss + "\n@plugin '../../outside';"), /billing_c3_build_css_inputs_unreviewed/);
});

test('Vite emits the same CSS when an unpinned compiled view changes outside the source closure', async t => {
    const root = scratch(t);
    const css = write(root, cssEntry, projectCss);
    write(root, 'resources/css/invoices.css', '');
    write(root, 'resources/js/x.jsx', '<div className="bg-[#123456]" />');
    write(root, 'resources/views/x.blade.php', '<div class="bg-[#234567]"></div>');
    write(root, 'vendor/laravel/framework/src/Illuminate/Pagination/resources/views/x.blade.php', '<div class="bg-[#345678]"></div>');
    const view = write(root, 'storage/framework/views/generated.php', '<div class="bg-[#543210]"></div>');
    cpSync(new URL('../../node_modules/tailwindcss/', import.meta.url), join(root, 'node_modules/tailwindcss'), { recursive: true });

    const pins = Object.fromEntries([cssEntry, 'resources/css/invoices.css', 'resources/js/x.jsx', 'resources/views/x.blade.php']
        .map(path => [path, true]));
    const render = async () => {
        const result = await build({
            root, configFile: false, envFile: false, publicDir: false, logLevel: 'silent',
            cacheDir: join(root, 'cache'), css: { postcss: { plugins: [] } },
            plugins: [c3BuildInputs(root, pins), tailwindcss()],
            build: { write: false, rollupOptions: { input: css } },
        });
        const styles = result.output.filter(entry => entry.type === 'asset' && entry.fileName.endsWith('.css'));
        assert.equal(styles.length, 1);
        return String(styles[0].source);
    };
    const first = await render();
    for (const hex of ['123456', '234567', '345678']) assert.match(first, new RegExp('#' + hex));
    assert.doesNotMatch(first, /#543210|#654321/);
    writeFileSync(view, '<div class="bg-[#654321]"></div>');
    assert.equal(await render(), first);
});

test('C3 build rejects unpinned files, hard links and inputs reached through a junction', t => {
    const root = scratch(t);
    const original = write(root, 'resources/js/original.js', 'export {};');
    const link = join(dirname(original), 'linked.js');
    const directoryLink = join(root, 'resources/alias');
    write(root, 'resources/real/outside.js', 'export {};');
    linkSync(original, link);
    symlinkSync(join(root, 'resources/real'), directoryLink, process.platform === 'win32' ? 'junction' : 'dir');
    const plugin = c3BuildInputs(root, { 'resources/js/linked.js': true, 'resources/alias/outside.js': true });
    assert.throws(() => plugin.load(original), /billing_c3_build_input_unpinned/);
    assert.throws(() => plugin.load(link), /billing_c3_build_input_unverified/);
    assert.throws(() => plugin.load(join(directoryLink, 'outside.js')), /billing_c3_build_input_unverified/);
});
