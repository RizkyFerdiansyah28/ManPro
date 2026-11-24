import { PaymentApiError, validateProofFile } from './api.js';
import { uuid, object, insist } from '../billing/contracts.js';
import { paymentHoldKey } from './sessionBoundary.js';

export function uiContext(v) {
    insist(object(v) && v.version === 1 && uuid(v.actor_id) && ['admin','keluarga'].includes(v.role)
        && ['session_context','audience_context','hold_key'].every(k => typeof v[k] === 'string' && /^[a-f0-9]{64}$/.test(v[k])));
    return { version: 1, actor_id: v.actor_id, role: v.role, session_context: v.session_context, audience_context: v.audience_context, hold_key: v.hold_key };
}
export const sameUiContext = (a, b) => ['version','actor_id','role','session_context','audience_context','hold_key'].every(k => a?.[k] === b?.[k]);
const definitive = new Map([
    ['invalid_input', 422], ['invalid_proof', 422], ['invalid_rejection', 422], ['invalid_command', 422],
    ['bank_confirmation_required', 422], ['request_too_large', 413], ['unsupported_media', 415], ['json_required', 406],
]);
const lost = e => [401,403,404,419].includes(e?.status) || ['context_invalid','session_expired','authentication_required','unauthorized'].includes(e?.code);
const recoverable = ['unknown','unresolved','conflict'];

// Shared storage holds only "1" under the opaque server-issued resource key.
export function createPaymentWorkflow({ api, target, context, storage, legacyStorage, locks, onChange = () => {} }) {
    const bound = uiContext(context);
    let original = null, alive = true, authorized = true, checking = false, state = { name: 'checking', error: null, fact: null };
    const reads = new AbortController();
    const key = paymentHoldKey(bound.hold_key);
    const active = () => alive && authorized;
    const publish = (name, fields = {}) => { if (alive) { state = { name, error: null, fact: null, ...fields }; onChange(state); } };
    function hasHold(store) {
        try { return store.getItem(key) !== null; }
        catch { throw new PaymentApiError('storage_unavailable'); }
    }
    const sharedHold = () => hasHold(storage);
    const legacyHold = () => legacyStorage != null && hasHold(legacyStorage);
    function hold() {
        try {
            storage.setItem(key, '1');
            if (storage.getItem(key) === '1') return;
        } catch { /* Keep a marker if the write succeeded but readback failed. */ }
        throw new PaymentApiError('storage_unavailable');
    }
    function release() {
        try { storage.removeItem(key); } catch { /* Leave the marker for manual review. */ }
    }
    const canCoordinate = () => typeof locks?.request === 'function';
    async function exclusive(action) {
        if (!canCoordinate()) throw new PaymentApiError('coordination_unavailable');
        return locks.request(key, { mode: 'exclusive', ifAvailable: true }, action);
    }
    async function inspectHold() {
        if (sharedHold()) return true;
        if (!legacyHold()) return false;
        if (canCoordinate()) {
            await exclusive(lock => {
                if (lock && active() && legacyHold() && !sharedHold()) hold();
            });
        }
        return true;
    }
    const safeError = e => ({ code: e instanceof PaymentApiError ? e.code : 'request_failed', status: e?.status ?? 0, correlation: e?.correlation ?? null });
    async function fresh() {
        if (!active()) return false;
        const current = uiContext(await api.uiContext(target.uiPath, reads.signal));
        if (!sameUiContext(bound, current)) throw new PaymentApiError('context_invalid', 419);
        return active();
    }
    function invalidate() { original = null; authorized = false; reads.abort(); }
    function accessLost(error) {
        if (!active()) return;
        invalidate();
        publish('session_lost', { error: safeError(error) });
    }
    function suspend() {
        if (!active()) return;
        let held = Boolean(original);
        try { held ||= sharedHold() || legacyHold(); } catch { held = true; }
        invalidate();
        publish(held ? 'manual_hold' : 'session_lost');
    }
    async function initialize() {
        if (!active() || checking || original || ['submitting','preparing','reconciling'].includes(state.name)) return;
        checking = true;
        publish('checking');
        try {
            if (!await fresh()) return;
            const held = await inspectHold();
            if (!active()) return;
            if (held || sharedHold()) publish('manual_hold');
            else if (canCoordinate()) publish('idle');
            else publish('unavailable', { error: { code: 'coordination_unavailable' } });
        } catch (error) {
            if (active()) {
                if (lost(error)) accessLost(error);
                else publish('unavailable', { error: safeError(error) });
            }
        } finally { checking = false; }
    }
    function observeHold() {
        if (!active() || original || !['checking','idle','rejected','unavailable'].includes(state.name)) return;
        try {
            if (sharedHold()) publish('manual_hold');
        } catch {
            publish('unavailable', { error: { code: 'storage_unavailable' } });
        }
    }
    async function execute(values) {
        if (!active() || !['idle','rejected'].includes(state.name)) return;
        const operation = target.operation ?? values.operation;
        if (!['submit','verify','reject'].includes(operation) || (bound.role === 'keluarga' ? operation !== 'submit' : operation === 'submit')) { publish('rejected', { error: { code: 'forbidden' } }); return; }
        if (values.confirm !== true || (operation === 'verify' && values.bank_receipt_checked !== true)) { publish('rejected', { error: { code: 'confirmation_required' } }); return; }
        if (operation === 'submit' && validateProofFile(values.file)) { publish('rejected', { error: { code: 'invalid_proof' } }); return; }
        publish('preparing'); // Synchronous latch, before any await or React render.
        // All automatic reads occur inside a non-queueing lock. A busy lock never
        // schedules a deferred financial action when the other tab finishes.
        try {
            await exclusive(async lock => {
                if (!active()) return;
                if (sharedHold() || legacyHold()) { publish('manual_hold'); return; }
                if (!lock) { publish('unavailable', { error: { code: 'coordination_busy' } }); return; }
                const dispatching = dispatch(operation, values);
                values = null;
                await dispatching;
            });
        } catch (error) {
            if (active() && state.name === 'preparing') publish('unavailable', { error: safeError(error) });
        }
    }
    async function dispatch(operation, values) {
        let sent = false, factReceived = false;
        try {
            if (!await fresh()) return;
            const command = await api.commandContext(operation, target.invoiceId, target.paymentId, reads.signal);
            if (!await fresh()) return;
            if (sharedHold() || legacyHold()) { publish('manual_hold'); return; }
            hold();
            original = { operation, command };
            publish('submitting');
            if (!active()) return;
            sent = true;
            const response = api.mutate(operation, target.invoiceId, target.paymentId, command, values);
            values = null;
            const fact = await response;
            factReceived = true;
            if (!await fresh()) return;
            release(); original = null; publish('committed', { fact });
        } catch (error) {
            if (!active()) return;
            if (lost(error)) accessLost(error);
            else if (!sent) publish('unavailable', { error: safeError(error) });
            else if (!factReceived && error instanceof PaymentApiError && definitive.has(error.code) && definitive.get(error.code) === error.status) {
                release(); original = null; publish('rejected', { error: safeError(error) });
            } else publish(error?.status === 409 ? 'conflict' : 'unknown', { error: safeError(error) });
        }
    }
    async function reconcile() {
        if (!active() || !original || !recoverable.includes(state.name)) return;
        const previous = state.name;
        publish('reconciling');
        try {
            await exclusive(async lock => {
                if (!active() || !original) return;
                if (!lock) { publish(previous, { error: { code: 'coordination_busy' } }); return; }
                if (!await fresh()) return;
                hold();
                const { operation, command } = original;
                const fact = await api.reconcile(operation, target.invoiceId, target.paymentId, command, fresh);
                if (!await fresh()) return;
                release(); original = null; publish('resolved', { fact });
            });
        } catch (error) {
            if (active()) {
                if (lost(error)) accessLost(error);
                else publish(error?.code === 'outcome_unresolved' ? 'unresolved' : 'unknown', { error: safeError(error) });
            }
        }
    }
    return { initialize, execute, reconcile, observeHold, suspend, snapshot: () => state,
        loseAccess: () => accessLost(new PaymentApiError('session_expired', 419)),
        dispose: () => { alive = false; invalidate(); onChange = () => {}; } };
}
