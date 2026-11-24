import { createHash } from 'node:crypto';
import { lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { performance } from 'node:perf_hooks';

const base = fileURLToPath(new URL('../../', import.meta.url));
const digest = value => createHash('sha256').update(value).digest('hex');
const hex = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const samePath = (a, b) => a.replaceAll('\\', '/').toLowerCase() === b.replaceAll('\\', '/').toLowerCase();

function check(condition, reason) {
    if (!condition) throw new Error(reason);
}

function canonical(path, directory = false) {
    check(isAbsolute(path) && samePath(realpathSync(path), path), 'billing_c3_dependency_path_alias');
    const stat = lstatSync(path);
    check(!stat.isSymbolicLink() && (directory ? stat.isDirectory() : stat.isFile()), 'billing_c3_dependency_path_reparse');
}

export function treeDigest(root, deadline = Infinity) {
    check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
    canonical(root, true);
    const hash = createHash('sha256');
    function walk(dir, prefix) {
        for (const name of readdirSync(dir).sort()) {
            check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
            check(!/[\x00-\x1f\x7f-\uffff]/.test(name), 'billing_c3_dependency_path_invalid');
            const path = join(dir, name);
            const relative = prefix + name;
            const directory = lstatSync(path).isDirectory();
            canonical(path, directory);
            if (directory) {
                hash.update(`D\t${relative}\n`);
                walk(path, `${relative}/`);
            } else {
                hash.update(`F\t${relative}\t${digest(readFileSync(path))}\n`);
            }
        }
    }
    walk(root, '');
    check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
    return hash.digest('hex');
}

export function assertDependencyTree(root, sha256, deadline = Infinity) {
    check(hex(sha256) && treeDigest(root, deadline) === sha256, 'billing_c3_dependency_tree_mismatch');
}

function readPin(path, expected) {
    check(hex(expected), 'billing_c3_dependency_policy_digest_required');
    canonical(path);
    check(lstatSync(path).size <= 1_000_000, 'billing_c3_dependency_policy_invalid');
    const bytes = readFileSync(path);
    check(digest(bytes) === expected, 'billing_c3_dependency_policy_digest_mismatch');
    return JSON.parse(bytes);
}

function assertSourcePin(project, key, sha256) {
    check(/^[a-z0-9_.@ -]+(?:\/[a-z0-9_.@ -]+)*$/i.test(key)
        && !key.split('/').some(part => part === '.' || part === '..') && hex(sha256), 'billing_c3_dependency_source_pin_invalid');
    const path = join(project, key);
    canonical(path);
    check(digest(readFileSync(path)) === sha256, 'billing_c3_dependency_source_pin_mismatch');
}

// Only C3 calls this gate. No package or shared scenario has executed when it runs.
export async function admitNode({ browser = false } = {}) {
    const deadline = performance.now() + 60000;
    const env = process.env;
    check(process.platform === 'win32' && process.execArgv.length === 0 && Object.keys(env).every(key =>
        !/^(NODE_OPTIONS|NODE_PATH|NODE_EXTRA_CA_CERTS|PHPRC|PHP_INI_SCAN_DIR)$/i.test(key)), 'billing_c3_boot_environment_override');
    const manifestDigest = env.SILANSIA_BILLING_C3_MANIFEST;
    const policy = readPin(join(base, 'tests/Support/billing-c3-dependency-policy.json'), env.SILANSIA_BILLING_C3_DEPENDENCY_POLICY);
    const manifest = readPin(join(base, 'tests/Support/billing-c3-source-manifest.json'), manifestDigest);
    check(policy.version === 1 && policy.source_manifest_sha256 === manifestDigest
        && samePath(process.execPath, manifest.tools.node.path)
        && samePath(join(policy.roots.node.path, 'node.exe'), manifest.tools.node.path), 'billing_c3_dependency_runtime_root_mismatch');
    const project = resolve(base);
    canonical(project, true);
    // Reject dependencies placed above the reviewed project as module resolution fallbacks.
    for (let dir = dirname(project); ; dir = dirname(dir)) {
        check(lstatSync(join(dir, 'node_modules'), { throwIfNoEntry: false }) === undefined, 'billing_c3_dependency_ancestor_modules');
        if (dirname(dir) === dir) break;
    }
    for (const [key, sha256] of Object.entries(manifest.files)) {
        check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
        assertSourcePin(project, key, sha256);
    }
    for (const key of ['tests/Browser/billing-c3-process.mjs', 'tests/Support/billing-c3-supervisor.php',
        'tests/Support/BillingC3ProcessJob.php', ...(!browser ? ['tests/Browser/billing-c3-build.mjs'] : [])]) {
        assertSourcePin(project, key, manifest.files[key]);
    }
    assertDependencyTree(join(project, 'node_modules'), policy.node_modules_sha256, deadline);
    if (!browser) assertDependencyTree(join(project, 'vendor'), policy.vendor_sha256, deadline);
    assertDependencyTree(policy.roots.node.path, policy.roots.node.sha256, deadline);
    check(samePath(join(policy.roots.php.path, 'php.exe'), manifest.tools.php.path), 'billing_c3_dependency_runtime_root_mismatch');
    if (browser) {
        check(samePath(join(policy.roots.chrome.path, 'chrome.exe'), manifest.tools.chrome.path), 'billing_c3_dependency_runtime_root_mismatch');
        assertDependencyTree(policy.roots.chrome.path, policy.roots.chrome.sha256, deadline);
    }
    assertDependencyTree(policy.roots.php.path, policy.roots.php.sha256, deadline);
    check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
    const { runC3Process, withinC3Deadline } = await import('./billing-c3-process.mjs');
    const profile = { php: manifest.tools.php.path, chrome: manifest.tools.chrome.path, phpTree: policy.roots.php, project, files: manifest.files,
        runC3Process, withinC3Deadline };
    await jobCommand(profile, '--probe');
    return profile;
}

export function jobCommand(profile, operation) {
    const root = process.env.SILANSIA_RUNTIME_ROOT;
    canonical(root, true);
    return profile.runC3Process(profile.php, [...controlPhpOptions(profile, root),
        join(base, 'tests/Support/billing-c3-supervisor.php'), operation, String(process.pid)], { cwd: root, env: process.env }, 8000);
}

export function controlPhpOptions(profile, root) {
    const deadline = performance.now() + 60000;
    assertDependencyTree(profile.phpTree.path, profile.phpTree.sha256, deadline);
    for (const name of ['BillingC3Bootstrap.php', 'BillingC3ProcessJob.php', 'billing-c3-supervisor.php', 'run-payment-r2-integration.php']) {
        const key = 'tests/Support/' + name;
        assertSourcePin(profile.project, key, profile.files[key]);
    }
    check(performance.now() < deadline, 'billing_c3_dependency_deadline_exceeded');
    const extensions = profile.phpTree.path + '/ext';
    const options = ['-n', '-d', 'extension_dir=' + extensions, '-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0',
        '-d', 'opcache.enable=0', '-d', 'include_path=.', '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0'];
    for (const name of ['curl', 'ffi', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_pgsql']) {
        options.push('-d', `extension=${extensions}/php_${name}.dll`);
    }
    return [...options, '-d', `sys_temp_dir=${root}\\tmp`, '-d', `upload_tmp_dir=${root}\\uploads`,
        '-d', `session.save_path=${root}\\storage\\framework\\sessions`, '-d', `error_log=${root}\\storage\\logs\\php-errors.log`, '-d', 'log_errors=1'];
}
