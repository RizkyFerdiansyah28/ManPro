import { context, month, preview, sameContext, uuid } from './contracts.js';
import { InvoiceApiError, messages } from './api.js';

export const workflowStates = Object.freeze(['idle', 'previewing', 'eligible', 'confirming', 'submitting', 'issued', 'replayed', 'outcome_unknown', 'reconciling', 'unresolved', 'authorization_lost', 'readiness_unavailable', 'manual_recovery']);
const stateError = (e) => ({ code: e?.code && Object.hasOwn(messages, e.code) ? e.code : 'malformed_response', message: messages[e?.code] ?? messages.malformed_response, correlation: uuid(e?.correlation) ? e.correlation : null });

// No token is exposed through getSnapshot(), React props, events, errors or history.
export class InvoiceWorkflow {
    #api; #store; #actor; #supported; #listeners = new Set(); #intent = null; #bound = null; #epoch = 0; #read = 0; #busy = false; #checking = false; #disposed = false; #attempted = false;
    #state = Object.freeze({ name: 'readiness_unavailable', checking: true, preview: null, result: null, error: null, hasIntent: false, requestId: null, expiresAt: null, target: null });
    constructor({ api, store, actor, supported = true }) { this.#api = api; this.#store = store; this.#actor = actor; this.#supported = supported; }
    subscribe = (fn) => { this.#listeners.add(fn); return () => this.#listeners.delete(fn); };
    getSnapshot = () => this.#state;
    #set(name, fields = {}) {
        if (this.#disposed) return;
        this.#state = Object.freeze({ ...this.#state, name, checking: false, hasIntent: Boolean(this.#intent),
            requestId: this.#intent?.request_id ?? null, expiresAt: this.#intent?.expires_at ?? null,
            target: this.#intent ? { elderly_id: this.#intent.elderly_id, billing_month: this.#intent.billing_month } : null, ...fields });
        this.#listeners.forEach((fn) => fn());
    }
    #failure(error) {
        const code = error?.code;
        if ([401, 403, 419].includes(error?.status) || code === 'context_mismatch') { this.loseAccess(error); return; }
        this.#epoch++;
        this.#intent = null;
        this.#set(['storage_unavailable', 'manual_recovery'].includes(code) ? 'manual_recovery' : 'readiness_unavailable', { error: stateError(error), preview: null });
    }
    loseAccess(error = new InvoiceApiError('authentication_required', 401)) {
        this.#epoch++; this.#read++; this.#intent = null; this.#bound = null; this.#attempted = true;
        this.#store.purge();
        this.#set('authorization_lost', { error: stateError(error), preview: null, result: null });
    }
    async #fresh() {
        const read = ++this.#read;
        const c = context(await this.#api.context());
        if (this.#disposed || read !== this.#read) throw new InvoiceApiError('network_error');
        if (c.actor_id !== this.#actor || (this.#bound && !sameContext(c, this.#bound))) throw new InvoiceApiError('context_mismatch', 403);
        this.#bound = c;
        return c;
    }
    async checkContext() {
        if (this.#disposed) return;
        if (!this.#supported) { this.#failure(new InvoiceApiError('storage_unavailable')); return; }
        if (this.#busy || this.#checking) return; // In-flight commands do their own fresh check before publish.
        this.#checking = true;
        const epoch = this.#epoch;
        this.#set(this.#state.name, { checking: true });
        try {
            const c = await this.#fresh();
            if (epoch !== this.#epoch || this.#disposed) return;
            const saved = this.#store.read(c); // Only after a full fresh authenticated context.
            if (saved) {
                const wasLive = this.#intent && this.#intent.request_id === saved.request_id && !this.#attempted;
                this.#intent = saved;
                if (!wasLive) this.#attempted = true; // Stored status is not trusted as proof of no POST.
                this.#set(wasLive ? this.#state.name : 'unresolved', { error: null });
            } else if (this.#intent) throw new InvoiceApiError('manual_recovery');
            else this.#set(this.#state.result ? this.#state.name : 'idle', { error: null });
        } catch (error) { if (epoch === this.#epoch && !this.#disposed) this.#failure(error); }
        finally { this.#checking = false; }
    }
    async preview(target) {
        if (this.#busy || this.#checking || this.#intent || this.#disposed || this.#state.name !== 'idle') return;
        if (!uuid(target.elderly_id) || !month(target.billing_month)) { this.#set('idle', { error: stateError(new InvoiceApiError('invalid_input', 422)) }); return; }
        this.#busy = true;
        const epoch = this.#epoch;
        this.#set('previewing', { error: null, preview: null });
        try {
            const c = await this.#fresh();
            if (this.#store.read(c)) throw new InvoiceApiError('manual_recovery');
            const result = preview(await this.#api.preview({ ...target }));
            if (epoch !== this.#epoch || this.#disposed) return;
            if (result.decision === 'ISSUE') {
                this.#intent = Object.freeze({ version: 1, context: c, ...target, request_id: result.confirmation.request_id,
                    confirmation_token: result.confirmation.token, expires_at: result.confirmation.expires_at, status: 'prepared' });
                this.#store.save(this.#intent); this.#attempted = false;
            }
            this.#set(result.decision === 'ISSUE' ? 'eligible' : 'idle', { preview: { decision: result.decision, reason: result.reason, calculation: result.calculation }, error: null });
        } catch (error) {
            if (epoch !== this.#epoch || this.#disposed) return;
            if (error?.status === 422 && !this.#intent) this.#set('idle', { error: stateError(error), preview: null });
            else this.#failure(error);
        }
        finally { this.#busy = false; }
    }
    confirm() { if (!this.#busy && !this.#checking && this.#state.name === 'eligible' && this.#intent && !this.#attempted) this.#set('confirming'); }
    cancelConfirmation() { if (!this.#busy && this.#state.name === 'confirming') this.#set('eligible'); }
    discardUnsent() {
        if (this.#busy || this.#attempted || !['eligible', 'confirming'].includes(this.#state.name)) return;
        try { this.#store.clearKnown(this.#bound, this.#intent.request_id); this.#intent = null; this.#set('idle', { preview: null, error: null }); }
        catch (e) { this.#failure(e); }
    }
    async issue() { if (this.#state.name === 'confirming' && !this.#attempted) await this.#send(false); }
    async reconcile() { if (this.#intent && ['unresolved', 'outcome_unknown'].includes(this.#state.name)) await this.#send(true); }
    async #send(reconcile) {
        if (this.#busy || this.#checking || this.#disposed) return;
        this.#busy = true;
        const epoch = this.#epoch;
        this.#set(reconcile ? 'reconciling' : 'submitting', { error: null });
        try {
            const c = await this.#fresh();
            const saved = this.#store.read(c);
            if (!saved || !this.#intent || saved.request_id !== this.#intent.request_id || saved.confirmation_token !== this.#intent.confirmation_token) throw new InvoiceApiError('manual_recovery');
            if (epoch !== this.#epoch || this.#disposed) return;
            const frozen = this.#intent;
            this.#store.save({ ...frozen, status: 'attempted' }); // Must succeed before POST may leave browser.
            this.#attempted = true;
            const command = { elderly_id: frozen.elderly_id, billing_month: frozen.billing_month, request_id: frozen.request_id, confirmation_token: frozen.confirmation_token };
            const result = await (reconcile ? this.#api.reconcile(command) : this.#api.issue(command));
            if (epoch !== this.#epoch || this.#disposed) return;
            await this.#fresh(); // Do not disclose result to a changed/expired session.
            if (epoch !== this.#epoch || this.#disposed) return;
            let cleanupError = null;
            try { this.#store.clearKnown(c, frozen.request_id); } catch (error) { cleanupError = stateError(error); }
            this.#intent = null;
            this.#set(result.replayed ? 'replayed' : 'issued', { result, preview: null, error: cleanupError });
        } catch (error) {
            if (epoch !== this.#epoch || this.#disposed) return;
            if ([401, 403, 419].includes(error?.status) || error?.code === 'context_mismatch') this.loseAccess(error);
            else if (['manual_recovery', 'storage_unavailable'].includes(error?.code)) this.#failure(error);
            else if (this.#attempted) this.#set(error?.code === 'outcome_unresolved' ? 'unresolved' : 'outcome_unknown', { error: stateError(error), preview: null });
            else this.#failure(error);
        } finally { this.#busy = false; }
    }
    dispose() { this.#disposed = true; this.#epoch++; this.#read++; this.#intent = null; this.#listeners.clear(); }
}

export function observeInvoicePage({ workflow, target, doc }) {
    const check = () => { if (!doc || doc.visibilityState !== 'hidden') void workflow.checkContext(); };
    const lost = () => workflow.loseAccess();
    target.addEventListener('focus', check); target.addEventListener('pageshow', check); target.addEventListener('popstate', check);
    target.addEventListener('silansia:billing-access-lost', lost); doc?.addEventListener('visibilitychange', check);
    check();
    return () => { target.removeEventListener('focus', check); target.removeEventListener('pageshow', check); target.removeEventListener('popstate', check); target.removeEventListener('silansia:billing-access-lost', lost); doc?.removeEventListener('visibilitychange', check); workflow.dispose(); };
}
