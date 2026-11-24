import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

try {
    const c3 = process.argv[2] === '--billing-c3';
    const admission = c3 ? await (await import('./billing-c3-bootstrap.mjs')).admitNode() : null;
    const { assertC3NoDatabaseEnvironment, base, ownedRoot, requireCheck, sha256, sourceFingerprint, within }
        = await import('./payment-r2-integration-owned.mjs');
    const root = ownedRoot(process.env, c3);
    if (c3) assertC3NoDatabaseEnvironment();
    else requireCheck(!process.env.SILANSIA_TEST_IDENTITY && process.env.SILANSIA_TEST_DB_DATABASE === ''
        && process.env.SILANSIA_TEST_DB_PASSWORD === '', 'asset_stage_may_not_use_database');
    const outDir = within(root, join(root, 'public/build'));
    const cacheDir = within(root, join(root, 'node-cache'));
    requireCheck(readdirSync(outDir).length === 0, 'owned_build_not_empty');
    const before = sourceFingerprint(c3);
    const [{ build }, { default: react }, { default: tailwindcss }] = await Promise.all([
        import('vite'), import('@vitejs/plugin-react'), import('@tailwindcss/vite'),
    ]);
    const buildInputs = c3 ? [(await import('./billing-c3-build.mjs')).c3BuildInputs(base, admission.files)] : [];
    // The application entry points are real. The harness avoids the main config's external font plugin.
    await build({
        root: base,
        configFile: false,
        envFile: false,
        cacheDir,
        publicDir: false,
        logLevel: 'silent',
        base: '/build/',
        plugins: [...buildInputs, react(), tailwindcss()],
        ...(c3 ? { css: { postcss: { plugins: [] } } } : {}),
        build: {
            outDir,
            emptyOutDir: false,
            manifest: 'manifest.json',
            sourcemap: false,
            rollupOptions: { input: [join(base, 'resources/css/app.css'), join(base, 'resources/js/app.jsx')] },
        },
    });
    requireCheck(before === sourceFingerprint(c3), 'bundle_sources_changed');
    const manifestBytes = readFileSync(within(root, join(outDir, 'manifest.json')));
    const manifest = JSON.parse(manifestBytes);
    const entries = ['resources/css/app.css', 'resources/js/app.jsx'].map(key => {
        const file = manifest[key]?.file ?? '';
        requireCheck(/^assets\/[A-Za-z0-9_.-]+\.(js|css)$/.test(file), 'invalid_manifest_entry');
        return { path: '/build/' + file, sha256: sha256(readFileSync(within(root, join(outDir, file)))) };
    });
    const files = {};
    if (c3) {
        for (const entry of readdirSync(within(root, join(outDir, 'assets')), { withFileTypes: true })) {
            requireCheck(entry.isFile() && !entry.isSymbolicLink() && /^[A-Za-z0-9_.-]+\.(?:js|css|woff2?|svg|png|jpg|webp)$/.test(entry.name), 'invalid_owned_asset');
            files['/build/assets/' + entry.name] = sha256(readFileSync(within(root, join(outDir, 'assets', entry.name))));
        }
        requireCheck(entries.every(entry => files[entry.path] === entry.sha256), 'incomplete_owned_asset_build');
    }
    writeFileSync(join(root, 'assets-receipt.json'), JSON.stringify({
        invocation: c3 ? process.env.SILANSIA_BILLING_C3_RUN : process.env.SILANSIA_05E_RUN,
        source: before, manifest: sha256(manifestBytes), entries, ...(c3 ? { files } : {}),
    }), { flag: 'wx', mode: 0o600 });
    process.stdout.write('{"ok":true}');
} catch {
    process.stdout.write('{"ok":false}');
    process.exitCode = 1;
}
