import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as pause } from 'node:timers/promises';

let closed = true;
let launchedButUnconfirmed = false;
let controlUnconfirmed = false;
let ok = false;
const contexts = [];
const checks = [];
const name = process.argv[2];
const c3 = name === 'billing-c3';
const responseTimeout = c3 ? 35000 : 12000;
let finishAdmission;

try {
    const bootstrap = c3 ? await import('./billing-c3-bootstrap.mjs') : null;
    const c3Profile = c3 ? await bootstrap.admitNode({ browser: true }) : null;
    if (c3) finishAdmission = (success) => bootstrap.jobCommand(c3Profile, success ? '--revoke' : '--fail');
    const { assertC3NoDatabaseEnvironment, base, equalSecret, ownedRoot, requireCheck, sha256, sourceFingerprint, within }
        = await import('./payment-r2-integration-owned.mjs');
    const { scenarios } = await import('./payment-r2-integration-scenarios.mjs');
    const billingC3 = c3 ? (await import('./billing-c3-scenarios.mjs')).billingC3 : null;
    const acceptance = c3 ? await import('./billing-c3-acceptance.mjs') : null;
    const root = ownedRoot(process.env, c3);
    if (c3) assertC3NoDatabaseEnvironment();
    const plan = c3 ? billingC3 : scenarios[name];
    requireCheck((c3 || Object.hasOwn(scenarios, name)) && typeof plan === 'function', 'unsupported_scenario');
    const origin = process.env.APP_URL;
    const webKey = c3 ? process.env.SILANSIA_BILLING_C3_WEB_KEY : process.env.SILANSIA_05E_WEB_KEY;
    const run = c3 ? process.env.SILANSIA_BILLING_C3_RUN : process.env.SILANSIA_05E_RUN;
    const expectedPid = Number(c3 ? process.env.SILANSIA_BILLING_C3_WEB_PID : process.env.SILANSIA_05E_WEB_PID);
    const webHeaders = { [c3 ? 'x-silansia-c3-web' : 'x-silansia-05e-web']: webKey };
    requireCheck(/^http:\/\/127\.0\.0\.1:[1-9][0-9]{0,4}$/.test(origin)
        && !['8000', '5187'].includes(new URL(origin).port) && /^[a-f0-9]{64}$/.test(webKey)
        && expectedPid > 0, 'owned_browser_endpoint_required');
    const receipt = JSON.parse(readFileSync(within(root, join(root, 'database-receipt.json')), 'utf8'));
    requireCheck(receipt.name === 'silansia_test_' + run && /^[1-9][0-9]*$/.test(receipt.oid)
        && receipt.invocation === run && receipt.runtime_root === process.env.SILANSIA_RUNTIME_ROOT, 'browser_receipt_mismatch');
    if (c3) requireCheck(receipt.owner === 'silansia_c2_' + run && receipt.host === '127.0.0.1'
        && receipt.port !== new URL(origin).port, 'c3_browser_receipt_mismatch');
    else {
        const identity = JSON.parse(process.env.SILANSIA_TEST_IDENTITY);
        requireCheck(identity.database === receipt.name && identity.oid === receipt.oid
            && identity.runtime_root === receipt.runtime_root, 'browser_receipt_mismatch');
    }
    const { digest, ...fields } = receipt;
    requireCheck(equalSecret(digest, createHmac('sha256', process.env.SILANSIA_RUNTIME_OWNER)
        .update(JSON.stringify(fields)).digest('hex')), 'browser_receipt_digest_invalid');
    const assets = JSON.parse(readFileSync(within(root, join(root, 'assets-receipt.json')), 'utf8'));
    const manifest = readFileSync(within(root, join(root, 'public/build/manifest.json')));
    requireCheck(assets.invocation === run && assets.source === sourceFingerprint(c3) && assets.manifest === sha256(manifest)
        && assets.entries.length === 2, 'browser_bundle_provenance_failed');

    // Do not send the per-run header, account credentials, or cookies until the
    // bound listener proves it is the actual child, including its PID.
    const proveListener = pid => acceptance.verifyListener({ origin, pid, key: webKey });
    if (c3) await proveListener(expectedPid);
    else {
        let ready = false;
        for (let n = 0; n < 20 && !ready; n++) {
            const challenge = randomBytes(32).toString('hex');
            try {
                const reply = await fetch(origin + '/__silansia_05e_identity', {
                    redirect: 'error', signal: AbortSignal.timeout(1000), headers: { 'X-Silansia-05E-Challenge': challenge },
                });
                const body = await reply.json();
                ready = reply.ok && body.pid === expectedPid && body.native_web === true
                    && equalSecret(body.challenge, createHmac('sha256', webKey).update(challenge + '|' + expectedPid + '|' + origin).digest('hex'));
            } catch {
                // Bounded startup wait. No fallback listener or origin.
            }
            if (!ready) await pause(150);
        }
        requireCheck(ready, 'loopback_challenge_failed');
    }
    let input = '';
    if (c3) input = readFileSync(within(root, join(root, 'c3-browser-fixture.json')), 'utf8');
    else {
        for await (const chunk of process.stdin) {
            input += chunk.toString('utf8');
            requireCheck(input.length <= 32768, 'fixture_protocol_limit');
        }
    }
    requireCheck(input.length <= 32768, 'fixture_protocol_limit');
    const fixture = JSON.parse(input);
    input = '';
    requireCheck(fixture.ok === true, 'fixture_data_missing');
    const image = { name: 'synthetic-proof.png', mimeType: 'image/png', buffer: Buffer.from(fixture.proof, 'base64') };
    const { chromium } = await import('playwright');

    function runControl(action, args, input = undefined) {
        const env = { ...process.env };
        if (c3) delete env.SILANSIA_BILLING_C3_WEB_KEY;
        if (action === 'scan' && name === 'scanner-off') env.SILANSIA_ACADEMIC_PROOF_SCAN = '0';
        const command = c3 ? [...bootstrap.controlPhpOptions(c3Profile, root), join(base, 'tests/Support/run-payment-r2-integration.php'), '--billing-c3-control']
            : ['tests/Support/payment-r2-integration-child.php'];
        if (c3) {
            return c3Profile.runC3Process(c3Profile.php, [...command, action, ...args], { cwd: root, env },
                action === 'race' ? 220000 : action === 'scan' ? 85000 : 65000, input).then(stdout => {
                const output = JSON.parse(stdout);
                requireCheck(output.ok === true, 'owned_control_response_invalid');
                return output;
            }).catch(error => {
                controlUnconfirmed = true;
                throw error;
            });
        }
        const child = spawnSync(process.env.SILANSIA_05E_PHP, [...command, action, ...args], {
            cwd: base, env, input, encoding: 'utf8', timeout: action === 'race' ? 55000 : 30000, maxBuffer: 262144, windowsHide: true,
        });
        if (child.error) controlUnconfirmed = true;
        requireCheck(child.status === 0 && !child.error, 'owned_control_failed');
        const output = JSON.parse(child.stdout);
        requireCheck(output.ok === true, 'owned_control_response_invalid');
        return output;
    }
    const control = (action, ...args) => runControl(action, args);

    async function client(actor, capabilities = 'default') {
        const tag = contexts.length + 1;
        const profile = join(root, 'chrome', 'profile-' + tag);
        mkdirSync(profile, { mode: 0o700 });
        const safeEnv = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
            /^(path|systemroot|windir|comspec|pathext|programfiles(?:\(x86\))?)$/i.test(key)));
        for (const key of ['TMP', 'TEMP', 'TMPDIR', 'HOME', 'LOCALAPPDATA', 'USERPROFILE']) safeEnv[key] = join(root, 'tmp');
        // The controller can spawn a PHP observer. Chrome receives none of the database or owner credentials.
        launchedButUnconfirmed = true;
        const ctx = await chromium.launchPersistentContext(within(root, profile), {
            ...(c3 ? { executablePath: c3Profile.chrome } : { channel: 'chrome' }),
            ...(c3 ? { timeout: 45000 } : {}),
            headless: true, chromiumSandbox: true, env: safeEnv,
            viewport: { width: 1300, height: 950 }, serviceWorkers: 'block', acceptDownloads: false,
            downloadsPath: within(root, join(root, 'artifacts')), ...(c3 ? {} : { extraHTTPHeaders: webHeaders }),
            ...(name === 'navigation' ? { ignoreDefaultArgs: ['--disable-back-forward-cache'] } : {}),
            args: ['--no-proxy-server', '--disable-background-networking', '--disable-component-update',
                '--disable-sync', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1'],
        });
        contexts.push(ctx);
        launchedButUnconfirmed = false;
        ctx.setDefaultTimeout(c3 ? 45000 : 22000);
        const net = { writes: 0, contexts: 0, reconciles: 0, recoveries: 0, proofs: 0, errors: 0, external: 0, shipped: 0 };
        const hooks = { loss: null, waitForContext: null, waitForAck: null, waitForProof: null,
            denyContext: false, expireCommand: false, expireRecovery: false, context: null, recovery: null };
        const foreignProbe = 'https://silansia-c3-egress.invalid/__silansia_c3_containment_probe';
        let testingContainment = false;
        let containmentBlocked = 0;
        const loaded = new Set();
        const verified = [];
        ctx.on('page', page => page.on('pageerror', () => net.errors++));
        ctx.on('response', response => {
            const entry = assets.entries.find(item => response.url() === origin + item.path);
            if (entry) verified.push(response.body().then(body => {
                requireCheck(response.ok() && sha256(body) === entry.sha256, 'served_bundle_mismatch');
                loaded.add(entry.path);
            }).catch(() => { net.errors++; }));
        });
        await ctx.routeWebSocket('**/*', ws => ws.close());
        await ctx.route('**/*', async route => {
            try {
                const request = route.request();
                const url = new URL(request.url());
                if (c3 ? !acceptance.ownedRequestOrigin(request.url(), origin) : url.origin !== origin) {
                    if (c3 && testingContainment && request.url() === foreignProbe) containmentBlocked++;
                    else net.external++;
                    await route.abort('blockedbyclient');
                    return;
                }
                const path = url.pathname;
                const method = request.method();
                const headers = c3 ? { ...await request.allHeaders(), ...webHeaders } : undefined;
                if (c3 && method === 'GET' && path === hooks.proofAudit) headers['X-Silansia-C3-Proof-Audit'] = 'before-send';
                const fetchOwned = () => route.fetch({ maxRedirects: 0, maxRetries: 0, timeout: responseTimeout, headers });
                if (method === 'GET' && /\/(payment-context|review-context)$/.test(path)) {
                    net.contexts++;
                    if (hooks.denyContext) {
                        await route.abort('failed');
                        return;
                    }
                    await hooks.waitForContext?.();
                    const response = await fetchOwned();
                    if (response.ok()) {
                        const value = await response.json();
                        hooks.context = hooks.expireCommand ? (await runControl('expire-context', [], value.command_context)).token : value.command_context;
                        if (hooks.expireCommand) {
                            await route.fulfill({ response, json: { ...value, command_context: hooks.context } });
                            return;
                        }
                    }
                    await route.fulfill({ response });
                    return;
                }
                if (method === 'GET' && path === '/billing/payment-commands/recovery-context') {
                    net.recoveries++;
                    const response = await fetchOwned();
                    if (response.ok()) {
                        const value = await response.json();
                        hooks.recovery = hooks.expireRecovery ? (await runControl('expire-context', [], value.recovery_context)).token : value.recovery_context;
                        if (hooks.expireRecovery) {
                            await route.fulfill({ response, json: { ...value, recovery_context: hooks.recovery } });
                            return;
                        }
                    }
                    await route.fulfill({ response });
                    return;
                }
                if (method === 'POST' && path === '/billing/payment-commands/reconcile') {
                    net.reconciles++;
                    const data = request.postDataJSON();
                    requireCheck(data.command_context === hooks.context && data.recovery_context === hooks.recovery, 'recovery_context_changed');
                }
                const write = method === 'POST' && /^\/(?:keluarga\/billing\/invoices\/[^/]+\/payments|admin\/billing\/invoices\/[^/]+\/payments\/[^/]+\/(?:verify|reject))$/.test(path);
                if (write) {
                    net.writes++;
                    if (hooks.loss === 'absent') {
                        await hooks.waitForAck?.();
                        await route.abort('failed');
                        return;
                    }
                    net.shipped++;
                    if (hooks.loss === 'committed' || hooks.waitForAck) {
                        const response = await fetchOwned();
                        requireCheck([200, 201].includes(response.status()), 'ack_fault_needs_committed_response');
                        await hooks.waitForAck?.();
                        if (hooks.loss === 'committed') {
                            if (c3) {
                                // Keep the real committed HTTP reply withheld beyond the UI's 15 s deadline.
                                net.timedOut = (net.timedOut ?? 0) + 1;
                                hooks.transportDone = (async () => {
                                    await pause(16000);
                                    await route.abort('timedout');
                                })();
                                await hooks.transportDone;
                            } else await route.abort('failed');
                        }
                        else await route.fulfill({ response }).catch(() => {});
                        return;
                    }
                }
                if (method === 'GET' && path.endsWith('/proof')) {
                    net.proofs++;
                    if (hooks.waitForProof) {
                        const response = await fetchOwned();
                        requireCheck(response.ok(), 'late_preview_needs_valid_get');
                        await hooks.waitForProof();
                        await route.fulfill({ response }).catch(() => {});
                        return;
                    }
                }
                await route.continue(headers ? { headers } : undefined);
            } catch {
                net.errors++;
                await route.abort('failed').catch(() => {});
            }
        });
        if (capabilities === 'locks-unavailable') {
            await ctx.addInitScript(() => Object.defineProperty(navigator, 'locks', { value: undefined }));
        }
        if (capabilities === 'storage-unavailable') {
            await ctx.addInitScript(() => {
                const get = Storage.prototype.getItem;
                Storage.prototype.getItem = function (key) {
                    if (key.startsWith('silansia.payment.hold.')) throw new DOMException('unavailable', 'SecurityError');
                    return get.call(this, key);
                };
            });
        }
        const page = ctx.pages()[0] ?? await ctx.newPage();
        page.on('pageerror', () => net.errors++);
        const open = path => page.goto(origin + path);
        const login = async (tag = actor, expectSuccess = true) => {
            if (c3) await proveListener(expectedPid);
            await open('/login');
            await page.locator('input[name=email]').fill(fixture.accounts[tag].email);
            await page.locator('input[name=password]').fill(fixture.password);
            if (expectSuccess) {
                await page.getByRole('button', { name: /^Masuk/ }).click();
                await page.waitForURL(url => url.pathname !== '/login');
                const cookie = (await ctx.cookies(origin)).find(c => c.name === (c3 ? 'silansia_c3_' : 'silansia_05e_') + run);
                requireCheck(cookie?.httpOnly && cookie.sameSite === 'Lax', 'test_session_cookie_mismatch');
            } else {
                const rejected = page.waitForResponse(r => new URL(r.url()).pathname === '/login' && r.request().method() === 'POST');
                await page.getByRole('button', { name: /^Masuk/ }).click();
                await rejected;
                await page.getByRole('button', { name: /^Masuk/ }).waitFor();
                const denied = await api('/billing/payment-commands/recovery-context');
                requireCheck(denied.status() === 401, 'inactive_actor_obtained_session');
            }
            await Promise.all(verified);
            requireCheck(loaded.size === assets.entries.length, 'app_did_not_load_pinned_assets');
        };
        const api = async (path, { method = 'GET', body, multipart, csrf = 'valid', headers = {} } = {}) => {
            const url = new URL(path, origin);
            requireCheck(url.origin === origin && path.startsWith('/'), 'control_origin_mismatch');
            const requestHeaders = { Accept: 'application/json', ...headers, ...webHeaders };
            if (method === 'POST') {
                if (!multipart) requestHeaders['Content-Type'] ??= 'application/json';
                if (csrf === 'valid') {
                    const token = (await ctx.cookies(origin)).find(c => c.name === 'XSRF-TOKEN');
                    requireCheck(token, 'real_xsrf_cookie_missing');
                    requestHeaders['X-XSRF-TOKEN'] = decodeURIComponent(token.value);
                } else if (csrf === 'invalid') requestHeaders['X-CSRF-TOKEN'] = 'invalid-synthetic-token';
            }
            return ctx.request.fetch(url.href, { method, headers: requestHeaders, data: body, multipart, maxRedirects: 0, maxRetries: 0, timeout: responseTimeout });
        };
        const finalize = async () => {
            if (c3) await hooks.transportDone;
            await Promise.all(verified);
            assert.equal(net.errors, 0);
            assert.equal(net.external, 0);
        };

        const containmentProbe = async () => {
            const probe = await ctx.newPage();
            try {
                testingContainment = true;
                await assert.rejects(probe.goto(foreignProbe, { timeout: 5000 }), /ERR_BLOCKED_BY_CLIENT/);
                assert.equal(containmentBlocked, 1);
            } finally {
                testingContainment = false;
                await probe.close();
            }
        };
        return { ctx, page, net, hooks, open, login, api, finalize, image, origin, ...(c3 ? { containmentProbe } : {}) };
    }

    const clients = [];
    await plan({
        fixture,
        ...(c3 ? { listenerProbe: async () => {
            await assert.rejects(proveListener(expectedPid + 1), { message: 'loopback_challenge_failed' });
            await proveListener(expectedPid);
        } } : {}),
        control,
        controlInput: runControl,
        observe: source => c3 ? control('observe', source.invoice).then(output => output.snapshot) : control('observe', source.invoice).snapshot,
        probe: mode => JSON.parse(readFileSync(within(root, join(root, 'artifacts/ordering-' + mode + '.json')), 'utf8')),
        client: async (...args) => {
            const created = await client(...args);
            clients.push(created);
            return created;
        },
        checked: (...ids) => checks.push(...ids),
    });
    for (const c of clients) await c.finalize();
    ok = sourceFingerprint(c3) === assets.source;
} catch {
    ok = false;
} finally {
    if (finishAdmission) {
        try { await finishAdmission(ok); } catch { closed = false; }
    }
    for (const context of contexts.reverse()) {
        try {
            if (c3) {
                const { withinC3Deadline } = await import('./billing-c3-process.mjs');
                await withinC3Deadline(() => context.close({ reason: 'owned fixture finished' }), 12000);
            } else await context.close({ reason: 'owned fixture finished' });
        } catch {
            closed = false;
            if (c3) break;
        }
    }
    closed = closed && !launchedButUnconfirmed && !controlUnconfirmed;
    process.stdout.write(JSON.stringify({ ok: ok && closed, closed, checks: ok && closed ? [...new Set(checks)] : [] }));
    process.exitCode = ok && closed ? 0 : 1;
}
