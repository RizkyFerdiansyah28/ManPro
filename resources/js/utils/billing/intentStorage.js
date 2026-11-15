import { context, exact, insist, instant, month, sameContext, uuid } from './contracts.js';
import { InvoiceApiError } from './api.js';

export const intentPrefix = 'silansia.invoice.intent.v1:';
const holdKey = intentPrefix + 'hold';
const keyFor = (c) => intentPrefix + [c.actor_id, c.session_context, c.audience_context].join(':');
function record(v) {
    exact(v, ['version', 'context', 'elderly_id', 'billing_month', 'request_id', 'confirmation_token', 'expires_at', 'status']);
    context(v.context);
    insist(v.version === 1 && uuid(v.elderly_id) && month(v.billing_month) && uuid(v.request_id) && instant(v.expires_at)
        && typeof v.confirmation_token === 'string' && /^[!-~]{16,4096}$/.test(v.confirmation_token) && ['prepared', 'attempted', 'pending'].includes(v.status));
    return Object.freeze({ ...v, context: Object.freeze({ ...v.context }) });
}
export function purgeInvoiceAccess(storage) {
    try {
        const keys = [];
        for (let i = 0; i < storage.length; i++) { const k = storage.key(i); if (k?.startsWith(intentPrefix) && k !== holdKey) keys.push(k); }
        if (keys.length || storage.getItem(holdKey)) storage.setItem(holdKey, 'manual');
        keys.forEach((k) => storage.removeItem(k));
    } catch { /* Denied storage stays inaccessible. Caller must fail closed. */ }
}
export function createIntentStorage(storage) {
    function guarded(fn) { try { if (!storage) throw new Error(); return fn(); } catch (e) { if (e instanceof InvoiceApiError) throw e; throw new InvoiceApiError('storage_unavailable'); } }
    return {
        read(c) { return guarded(() => {
            context(c);
            const keys = [];
            for (let i = 0; i < storage.length; i++) { const k = storage.key(i); if (k?.startsWith(intentPrefix) && k !== holdKey) keys.push(k); }
            if (keys.some((k) => k !== keyFor(c)) || storage.getItem(holdKey) === 'manual') { purgeInvoiceAccess(storage); throw new InvoiceApiError('manual_recovery'); }
            const text = storage.getItem(keyFor(c));
            if (text === null) { if (storage.getItem(holdKey)) throw new InvoiceApiError('manual_recovery'); return null; }
            try { insist(text.length <= 8192); const v = record(JSON.parse(text)); insist(sameContext(v.context, c)); return v; }
            catch { purgeInvoiceAccess(storage); storage.setItem(holdKey, 'manual'); throw new InvoiceApiError('manual_recovery'); }
        }); },
        save(v) { return guarded(() => {
            record(v); const text = JSON.stringify(v); insist(text.length <= 8192);
            storage.setItem(holdKey, 'pending'); storage.setItem(keyFor(v.context), text);
            insist(storage.getItem(keyFor(v.context)) === text);
        }); },
        clearKnown(c, requestId) { return guarded(() => { const saved = this.read(c); insist(saved && saved.request_id === requestId); storage.removeItem(keyFor(c)); storage.removeItem(holdKey); }); },
        purge() { purgeInvoiceAccess(storage); },
    };
}
