import assert from 'node:assert/strict';
import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import { crc32, deflateSync } from 'node:zlib';

const uuid = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;

export async function assertInitialPreviewUnchanged(observe, preview) {
    const before = await observe();
    const result = await preview();
    assert.deepEqual(await observe(), before, 'C3-01 preview changed the initial billing fingerprint');
    return result;
}

export function assertPrivateError(body, code) {
    assert.deepEqual(Object.keys(body).sort(), ['correlation_id', 'error', 'financial_outcome']);
    assert.match(body.correlation_id, uuid);
    assert.equal(body.financial_outcome, 'undisclosed');
    assert.match(body.error.code, /^[a-z][a-z0-9_]{0,79}$/);
    assert.deepEqual(body.error, { code: code ?? body.error.code,
        message: 'Permintaan pembayaran belum dapat diproses.', retry_mode: 'reconcile_original_intent' });
}

export function assertStorageDelta(before, after, expected, proofHash) {
    for (const [reference, files] of Object.entries(before)) assert.deepEqual(after[reference], files);
    const added = Object.keys(after).filter(reference => !Object.hasOwn(before, reference));
    assert.equal(added.length, expected);
    for (const reference of added) {
        assert.match(reference, uuid);
        assert.deepEqual(Object.keys(after[reference]).sort(), ['lock', 'object.png', 'registry.json']);
        assert.equal(after[reference]['object.png'], proofHash);
        assert.match(after[reference]['registry.json'], /^[a-f0-9]{64}$/);
    }
    return new Set(added);
}

// Grayscale PNG with valid chunk CRC and compressed scanlines, including oversized dimensions.
export function syntheticPng(width, height) {
    const chunk = (type, bytes) => {
        const header = Buffer.alloc(8);
        header.writeUInt32BE(bytes.length);
        header.write(type, 4);
        const crc = Buffer.alloc(4);
        crc.writeUInt32BE(crc32(Buffer.concat([header.subarray(4), bytes])));
        return Buffer.concat([header, bytes, crc]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    return Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', ihdr),
        chunk('IDAT', deflateSync(Buffer.alloc((width + 1) * height))), chunk('IEND', Buffer.alloc(0))]);
}

export function oversizedEnvelope(proof) {
    const boundary = 'silansia-c3-synthetic-envelope';
    const start = Buffer.from('--' + boundary + '\r\nContent-Disposition: form-data; name="proof"; filename="fixture.png"\r\n'
        + 'Content-Type: image/png\r\n\r\n');
    const field = Buffer.from('\r\n--' + boundary + '\r\nContent-Disposition: form-data; name="padding"\r\n\r\n');
    const end = Buffer.from('\r\n--' + boundary + '--\r\n');
    const length = 2162689;
    const padding = Buffer.alloc(length - start.length - proof.length - field.length - end.length, 0x41);
    return { body: Buffer.concat([start, proof, field, padding, end]), headers: { 'Content-Type': 'multipart/form-data; boundary=' + boundary } };
}

export function ownedRequestOrigin(url, origin) {
    const target = new URL(url);
    return /^http:\/\/127\.0\.0\.1:[1-9][0-9]{0,4}$/.test(origin)
        && target.protocol === 'http:' && target.origin === origin && !target.username && !target.password;
}

export async function verifyListener({ origin, pid, key, fetcher = fetch }) {
    const nonce = randomBytes(32).toString('hex');
    const path = origin + '/__silansia_c3_identity';
    if (!Number.isSafeInteger(pid) || pid < 1 || !/^[a-f0-9]{64}$/.test(key) || !ownedRequestOrigin(path, origin)) {
        throw new Error('loopback_challenge_failed');
    }
    try {
        const reply = await fetcher(path, { redirect: 'error', credentials: 'omit', cache: 'no-store',
            signal: AbortSignal.timeout(35000), headers: { 'X-Silansia-C3-Challenge': nonce } });
        const facts = await reply.json();
        const expected = createHmac('sha256', key).update(nonce + '|' + pid + '|' + origin).digest();
        if (reply.ok && !reply.redirected && reply.url === path && facts.pid === pid && facts.native_web === true
            && /^[a-f0-9]{64}$/.test(facts.challenge) && timingSafeEqual(Buffer.from(facts.challenge, 'hex'), expected)) return;
    } catch {
        // A refused connection or invalid body has the same fail-closed result.
    }
    throw new Error('loopback_challenge_failed');
}
