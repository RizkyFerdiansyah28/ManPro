import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { inflateSync, crc32 } from 'node:zlib';
import test from 'node:test';
import { assertInitialPreviewUnchanged, assertPrivateError, assertStorageDelta, oversizedEnvelope, ownedRequestOrigin, syntheticPng, verifyListener } from '../Browser/billing-c3-acceptance.mjs';
import { createPaymentApi } from '../../resources/js/utils/payments/api.js';

const a = '2b6fb559-bc4d-473f-a9dd-250d35ce38c1';
const b = '64db11a5-86be-47af-ac6e-80c579b22824';
const origin = 'http://127.0.0.1:34021';
const key = 'a'.repeat(64);

test('C3-01 wraps preview with two equal snapshots and returns the preview result', async () => {
    const snapshot = { graph: { billing_actions: { rows: 16, digest: 'a'.repeat(64) } }, storage: [] };
    const trace = [];
    const result = await assertInitialPreviewUnchanged(async () => {
        trace.push('observe');
        return structuredClone(snapshot);
    }, async () => {
        trace.push('preview');
        return 'synthetic-confirmation';
    });
    assert.deepEqual(trace, ['observe', 'preview', 'observe']);
    assert.equal(result, 'synthetic-confirmation');
});

test('C3-01 rejects changes to the full audit fingerprint even if the action count stays 16', async () => {
    const before = { graph: { billing_actions: { rows: 16, digest: 'a'.repeat(64) } }, storage: [] };
    const after = structuredClone(before);
    after.graph.billing_actions.digest = 'b'.repeat(64);
    const observations = [before, after];
    await assert.rejects(assertInitialPreviewUnchanged(async () => observations.shift(), async () => 'preview'),
        { code: 'ERR_ASSERTION', message: /^C3-01 preview changed the initial billing fingerprint\n/ });
});

test('C3-01 does not send preview if the initial observer rejects the graph', async () => {
    const failure = new Error('billing_c3_initial_invoices_count_mismatch');
    await assert.rejects(assertInitialPreviewUnchanged(async () => { throw failure; },
        async () => assert.fail('preview should not run')), failure);
});

test('acceptance privacy assertion rejects extra detail, nested paths and wrong error codes', () => {
    const body = { correlation_id: a, financial_outcome: 'undisclosed', error: {
        code: 'context_invalid', message: 'Permintaan pembayaran belum dapat diproses.', retry_mode: 'reconcile_original_intent',
    } };
    assert.doesNotThrow(() => assertPrivateError(body, 'context_invalid'));
    assert.throws(() => assertPrivateError({ ...body, debug: 'synthetic-credential' }));
    assert.throws(() => assertPrivateError({ ...body, error: { ...body.error, path: '/private' } }));
    assert.throws(() => assertPrivateError(body, 'context_expired'));
});

test('storage evidence distinguishes existing objects, new complete registry, and replay', () => {
    const proof = '5'.repeat(64);
    const files = { lock: '0'.repeat(64), 'object.png': proof, 'registry.json': '1'.repeat(64) };
    const before = { [a]: files };
    const after = { ...before, [b]: files };
    assert.deepEqual(assertStorageDelta(before, after, 1, proof), new Set([b]));
    assert.deepEqual(assertStorageDelta(after, after, 0, proof), new Set());
    assert.throws(() => assertStorageDelta(before, after, 0, proof));
    assert.throws(() => assertStorageDelta(before, { [a]: { ...files, 'registry.json': '2'.repeat(64) } }, 0, proof));
    assert.throws(() => assertStorageDelta(before, { ...before, [b]: { 'object.png': proof } }, 1, proof));
});

test('dimension fixtures contain real PNG scanlines and CRC', () => {
    for (const [width, height] of [[2049, 1], [1, 2049], [2001, 2000]]) {
        const png = syntheticPng(width, height);
        const ihdr = png.subarray(12, 29);
        const idatBytes = png.readUInt32BE(33);
        const idat = png.subarray(37, 41 + idatBytes);
        assert.equal(ihdr.readUInt32BE(4), width);
        assert.equal(ihdr.readUInt32BE(8), height);
        assert.equal(png.readUInt32BE(29), crc32(ihdr));
        assert.equal(png.readUInt32BE(41 + idatBytes), crc32(idat));
        assert.equal(inflateSync(idat.subarray(4)).length, (width + 1) * height);
        assert.ok(png.length < 2097152);
    }
});

test('envelope exceeds its byte limit with a small proof', () => {
    const proof = syntheticPng(1, 1);
    const { body, headers } = oversizedEnvelope(proof);
    const boundary = headers['Content-Type'].split('boundary=')[1];
    assert.equal(body.length, 2162689);
    assert.equal(body.subarray(0, boundary.length + 2).toString(), '--' + boundary);
    assert.equal(body.subarray(-(boundary.length + 8)).toString(), '\r\n--' + boundary + '--\r\n');
    assert.ok(body.includes(proof));
    assert.ok(proof.length < 2097152);
});

test('C3 checks exact HTTP origin, excluding egress, blob aliases, credentials and other loopback ports', () => {
    assert.equal(ownedRequestOrigin(origin + '/login?synthetic=true', origin), true);
    for (const target of [origin.replace('34021', '34022'), origin.replace('127.0.0.1', 'localhost'),
        'https://example.invalid/', 'blob:' + origin + '/synthetic', 'http://user@127.0.0.1:34021/login']) {
        assert.equal(ownedRequestOrigin(target, origin), false);
    }
});

test('C3 rejects listener PID reuse, wrong signature, stale nonce, redirect and broken transport without sending secrets', async () => {
    const options = { origin, pid: 1272, key };
    const nonces = [];
    function reply(diff = {}) {
        return async (url, request) => {
            assert.equal(url, origin + '/__silansia_c3_identity');
            assert.equal(request.redirect, 'error');
            assert.equal(request.credentials, 'omit');
            assert.deepEqual(Object.keys(request.headers), ['X-Silansia-C3-Challenge']);
            const nonce = request.headers['X-Silansia-C3-Challenge'];
            assert.match(nonce, /^[a-f0-9]{64}$/);
            nonces.push(nonce);
            const challenge = createHmac('sha256', key).update((diff.nonce ?? nonce) + '|1272|' + origin).digest('hex');
            return { ok: true, url: diff.url ?? url, redirected: diff.redirected ?? false, json: async () => ({
                native_web: diff.native ?? true, pid: diff.pid ?? 1272, challenge: diff.challenge ?? challenge,
            }) };
        };
    }
    await verifyListener({ ...options, fetcher: reply() });
    for (const invalid of [{ pid: 1273 }, { nonce: nonces[0] }, { challenge: 'f'.repeat(64) },
        { url: origin + '/login' }, { redirected: true }, { native: false }]) {
        await assert.rejects(verifyListener({ ...options, fetcher: reply(invalid) }), /loopback_challenge_failed/);
    }
    await assert.rejects(verifyListener({ ...options, fetcher: async () => { throw new TypeError('fixture refused'); } }), /loopback_challenge_failed/);
    assert.equal(new Set(nonces).size, nonces.length);
});

test('payment transport timeout settles independently of a pending synthetic response without retry', async () => {
    const pending = Promise.withResolvers();
    let calls = 0;
    const api = createPaymentApi({ timeout: 20, fetcher: async (_path, request) => {
        calls++;
        assert.equal(request.method, 'GET');
        return pending.promise;
    } });
    await assert.rejects(api.commandContext('submit', a), { code: 'network_error' });
    pending.resolve(new Response('{}', { headers: { 'Content-Type': 'application/json' } }));
    assert.equal(calls, 1);
});
