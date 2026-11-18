import test from 'node:test';
import assert from 'node:assert/strict';
import { createPaymentApi, PaymentApiError, validateProofFile, invoice } from '../../resources/js/utils/payments/api.js';
import { createPaymentWorkflow, sameUiContext } from '../../resources/js/utils/payments/workflow.js';
import { installPaymentSessionBoundary } from '../../resources/js/utils/payments/sessionBoundary.js';

const id = '12345678-1234-4234-8234-123456789abc', payment = '12345678-1234-4234-8234-123456789abd';
const context = { version: 1, actor_id: id, role: 'keluarga', session_context: 'a'.repeat(64), audience_context: 'b'.repeat(64), hold_key: 'c'.repeat(64) };
const command = 'SYNTHETIC-CONTEXT-MEMORY-ONLY';
const file = { name: 'proof.png', size: 100, type: 'image/png' };
const result = { command_fact: 'submission_recorded', invoice_id: id, revision_id: id, payment_id: payment, amount: '3100.00', recorded_at: '2026-11-16T00:00:00.000000Z', replayed: false };
const response = (value, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });
function fixture(overrides = {}, role = 'keluarga') {
    const values = new Map(), calls = [], states = [];
    const ctx = { ...context, role };
    const storage = { getItem: k => values.get(k) ?? null, setItem: (k,v) => values.set(k,v), removeItem: k => values.delete(k) };
    const api = { uiContext: async () => ctx, commandContext: async () => { calls.push('context'); return command; }, mutate: async (op, i, p, token) => { calls.push(['mutation', op, token]); return result; }, reconcile: async (op, i, p, token, ready) => { assert.equal(await ready(), true); calls.push(['reconcile', op, token]); return { ...result, replayed: true }; }, ...overrides };
    const flow = createPaymentWorkflow({ api, target: { operation: role === 'keluarga' ? 'submit' : null, invoiceId: id, paymentId: role === 'admin' ? payment : null, uiPath: '/synthetic' }, context: ctx, storage, onChange: v => states.push(v) });
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
