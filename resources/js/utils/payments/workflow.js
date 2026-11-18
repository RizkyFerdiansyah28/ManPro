import { PaymentApiError, validateProofFile } from './api.js';
import { uuid, object, insist } from '../billing/contracts.js';

export function uiContext(v) {
    insist(object(v) && v.version === 1 && uuid(v.actor_id) && ['admin','keluarga'].includes(v.role)
        && ['session_context','audience_context','hold_key'].every(k => typeof v[k] === 'string' && /^[a-f0-9]{64}$/.test(v[k])));
    return { version: 1, actor_id: v.actor_id, role: v.role, session_context: v.session_context, audience_context: v.audience_context, hold_key: v.hold_key };
}
export const sameUiContext = (a, b) => ['version','actor_id','role','session_context','audience_context','hold_key'].every(k => a?.[k] === b?.[k]);
const definitive = new Set(['invalid_input','invalid_proof','invalid_rejection','invalid_command','bank_confirmation_required','request_too_large','unsupported_media','json_required']);
const lost = e => [401,403,404,419].includes(e.status) || ['context_invalid','session_expired','authentication_required','unauthorized'].includes(e.code);

// Only a boolean hold is persisted. Context/UUID/ref/state, notes and proof never leave memory.
export function createPaymentWorkflow({ api, target, context, storage, onChange = () => {} }) {
    let bound = uiContext(context), original = null, alive = true, authorized = true, operation = target.operation, state = { name: 'checking', error: null, fact: null };
    const reads = new AbortController();
    const key = 'silansia.payment.hold.v1:' + bound.hold_key;
    const publish = (name, fields = {}) => { if (alive) { state = { name, error: null, fact: null, ...fields }; onChange(state); } };
    const hold = () => { storage.setItem(key, '1'); if (storage.getItem(key) !== '1') throw new PaymentApiError('storage_unavailable'); };
    const release = () => { try { storage.removeItem(key); } catch { /* Keep a conservative hold after a known COMMIT. */ } };
    const safeError = e => ({ code: e instanceof PaymentApiError ? e.code : 'request_failed', status: e.status ?? 0, correlation: e.correlation ?? null });
    async function fresh() {
        if (!alive || !authorized) return false;
        const current = uiContext(await api.uiContext(target.uiPath, reads.signal));
        if (!sameUiContext(bound, current)) throw new PaymentApiError('context_invalid', 419);
        return alive && authorized;
    }
    function accessLost(error) { original = null; authorized = false; reads.abort(); publish('session_lost', { error: safeError(error) }); }
    async function initialize() {
        if (!authorized || original || ['submitting','preparing','reconciling'].includes(state.name)) return;
        publish('checking');
        try {
            if (!await fresh()) return;
            publish(storage.getItem(key) === null ? 'idle' : 'manual_hold');
        } catch (error) { if (!alive || !authorized) return; if (lost(error)) accessLost(error); else publish('unavailable', { error: safeError(error) }); }
    }
    async function execute(values) {
        if (!alive || !authorized || !['idle','rejected'].includes(state.name)) return;
        operation = target.operation ?? values.operation;
        if (!['submit','verify','reject'].includes(operation) || (bound.role === 'keluarga' ? operation !== 'submit' : operation === 'submit')) { publish('rejected', { error: { code: 'forbidden' } }); return; }
        if (values.confirm !== true || (operation === 'verify' && values.bank_receipt_checked !== true)) { publish('rejected', { error: { code: 'confirmation_required' } }); return; }
        if (operation === 'submit' && validateProofFile(values.file)) { publish('rejected', { error: { code: 'invalid_proof' } }); return; }
        publish('preparing'); // Synchronous latch, before any await or React render.
        let sent = false;
        try {
            if (!await fresh()) return;
            const command = await api.commandContext(operation, target.invoiceId, target.paymentId, reads.signal);
            if (!await fresh()) return;
            hold(); original = command;
            publish('submitting'); sent = true;
            const response = api.mutate(operation, target.invoiceId, target.paymentId, command, values);
            values = null;
            const fact = await response;
            if (!alive || !authorized) return;
            release(); original = null; publish('committed', { fact });
        } catch (error) {
            values = null;
            if (!alive || !authorized) return;
            if (lost(error)) accessLost(error);
            else if (!sent) { original = null; publish('unavailable', { error: safeError(error) }); }
            else if (definitive.has(error.code)) { release(); original = null; publish('rejected', { error: safeError(error) }); }
            else publish(error.status === 409 ? 'conflict' : 'unknown', { error: safeError(error) });
        }
    }
    async function reconcile() {
        if (!alive || !authorized || !original || !['unknown','unresolved','conflict'].includes(state.name)) return;
        publish('reconciling');
        try {
            if (!await fresh()) return;
            const fact = await api.reconcile(operation, target.invoiceId, target.paymentId, original, () => alive && authorized);
            if (!alive || !authorized) return;
            release(); original = null; publish('resolved', { fact });
        } catch (error) {
            if (!alive || !authorized) return;
            if (lost(error)) accessLost(error);
            else publish(error.code === 'outcome_unresolved' ? 'unresolved' : 'unknown', { error: safeError(error) });
        }
    }
    return { initialize, execute, reconcile, snapshot: () => state,
        loseAccess: () => accessLost(new PaymentApiError('session_expired', 419)),
        dispose: () => { alive = false; authorized = false; reads.abort(); original = null; bound = null; onChange = () => {}; } };
}
