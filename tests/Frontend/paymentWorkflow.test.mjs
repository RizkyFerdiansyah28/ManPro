import test from 'node:test';
import assert from 'node:assert/strict';
import { createPaymentApi, PaymentApiError, validateProofFile, invoice } from '../../resources/js/utils/payments/api.js';
import { createPaymentWorkflow, sameUiContext } from '../../resources/js/utils/payments/workflow.js';
import { installPaymentSessionBoundary, paymentHoldKey, watchPaymentHold } from '../../resources/js/utils/payments/sessionBoundary.js';

const id = '12345678-1234-4234-8234-123456789abc', payment = '12345678-1234-4234-8234-123456789abd';
const context = { version: 1, actor_id: id, role: 'keluarga', session_context: 'a'.repeat(64), audience_context: 'b'.repeat(64), hold_key: 'c'.repeat(64) };
const command = 'SYNTHETIC-CONTEXT-MEMORY-ONLY';
const file = { name: 'proof.png', size: 100, type: 'image/png' };
const result = { command_fact: 'submission_recorded', invoice_id: id, revision_id: id, payment_id: payment, amount: '3100.00', recorded_at: '2026-11-16T00:00:00.000000Z', replayed: false };
const response = (value, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });
const correlation = '12345678-1234-4234-8234-123456789abe';
const proofPath = '/billing/invoices/' + id + '/payments/' + payment + '/proof';
const errorEnvelope = code => ({
    error: { code, message: 'SYNTHETIC-INTERNAL-EXCEPTION', retry_mode: 'reconcile_original_intent' },
    financial_outcome: 'undisclosed',
    correlation_id: correlation,
});
function lockFixture() {
    const held = new Set();
    return { request: async (key, options, callback) => {
        assert.deepEqual(options, { mode: 'exclusive', ifAvailable: true });
        if (held.has(key)) return callback(null);
        held.add(key);
        try { return await callback({ name: key }); } finally { held.delete(key); }
    } };
}
const memoryStorage = values => ({ getItem: k => values.get(k) ?? null, setItem: (k,v) => values.set(k,v), removeItem: k => values.delete(k) });
function fixture(overrides = {}, role = 'keluarga', uiPath = '/synthetic', coordination = {}) {
    const { values = new Map(), locks = lockFixture(), legacyStorage, context: scope } = coordination;
    const calls = [], states = [];
    const ctx = { ...context, role, ...scope };
    const storage = memoryStorage(values);
    const api = { uiContext: async () => ctx, commandContext: async () => { calls.push('context'); return command; }, mutate: async (op, i, p, token) => { calls.push(['mutation', op, token]); return result; }, reconcile: async (op, i, p, token, ready) => { assert.equal(await ready(), true); calls.push(['reconcile', op, token]); return { ...result, replayed: true }; }, ...overrides };
    const flow = createPaymentWorkflow({ api, target: { operation: role === 'keluarga' ? 'submit' : null, invoiceId: id, paymentId: role === 'admin' ? payment : null, uiPath }, context: ctx, storage, legacyStorage, locks, onChange: v => states.push(v) });
    return { flow, api, ctx, values, calls, storage, states };
}
test('one explicit multipart submit, no amount/actor/status/path, credentials and CSRF, no fabricated boundary', async () => {
    const calls = [], entries = [];
    const api = createPaymentApi({ doc: { cookie: 'XSRF-TOKEN=synthetic%3D' }, formData: () => ({ append: (...v) => entries.push(v) }), fetcher: async (path, options) => { calls.push({ path, options }); return response(result, 201); } });
    await api.mutate('submit', id, null, command, { file, amount: '0.01', actor_id: payment, proof_path: 'forbidden' });
    assert.equal(calls.length, 1); assert.equal(calls[0].options.credentials, 'same-origin'); assert.equal(calls[0].options.cache, 'no-store');
    assert.equal(calls[0].options.headers['Content-Type'], undefined); assert.equal(calls[0].options.headers['X-XSRF-TOKEN'], 'synthetic=');
    assert.deepEqual(entries.map(v => v[0]), ['command_context','confirm','proof']); assert.equal(entries[2][1], file);
});
test('scanner unavailability retains the original submission and never retries the financial POST', async () => {
    const requests = [], fields = [];
    const api = createPaymentApi({
        doc: { cookie: 'XSRF-TOKEN=synthetic' },
        formData: () => ({ append: (...entry) => fields.push(entry) }),
        fetcher: async (path, options) => {
            requests.push({ path, method: options.method });
            return response(errorEnvelope('proof_scan_unavailable'), 503);
        },
    });
    const { flow, values, calls } = fixture({ mutate: api.mutate });
    const submit = { operation: 'submit', confirm: true, file };
    await flow.initialize();
    await flow.execute(submit);
    assert.deepEqual(flow.snapshot(), { name: 'unknown', fact: null, error: { code: 'proof_scan_unavailable', status: 503, correlation } });
    assert.deepEqual([...values.values()], ['1']);
    assert.deepEqual(fields, [['command_context', command], ['confirm', '1'], ['proof', file]]);

    await flow.execute(submit);
    assert.deepEqual(calls, ['context']);
    assert.deepEqual(requests, [{ path: '/keluarga/billing/invoices/' + id + '/payments', method: 'POST' }]);

    await flow.reconcile();
    assert.deepEqual(calls, ['context', ['reconcile', 'submit', command]]);
    assert.equal(requests.length, 1);
    assert.equal(flow.snapshot().name, 'resolved');
    assert.equal(values.size, 0);
});
test('protected proof 503 preserves the public code, status and correlation without reading image bytes or retrying', async () => {
    const envelope = errorEnvelope('proof_use_unavailable');
    const reply = response({
        ...envelope,
        error: { ...envelope.error, scanner: 'SYNTHETIC-PRIVATE-VERDICT' },
        status: 200,
        proof_path: 'SYNTHETIC-PRIVATE-PATH',
        financial_outcome: 'paid',
    }, 503);
    reply.arrayBuffer = () => assert.fail('A denied proof must never be consumed as image bytes');
    const requests = [];
    const api = createPaymentApi({ fetcher: async (path, options) => { requests.push({ path, options }); return reply; } });

    assert.deepEqual(requests, []);
    await assert.rejects(api.proof(id, payment), error => {
        assert.ok(error instanceof PaymentApiError);
        assert.deepEqual({ ...error }, { code: 'proof_use_unavailable', status: 503, correlation });
        assert.equal(error.message, 'Permintaan pembayaran belum dapat diproses.');
        return true;
    });
    assert.deepEqual(requests, [{
        path: proofPath,
        options: { method: 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', redirect: 'error' },
    }]);
});
test('family detail yields scoped attempt IDs without private fields and only the chosen pair loads', async () => {
    const calls = [];
    const detailPath = '/keluarga/billing/invoices/' + id;
    const api = createPaymentApi({ fetcher: async (path, options) => {
        calls.push({ path, method: options.method });
        if (path === detailPath) return response({
            invoice: { id, elderly_id: payment, number: 'SIM-1', billing_month: '2026-10-01', status: 'unpaid', amount: '3100.00', due_date: '2026-11-23', grace_period_ends_on: '2026-11-26' },
            payments: [{ id: payment, status: 'rejected', submitted_at: result.recorded_at, public_rejection_reason: 'Ganti bukti dummy.',
                private_note: 'SYNTHETIC-PRIVATE-NOTE', scanner_verdict: 'SYNTHETIC-PRIVATE-VERDICT', proof_path: '/storage/private/synthetic' }],
        });
        assert.equal(path, proofPath);
        return new Response(new Uint8Array([1, 2, 3]), { headers: { 'content-type': 'image/png' } });
    } });
    const detail = await api.detail('keluarga', id);
    assert.deepEqual(calls, [{ path: detailPath, method: 'GET' }]);
    assert.deepEqual(detail.payments, [{ id: payment, status: 'rejected', submitted_at: result.recorded_at, public_rejection_reason: 'Ganti bukti dummy.' }]);
    assert.equal(detail.invoice.status, 'unpaid');
    const blob = await api.proof(detail.invoice.id, detail.payments[0].id);
    assert.deepEqual(calls.slice(1), [{ path: proofPath, method: 'GET' }]);
    assert.equal(blob.type, 'image/png');
    assert.deepEqual(new Uint8Array(await blob.arrayBuffer()), new Uint8Array([1, 2, 3]));
    assert.equal(detail.invoice.status, 'unpaid');
    assert.equal(detail.payments[0].status, 'rejected');
});
test('unknown public code stays generic with the original HTTP status and validated correlation', async () => {
    const api = createPaymentApi({ fetcher: async () => response(errorEnvelope('synthetic_unknown_kind'), 503) });
    await assert.rejects(api.proof(id, payment), {
        code: 'request_failed', status: 503, correlation, message: 'Permintaan pembayaran belum dapat diproses.',
    });
});
for (const [scenario, envelope] of [
    ['error is missing', { correlation_id: correlation }],
    ['code is not a string', { ...errorEnvelope('ignored'), error: { code: 503 } }],
    ['code exceeds limit', errorEnvelope('x'.repeat(81))],
    ['correlation is invalid', { ...errorEnvelope('proof_use_unavailable'), correlation_id: 'not-a-uuid' }],
]) test('proof error envelope is rejected when ' + scenario, async () => {
    const api = createPaymentApi({ fetcher: async () => response(envelope, 503) });
    await assert.rejects(api.proof(id, payment), { code: 'malformed_response', status: 0, correlation: null });
});
for (const [status, code] of [[401, 'authentication_required'], [403, 'forbidden'], [404, 'not_found']]) {
    test('protected proof keeps scoped HTTP denial ' + status, async () => {
        const api = createPaymentApi({ fetcher: async () => response(errorEnvelope(code), status) });
        await assert.rejects(api.proof(id, payment), { code, status, correlation });
    });
}
test('verify proof denial retains the original intent until explicit reconcile returns its canonical fact', async () => {
    const requests = [];
    const ctx = { ...context, role: 'admin' };
    const adminPath = '/admin/billing/invoices/' + id + '/payments/' + payment;
    const uiPath = '/admin/pembayaran/' + id + '/' + payment;
    const recoveryPath = '/billing/payment-commands/recovery-context';
    const reconcilePath = '/billing/payment-commands/reconcile';
    const replay = { ...result, command_fact: 'verification_recorded', replayed: true };
    const api = createPaymentApi({
        doc: { cookie: 'XSRF-TOKEN=fixture' },
        fetcher: async (path, options) => {
            requests.push({ method: options.method, path, body: options.body });
            if (path === uiPath) return response({ props: { payment_ui_context: ctx } });
            if (path === adminPath + '/review-context?operation=verify') return response({ command_context: command, expires_in: 300 });
            if (path === adminPath + '/verify') return response(errorEnvelope('proof_use_unavailable'), 503);
            if (path === recoveryPath) return response({ recovery_context: command + '-fresh', expires_in: 300 });
            if (path === reconcilePath) return response(replay);
            assert.fail('Unexpected request: ' + path);
        },
    });
    const { flow, values, states } = fixture(api, 'admin', uiPath);
    const verify = { operation: 'verify', confirm: true, bank_receipt_checked: true };
    await flow.initialize();
    await flow.execute(verify);
    assert.deepEqual(flow.snapshot(), { name: 'unknown', fact: null, error: { code: 'proof_use_unavailable', status: 503, correlation } });
    assert.deepEqual([...values.values()], ['1']);
    assert.ok(!JSON.stringify([...values, ...states]).includes(command));
    const attempt = [...requests];
    assert.equal(attempt.filter(request => request.path.includes('/review-context?')).length, 1);
    assert.deepEqual(attempt.filter(request => request.method === 'POST'), [{
        method: 'POST', path: adminPath + '/verify',
        body: JSON.stringify({ command_context: command, confirm: true, bank_receipt_checked: true }),
    }]);

    await flow.execute(verify);
    await flow.initialize();
    assert.deepEqual(requests, attempt);

    await flow.reconcile();
    assert.deepEqual(requests.slice(attempt.length), [
        { method: 'GET', path: uiPath, body: undefined },
        { method: 'GET', path: recoveryPath, body: undefined },
        { method: 'GET', path: uiPath, body: undefined },
        { method: 'POST', path: reconcilePath, body: JSON.stringify({ command_context: command, recovery_context: command + '-fresh' }) },
        { method: 'GET', path: uiPath, body: undefined },
    ]);
    assert.deepEqual(flow.snapshot(), { name: 'resolved', error: null, fact: replay });
    assert.equal(values.size, 0);
});
test('recovery obtains fresh binding but sends only original context and never calls submission', async () => {
    const calls = []; const api = createPaymentApi({ doc: { cookie: 'XSRF-TOKEN=fixture' }, fetcher: async (path, options) => { calls.push({ path, options }); return path.endsWith('recovery-context') ? response({ recovery_context: command + '-fresh', expires_in: 300 }) : response({ ...result, replayed: true }); } });
    await api.reconcile('submit', id, null, command);
    assert.equal(calls.length, 2); assert.equal(calls[1].path, '/billing/payment-commands/reconcile');
    assert.deepEqual(JSON.parse(calls[1].options.body), { command_context: command, recovery_context: command + '-fresh' });
    assert.ok(!calls.some(v => /\/payments$/.test(v.path)));
});
test('unmount/access loss between recovery GET and POST cancels reconcile delivery', async () => {
    let count = 0;
    const api = createPaymentApi({ fetcher: async () => { count++; return response({ recovery_context: command, expires_in: 300 }); } });
    await assert.rejects(api.reconcile('submit', id, null, command, () => false), e => e.code === 'read_aborted'); assert.equal(count, 1);
});
for (const field of ['session_context','audience_context','actor_id','role','hold_key']) test('fresh server mismatch blocks mutation before context allocation: ' + field, async () => {
    const f = fixture({ uiContext: async () => ({ ...context, [field]: field === 'actor_id' ? payment : field === 'role' ? 'admin' : 'd'.repeat(64) }) });
    await f.flow.initialize(); await f.flow.execute({ file, confirm: true }); assert.equal(f.flow.snapshot().name, 'session_lost'); assert.deepEqual(f.calls, []);
});
test('synchronous latch covers preparing/context fetch and double click', async () => {
    let finish; let count = 0;
    const f = fixture({ mutate: () => { count++; return new Promise(resolve => { finish = resolve; }); } });
    await f.flow.initialize(); const first = f.flow.execute({ file, confirm: true }); const second = f.flow.execute({ file, confirm: true });
    await second; for (let i=0; i<10 && !finish; i++) await Promise.resolve();
    assert.equal(count, 1); assert.equal(f.flow.snapshot().name, 'submitting'); assert.deepEqual([...f.values.values()], ['1']);
    finish(result); await first; assert.equal(f.flow.snapshot().name, 'committed'); assert.equal(f.values.size, 0);
});
for (const failure of [new PaymentApiError('network_error'), new PaymentApiError('unknown_commit_outcome',503), new PaymentApiError('request_failed',500), new PaymentApiError('malformed_response'), new PaymentApiError('context_expired',409), new PaymentApiError('stale_payment_state',409)]) test('unknown/conflict preserves memory-only intent without retries: ' + failure.code, async () => {
    let mutations = 0; const f = fixture({ mutate: async () => { mutations++; throw failure; } });
    await f.flow.initialize(); await f.flow.execute({ file, confirm: true }); assert.ok(['unknown','conflict'].includes(f.flow.snapshot().name));
    await f.flow.execute({ file, confirm: true }); assert.equal(mutations, 1); assert.deepEqual([...f.values.values()], ['1']);
    assert.ok(!JSON.stringify([...f.values]).includes(command)); assert.ok(!JSON.stringify(f.states).includes(command));
    await f.flow.reconcile(); assert.equal(f.flow.snapshot().name, 'resolved'); assert.equal(f.values.size, 0); assert.equal(f.calls.at(-1)[2], command);
});
test('reconcile absent stays unresolved, no new context or file upload', async () => {
    let submitted = 0; const f = fixture({ mutate: async () => { submitted++; throw new PaymentApiError('network_error'); }, reconcile: async () => { throw new PaymentApiError('outcome_unresolved',409); } });
    await f.flow.initialize(); await f.flow.execute({ file, confirm: true }); await f.flow.reconcile(); assert.equal(f.flow.snapshot().name, 'unresolved');
    await f.flow.execute({ file, confirm: true }); assert.equal(submitted,1); assert.equal(f.calls.filter(v => v === 'context').length,1);
});
test('family replacement stays blocked during UNKNOWN even if another file is chosen', async () => {
    const f = fixture({ mutate: async (op, invoiceId, paymentId, token, values) => {
        f.calls.push([op, invoiceId, paymentId, token, values.file]);
        throw new PaymentApiError('unknown_commit_outcome', 503);
    } });
    await f.flow.initialize();
    await f.flow.execute({ file, confirm: true });
    await f.flow.execute({ file: { ...file, name: 'replacement.png' }, confirm: true });
    assert.deepEqual(f.calls, ['context', ['submit', id, null, command, file]]);
    assert.deepEqual([...f.values.values()], ['1']);
    assert.equal(f.flow.snapshot().name, 'unknown');
    assert.equal(f.flow.snapshot().fact, null);
});
test('reload without ephemeral context reads only boolean hold and never guesses a command', async () => {
    const f = fixture({ mutate: async () => { throw new PaymentApiError('network_error'); } }); await f.flow.initialize(); await f.flow.execute({ file, confirm: true }); f.flow.dispose();
    const g = fixture(); for (const [k,v] of f.values) g.values.set(k,v); await g.flow.initialize();
    assert.equal(g.flow.snapshot().name, 'manual_hold'); await g.flow.reconcile(); await g.flow.execute({ file, confirm:true }); assert.deepEqual(g.calls, []);
});
test('storage failure blocks first financial POST', async () => {
    const f = fixture(); f.storage.setItem = () => { throw new Error('quota'); }; await f.flow.initialize(); await f.flow.execute({ file, confirm:true }); assert.equal(f.flow.snapshot().name, 'unavailable'); assert.deepEqual(f.calls, ['context']);
});
test('definitive validation rejection permits manual correction, without automatic retry', async () => {
    let count = 0; const f = fixture({ mutate: async () => { count++; throw new PaymentApiError('invalid_proof',422); } });
    await f.flow.initialize(); await f.flow.execute({ file, confirm:true }); assert.equal(f.flow.snapshot().name,'rejected'); assert.equal(count,1); assert.equal(f.values.size,0);
});
test('logout during in-flight mutation clears original context and cannot publish a late fact', async () => {
    let finish; const f = fixture({ mutate: () => new Promise(resolve => { finish = resolve; }) }); await f.flow.initialize(); const post = f.flow.execute({ file, confirm:true });
    for (let i=0; i<10 && !finish; i++) await Promise.resolve(); f.flow.loseAccess(); finish(result); await post;
    assert.equal(f.flow.snapshot().name,'session_lost'); await f.flow.reconcile(); assert.equal(f.values.size,1); assert.equal(f.calls.filter(v => Array.isArray(v) && v[0] === 'reconcile').length,0);
});
test('Admin bank confirmation is never inferred; private note never enters workflow snapshots/storage', async () => {
    let payload; const f = fixture({ mutate: async (...args) => { payload = args.at(-1); return result; } }, 'admin'); await f.flow.initialize();
    await f.flow.execute({ operation:'verify', confirm:true, bank_receipt_checked:false }); assert.equal(f.flow.snapshot().name,'rejected'); assert.equal(payload,undefined);
    await f.flow.execute({ operation:'reject', confirm:true, reason_code:'not_received',public_reason:'Synthetic reason',private_note:'SYNTHETIC-PRIVATE-NOTE' });
    assert.equal(payload.private_note,'SYNTHETIC-PRIVATE-NOTE'); assert.ok(!JSON.stringify(f.states).includes('SYNTHETIC-PRIVATE-NOTE')); assert.ok(!JSON.stringify([...f.values]).includes('SYNTHETIC-PRIVATE-NOTE'));
});
test('Admin verify and reject retain exact review context and payload without proof or scanner preflight', async () => {
    const calls = [];
    const adminPath = '/admin/billing/invoices/' + id + '/payments/' + payment;
    const api = createPaymentApi({ doc: { cookie: 'XSRF-TOKEN=fixture' }, fetcher: async (path, options) => {
        calls.push({ path, method: options.method, body: options.body, csrf: options.headers['X-XSRF-TOKEN'] });
        if (path.includes('/review-context?')) return response({ command_context: command, expires_in: 300 });
        const verify = path.endsWith('/verify');
        return response({ ...result, replayed: verify, command_fact: verify ? 'verification_recorded' : 'rejection_recorded' });
    } });
    const verifyContext = await api.commandContext('verify', id, payment);
    const verified = await api.mutate('verify', id, payment, verifyContext, { bank_receipt_checked: true });
    const rejectContext = await api.commandContext('reject', id, payment);
    const rejected = await api.mutate('reject', id, payment, rejectContext, {
        reason_code: 'receipt_not_confirmed', public_reason: 'Bukti dummy kurang jelas.', private_note: 'Catatan sintetis.',
    });
    assert.deepEqual(calls, [
        { path: adminPath + '/review-context?operation=verify', method: 'GET', body: undefined, csrf: undefined },
        { path: adminPath + '/verify', method: 'POST', body: JSON.stringify({ command_context: command, confirm: true, bank_receipt_checked: true }), csrf: 'fixture' },
        { path: adminPath + '/review-context?operation=reject', method: 'GET', body: undefined, csrf: undefined },
        { path: adminPath + '/reject', method: 'POST', body: JSON.stringify({ command_context: command, confirm: true, reason_code: 'receipt_not_confirmed', public_reason: 'Bukti dummy kurang jelas.', private_note: 'Catatan sintetis.' }), csrf: 'fixture' },
    ]);
    assert.deepEqual(verified, { ...result, replayed: true, command_fact: 'verification_recorded' });
    assert.deepEqual(rejected, { ...result, command_fact: 'rejection_recorded' });
});
for (const invalid of [null,{...file,size:0},{...file,size:2097153},{...file,name:'proof.php'},{...file,type:'text/plain'}]) test('file UX rejects invalid file without an upload: ' + String(invalid?.name), () => assert.ok(validateProofFile(invalid)));
test('exact file limit is allowed for server validation and amounts remain exact strings', () => {
    assert.equal(validateProofFile({...file,size:2097152}),null);
    const value = {id,elderly_id:payment,number:'F1',billing_month:'2026-10-01',amount:'999999999999.99',status:'unpaid',due_date:'2026-11-23',grace_period_ends_on:'2026-11-26'};
    assert.equal(invoice(value).amount,value.amount); assert.throws(() => invoice({...value,amount:1}));
});
test('dedicated payment session channel does not purge normal family navigation', () => {
    const handlers = {}, emitted = []; let broadcasts = 0;
    class Channel { constructor() {} postMessage() { broadcasts++; } close() {} }
    const cleanup = installPaymentSessionBoundary({ router:{on:(name,handler) => {handlers[name]=handler; return () => {}; }}, target:{Event:class {constructor(type){this.type=type;}},BroadcastChannel:Channel,location:{origin:'http://localhost'},dispatchEvent:e => emitted.push(e.type)}, initialAuth:{user:{id,role:'keluarga'}} });
    handlers.navigate({detail:{page:{props:{auth:{user:{id,role:'keluarga'}}}}}}); assert.equal(emitted.length,0);
    handlers.before({detail:{visit:{method:'post',url:'http://localhost/logout'}}}); assert.equal(emitted.length,1); assert.equal(broadcasts,1); cleanup();
});

const submitValues = { file, confirm: true };
const heldKey = paymentHoldKey(context.hold_key);
function defer() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

test('pre-dispatch validation and context expiry leave no marker or UNKNOWN', async () => {
    let reads = 0;
    const f = fixture({ commandContext: async () => {
        reads++;
        throw new PaymentApiError('context_expired', 409);
    } });
    await f.flow.initialize();
    await f.flow.execute({ ...submitValues, confirm: false });
    assert.equal(f.flow.snapshot().name, 'rejected');
    assert.equal(reads, 0);
    await f.flow.execute(submitValues);
    assert.equal(f.flow.snapshot().name, 'unavailable');
    assert.equal(f.flow.snapshot().error.code, 'context_expired');
    assert.equal(reads, 1);
    assert.deepEqual(f.calls, []);
    assert.equal(f.values.size, 0);
});

test('opaque command expiry reported after POST is a held conflict without automatic replacement', async () => {
    let writes = 0;
    const f = fixture({ mutate: async () => { writes++; throw new PaymentApiError('context_expired', 409); } });
    await f.flow.initialize(); await f.flow.execute(submitValues);
    assert.equal(f.flow.snapshot().name, 'conflict');
    assert.equal(f.values.get(heldKey), '1');
    await f.flow.initialize(); await f.flow.execute(submitValues);
    assert.equal(writes, 1);
    assert.deepEqual(f.calls, ['context']);
});

test('a definitive validation code on an unexpected HTTP 503 cannot clear hold', async () => {
    const f = fixture({ mutate: async () => { throw new PaymentApiError('invalid_proof', 503); } });
    await f.flow.initialize(); await f.flow.execute(submitValues);
    assert.equal(f.flow.snapshot().name, 'unknown');
    assert.equal(f.values.get(heldKey), '1');
});

test('legacy session marker is preserved and promoted to shared storage without recovering an intent', async () => {
    const legacy = new Map([[heldKey, '1']]);
    const values = new Map();
    const a = fixture({}, 'keluarga', '/synthetic', { values, legacyStorage: memoryStorage(legacy) });
    await a.flow.initialize();
    const b = fixture({}, 'keluarga', '/synthetic', { values });
    await b.flow.initialize();
    for (const tab of [a, b]) {
        assert.equal(tab.flow.snapshot().name, 'manual_hold');
        await tab.flow.reconcile(); await tab.flow.execute(submitValues);
        assert.deepEqual(tab.calls, []);
    }
    assert.deepEqual([...legacy], [[heldKey, '1']]);
    assert.deepEqual([...values], [[heldKey, '1']]);
});

test('missing Web Locks fails closed before allocation or financial dispatch', async () => {
    const f = fixture({}, 'keluarga', '/synthetic', { locks: null });
    await f.flow.initialize();
    assert.equal(f.flow.snapshot().error.code, 'coordination_unavailable');
    await f.flow.execute(submitValues);
    assert.deepEqual(f.calls, []);
    assert.equal(f.values.size, 0);
});

test('simultaneous attempts with initially empty storage acquire only one non-queueing writer', async () => {
    const values = new Map(), locks = lockFixture(), contextReady = defer(), writerEntered = defer();
    let dispatches = 0;
    const a = fixture({
        commandContext: async () => { writerEntered.resolve(); return contextReady.promise; },
        mutate: async () => { dispatches++; throw new PaymentApiError('network_error'); },
    }, 'keluarga', '/synthetic', { values, locks });
    const b = fixture({}, 'keluarga', '/synthetic', { values, locks });
    await Promise.all([a.flow.initialize(), b.flow.initialize()]);
    const first = a.flow.execute(submitValues);
    const second = b.flow.execute(submitValues);
    await writerEntered.promise;
    await second;
    assert.equal(values.size, 0);
    assert.equal(b.flow.snapshot().error.code, 'coordination_busy');
    assert.deepEqual(b.calls, []);
    contextReady.resolve(command);
    await first;
    assert.equal(dispatches, 1);
    assert.equal(a.flow.snapshot().name, 'unknown');
    assert.equal(values.get(heldKey), '1');
    await b.flow.initialize(); await b.flow.reconcile(); await b.flow.execute(submitValues);
    assert.equal(b.flow.snapshot().name, 'manual_hold');
    assert.deepEqual(b.calls, []);
});

test('stale-IDLE tab sees shared marker even before storage event and cannot allocate context', async () => {
    const values = new Map(), locks = lockFixture();
    const a = fixture({ mutate: async () => { throw new PaymentApiError('network_error'); } }, 'keluarga', '/synthetic', { values, locks });
    const b = fixture({}, 'keluarga', '/synthetic', { values, locks });
    await Promise.all([a.flow.initialize(), b.flow.initialize()]);
    await a.flow.execute(submitValues);
    assert.equal(b.flow.snapshot().name, 'idle');
    await b.flow.execute(submitValues);
    assert.equal(b.flow.snapshot().name, 'manual_hold');
    assert.deepEqual(b.calls, []);
});

test('storage event updates only the matching same-origin hold and never dispatches', async () => {
    const f = fixture();
    const target = new EventTarget();
    const stop = watchPaymentHold(target, f.storage, context.hold_key, f.flow.observeHold);
    const fire = (key, storageArea) => target.dispatchEvent(Object.assign(new Event('storage'), { key, storageArea }));
    await f.flow.initialize();
    f.values.set(heldKey, '1');
    fire('different-key', f.storage);
    fire(heldKey, {});
    assert.equal(f.flow.snapshot().name, 'idle');
    fire(heldKey, f.storage);
    assert.equal(f.flow.snapshot().name, 'manual_hold');
    await f.flow.reconcile(); await f.flow.execute(submitValues);
    assert.deepEqual(f.calls, []);
    f.values.delete(heldKey); fire(heldKey, f.storage);
    assert.equal(f.flow.snapshot().name, 'manual_hold');
    stop();
});

test('pagehide in flight retains shared hold across tab close and does not publish late result', async () => {
    const values = new Map(), locks = lockFixture(), reply = defer(), sent = defer();
    const a = fixture({ mutate: () => { sent.resolve(); return reply.promise; } }, 'keluarga', '/synthetic', { values, locks });
    await a.flow.initialize();
    const post = a.flow.execute(submitValues);
    await sent.promise;
    a.flow.suspend();
    assert.equal(a.flow.snapshot().name, 'manual_hold');
    reply.resolve(result);
    await post;
    assert.equal(values.get(heldKey), '1');
    assert.equal(a.flow.snapshot().fact, null);
    a.flow.dispose();
    const newTab = fixture({}, 'keluarga', '/synthetic', { values, locks, context: { session_context: 'd'.repeat(64) } });
    await newTab.flow.initialize(); await newTab.flow.reconcile(); await newTab.flow.execute(submitValues);
    assert.equal(newTab.flow.snapshot().name, 'manual_hold');
    assert.deepEqual(newTab.calls, []);
});

test('idle pagehide and reload do not create a false hold', async () => {
    const f = fixture();
    await f.flow.initialize(); f.flow.suspend();
    assert.equal(f.flow.snapshot().name, 'session_lost');
    assert.equal(f.values.size, 0);
    const reboot = fixture({}, 'keluarga', '/synthetic', { values: f.values });
    await reboot.flow.initialize();
    assert.equal(reboot.flow.snapshot().name, 'idle');
    assert.deepEqual(reboot.calls, []);
});

test('scope change before POST suppresses stale command context and leaves no marker', async () => {
    const lookup = defer(), loading = defer();
    const f = fixture({ commandContext: () => { loading.resolve(); return lookup.promise; } });
    await f.flow.initialize();
    const execution = f.flow.execute(submitValues);
    await loading.promise;
    f.flow.loseAccess();
    lookup.resolve(command);
    await execution;
    assert.equal(f.flow.snapshot().name, 'session_lost');
    assert.deepEqual(f.calls, []);
    assert.equal(f.values.size, 0);
});

test('a late response from a disposed scope cannot replace a newer scope fact out of order', async () => {
    const values = new Map(), locks = lockFixture(), oldReply = defer(), entered = defer();
    const old = fixture({ mutate: () => { entered.resolve(); return oldReply.promise; } }, 'keluarga', '/old', { values, locks });
    const newerFact = { ...result, payment_id: id };
    const newer = fixture({ mutate: async () => newerFact }, 'keluarga', '/new', { values, locks, context: { hold_key: 'e'.repeat(64) } });
    await old.flow.initialize();
    const oldPost = old.flow.execute(submitValues);
    await entered.promise;
    old.flow.dispose();
    const previousStateCount = old.states.length;
    await newer.flow.initialize(); await newer.flow.execute(submitValues);
    assert.deepEqual(newer.flow.snapshot().fact, newerFact);
    oldReply.resolve(result);
    await oldPost;
    assert.deepEqual(newer.flow.snapshot().fact, newerFact);
    assert.equal(old.states.length, previousStateCount);
    assert.deepEqual([...values], [[heldKey, '1']]);
});

for (const field of ['session_context', 'audience_context', 'actor_id', 'role']) {
    test('late canonical success cannot publish after silent change of ' + field, async () => {
        let changed = false;
        const replacement = field === 'actor_id' ? payment : field === 'role' ? 'admin' : 'd'.repeat(64);
        const f = fixture({
            mutate: async () => { changed = true; return result; },
            uiContext: async () => changed ? { ...context, [field]: replacement } : context,
        });
        await f.flow.initialize(); await f.flow.execute(submitValues);
        assert.equal(f.flow.snapshot().name, 'session_lost');
        assert.equal(f.flow.snapshot().fact, null);
        assert.equal(f.values.get(heldKey), '1');
        await f.flow.reconcile();
        assert.deepEqual(f.calls, ['context']);
    });
}

test('expired recovery leaves intent held; only another explicit click gets a fresh recovery context', async () => {
    const requests = [];
    const originalPath = '/keluarga/billing/invoices/' + id + '/payments';
    const recoveryPath = '/billing/payment-commands/recovery-context';
    const reconcilePath = '/billing/payment-commands/reconcile';
    const api = createPaymentApi({ doc: { cookie: 'XSRF-TOKEN=test' }, formData: () => ({ append() {} }), fetcher: async (path, options) => {
        requests.push({ path, method: options.method, body: typeof options.body === 'string' ? JSON.parse(options.body) : null });
        if (path === '/synthetic') return response({ props: { payment_ui_context: context } });
        if (path.endsWith('payment-context')) return response({ command_context: command, expires_in: 300 });
        if (path === originalPath) return response(errorEnvelope('unknown_commit_outcome'), 503);
        if (path === recoveryPath) return response({ recovery_context: 'SYNTHETIC-RECOVERY-' + requests.filter(r => r.path === recoveryPath).length, expires_in: 300 });
        if (path === reconcilePath && requests.filter(r => r.path === reconcilePath).length === 1) return response(errorEnvelope('context_expired'), 409);
        return response({ ...result, replayed: true });
    } });
    const f = fixture(api);
    await f.flow.initialize(); await f.flow.execute(submitValues); await f.flow.reconcile();
    assert.equal(f.flow.snapshot().error.code, 'context_expired');
    assert.equal(f.values.get(heldKey), '1');
    const initialCount = requests.length;
    await f.flow.execute(submitValues); await f.flow.initialize();
    assert.equal(requests.length, initialCount);
    await f.flow.reconcile();
    assert.deepEqual(requests.filter(r => r.method === 'POST').map(r => [r.path, r.body]), [
        [originalPath, null],
        [reconcilePath, { command_context: command, recovery_context: 'SYNTHETIC-RECOVERY-1' }],
        [reconcilePath, { command_context: command, recovery_context: 'SYNTHETIC-RECOVERY-2' }],
    ]);
    assert.deepEqual(f.flow.snapshot().fact, { ...result, replayed: true });
    assert.equal(f.values.size, 0);
});

test('binding is revalidated after recovery context GET before any reconcile POST', async () => {
    let rotated = false, reconciles = 0;
    const f = fixture({
        mutate: async () => { throw new PaymentApiError('network_error'); },
        uiContext: async () => rotated ? { ...context, session_context: 'd'.repeat(64) } : context,
        reconcile: async (op, inv, pay, token, beforeSend) => {
            assert.equal(token, command);
            rotated = true;
            await beforeSend();
            reconciles++;
        },
    });
    await f.flow.initialize(); await f.flow.execute(submitValues); await f.flow.reconcile();
    assert.equal(reconciles, 0);
    assert.equal(f.flow.snapshot().name, 'session_lost');
    assert.equal(f.values.get(heldKey), '1');
});

test('role change for the same actor and persisted pageshow both invalidate the old view', () => {
    const handlers = {}, receiver = new EventTarget(), emitted = [];
    receiver.Event = Event;
    receiver.addEventListener('silansia:payment-access-lost', () => emitted.push('lost'));
    const stop = installPaymentSessionBoundary({ router: { on: (name, handler) => {
        handlers[name] = handler; return () => {};
    } }, target: receiver, initialAuth: { user: { id, role: 'keluarga' } } });
    handlers.navigate({ detail: { page: { props: { auth: { user: { id, role: 'admin' } } } } } });
    receiver.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: false }));
    receiver.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
    assert.deepEqual(emitted, ['lost', 'lost']);
    stop();
});
