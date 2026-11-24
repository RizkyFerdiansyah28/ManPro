import { createHash, timingSafeEqual } from 'node:crypto';
import { lstatSync, readFileSync, realpathSync, readdirSync } from 'node:fs';
import { basename, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const base = fileURLToPath(new URL('../../', import.meta.url));
export const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
const slash = path => path.split(sep).join('/');

export function requireCheck(condition, reason) {
    if (!condition) throw new Error(reason);
}

export function canonical(path) {
    const normalized = resolve(path);
    requireCheck(slash(normalized) === slash(realpathSync(normalized)) && !lstatSync(normalized).isSymbolicLink(), 'path_invalid');
    return normalized;
}

export function within(root, file) {
    let path = canonical(file);
    const result = path;
    const sub = relative(root, path);
    requireCheck(sub && !sub.startsWith('..') && !isAbsolute(sub), 'path_escape');
    while (path !== root) {
        canonical(path);
        path = dirname(path);
    }
    return result;
}

export function equalSecret(a, b) {
    return typeof a === 'string' && typeof b === 'string' && /^[a-f0-9]{64}$/.test(a) && /^[a-f0-9]{64}$/.test(b)
        && timingSafeEqual(Buffer.from(a, 'hex'), Buffer.from(b, 'hex'));
}

export function ownedRoot(env = process.env, c3 = false) {
    const run = c3 ? env.SILANSIA_BILLING_C3_RUN : env.SILANSIA_05E_RUN;
    const stage = c3 ? /^[a-f0-9]{64}$/.test(env.SILANSIA_BILLING_C3_MANIFEST) : env.SILANSIA_05E_STAGE === 'authorized-05e2';
    requireCheck(stage && env.APP_ENV === 'testing'
        && env.DB_CONNECTION === 'silansia_testing' && /^[a-f0-9]{16}$/.test(run)
        && env.SILANSIA_P94E1_VERIFY === '1', 'run_context_missing');
    const root = canonical(env.SILANSIA_RUNTIME_ROOT);
    requireCheck(basename(root) === 'silansia-p94e1-' + run, 'run_root_mismatch');
    const repoRelative = relative(canonical(base), root);
    requireCheck(repoRelative.startsWith('..' + sep) || isAbsolute(repoRelative), 'workspace_root_invalid');
    const marker = JSON.parse(readFileSync(within(root, join(root, 'owner.json')), 'utf8'));
    requireCheck(marker.root === env.SILANSIA_RUNTIME_ROOT && equalSecret(marker.token, env.SILANSIA_RUNTIME_OWNER), 'run_owner_mismatch');
    for (const field of ['TMP', 'TEMP', 'TMPDIR']) {
        requireCheck(within(root, env[field]) === join(root, 'tmp'), 'child_temp_mismatch');
    }
    return root;
}

export function assertC3NoDatabaseEnvironment(env = process.env) {
    requireCheck(Object.keys(env).every(name => {
        const key = name.toUpperCase();
        return !key.startsWith('PG') && !key.startsWith('SILANSIA_TEST_DB_') && !key.startsWith('SILANSIA_05E')
            && !['SILANSIA_TEST_IDENTITY', 'DATABASE_URL', 'APP_KEY', 'APP_PREVIOUS_KEYS'].includes(key)
            && (key === 'DB_CONNECTION' || !key.startsWith('DB_'));
    }), 'c3_browser_database_environment');
}

export function sourceFingerprint(c3 = false) {
    const files = ['package.json', 'package-lock.json', 'vite.config.js'];
    const collect = directory => {
        for (const entry of readdirSync(join(base, directory), { withFileTypes: true })) {
            const name = directory + '/' + entry.name;
            within(canonical(base), join(base, name));
            requireCheck(!entry.isSymbolicLink(), 'asset_source_link');
            if (entry.isDirectory()) collect(name);
            else if (entry.isFile()) files.push(name);
            else throw new Error('asset_source_not_regular');
        }
    };
    collect('resources/js');
    collect('resources/css');
    if (c3) {
        collect('resources/views');
        files.push('tests/Browser/billing-c3-build.mjs', 'tests/Browser/payment-r2-integration-assets.mjs');
    }
    return sha256(JSON.stringify(files.sort().map(name => [name, sha256(readFileSync(join(base, name)))])));
}
