import { uuid, money, object, insist } from '../billing/contracts.js';

const family = '/keluarga/billing/invoices';
const admin = '/admin/billing';
const commands = '/billing/payment-commands';
const date = (v) => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v);
const text = (v, limit = 255) => typeof v === 'string' && v.length <= limit;
const stamp = (v) => text(v, 48) && Number.isFinite(Date.parse(v.replace(' ', 'T').replace(/\+00$/, '+00:00')));
const token = (v) => typeof v === 'string' && /^[!-~]{16,12000}$/.test(v);
const label = (v) => { insist(object(v) && uuid(v.id) && (v.name_current === null || text(v.name_current))); return { id: v.id, name_current: v.name_current }; };

export class PaymentApiError extends Error {
    constructor(code, status = 0, correlation = null) { super('Permintaan pembayaran belum dapat diproses.'); this.code = code; this.status = status; this.correlation = uuid(correlation) ? correlation : null; }
}
export function invoice(v) {
    insist(object(v) && uuid(v.id) && uuid(v.elderly_id) && text(v.number) && date(v.billing_month) && money(v.amount)
        && ['unpaid', 'paid', 'cancelled'].includes(v.status) && date(v.due_date) && date(v.grace_period_ends_on));
    const result = Object.fromEntries(['id', 'elderly_id', 'number', 'billing_month', 'amount', 'status', 'due_date', 'grace_period_ends_on'].map(k => [k, v[k]]));
    if (v.current_revision_id !== undefined) { insist(uuid(v.current_revision_id)); result.current_revision_id = v.current_revision_id; }
    if (v.resident !== undefined) result.resident = label(v.resident);
    if (v.financial_owner !== undefined) result.financial_owner = label(v.financial_owner);
    if (v.service_period !== undefined) { insist(object(v.service_period) && stamp(v.service_period.started_at) && stamp(v.service_period.ended_at)); result.service_period = { started_at: v.service_period.started_at, ended_at: v.service_period.ended_at }; }
    return result;
}
function attempt(v, detailed = false) {
    insist(object(v) && uuid(v.id) && ['pending', 'verified', 'rejected'].includes(v.status) && stamp(v.submitted_at));
    const result = { id: v.id, status: v.status, submitted_at: v.submitted_at };
    if (v.public_rejection_reason !== undefined) { insist(v.public_rejection_reason === null || text(v.public_rejection_reason, 2000)); result.public_rejection_reason = v.public_rejection_reason; }
    if (detailed) {
        insist(uuid(v.invoice_id) && uuid(v.invoice_revision_id) && money(v.amount) && (v.verified_at === null || stamp(v.verified_at)));
        Object.assign(result, { invoice_id: v.invoice_id, invoice_revision_id: v.invoice_revision_id, amount: v.amount, verified_at: v.verified_at });
    }
    return result;
}
function page(v, parser) { insist(object(v) && Array.isArray(v.data) && v.data.length <= 20 && Number.isInteger(v.page) && v.page > 0 && typeof v.has_more === 'boolean'); return { data: v.data.map(parser), page: v.page, has_more: v.has_more }; }
function fact(v, operation, invoiceId, paymentId) {
    insist(object(v) && v.command_fact === ({ submit: 'submission_recorded', verify: 'verification_recorded', reject: 'rejection_recorded' })[operation]
        && v.invoice_id === invoiceId && uuid(v.revision_id) && uuid(v.payment_id) && (!paymentId || v.payment_id === paymentId)
        && money(v.amount) && stamp(v.recorded_at) && typeof v.replayed === 'boolean');
    return Object.fromEntries(['command_fact', 'invoice_id', 'revision_id', 'payment_id', 'amount', 'recorded_at', 'replayed'].map(k => [k, v[k]]));
}
const codes = new Set(['authentication_required','forbidden','not_found','method_not_allowed','json_required','request_too_large','unsupported_media','session_expired','invalid_input','rate_limited','request_failed',
    'invalid_command','invalid_rejection','invalid_proof','bank_confirmation_required','context_invalid','context_expired','target_not_found','invalid_payment_target','unmanaged_invoice','unauthorized','idempotency_conflict',
    'invoice_not_eligible','pending_payment_exists','stale_payment_state','stale_owner_context','outcome_unresolved','integrity_violation','gate_closed','proof_boundary_unavailable','proof_validation_unavailable',
    'proof_storage_unavailable','proof_integrity_failure','proof_use_unavailable','proof_scan_unavailable','unknown_commit_outcome','infrastructure_failure','lock_timeout','statement_timeout','transient_failure','owned_test_database_required']);
function csrf(doc) {
    const cookie = doc?.cookie?.split(';').map(v => v.trim()).find(v => v.startsWith('XSRF-TOKEN='));
    try { if (cookie) return decodeURIComponent(cookie.slice(11)); } catch { /* Never expose cookie values. */ }
    throw new PaymentApiError('session_expired', 419);
}
export function createPaymentApi({ fetcher = (...args) => fetch(...args), doc = typeof document === 'undefined' ? null : document, timeout = 15000, formData = () => new FormData(), version = () => '' } = {}) {
    async function request(path, { method = 'GET', body, signal, parser, binary = false, inertia = false } = {}) {
        const headers = { Accept: 'application/json' };
        if (inertia) { headers['X-Inertia'] = 'true'; headers['X-Inertia-Version'] = version() ?? ''; }
        if (method === 'POST') { headers['X-XSRF-TOKEN'] = csrf(doc); if (!(body && typeof body.append === 'function')) headers['Content-Type'] = 'application/json'; }
        let timer;
        const elapsed = new Promise((_, reject) => { timer = setTimeout(() => reject(new PaymentApiError('network_error')), timeout); });
        const race = p => Promise.race([p, elapsed]);
        try {
            const response = await race(fetcher(path, { method, headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error', ...(signal ? { signal } : {}),
                ...(body ? { body: typeof body.append === 'function' ? body : JSON.stringify(body) } : {}) }));
            const mime = response.headers.get('content-type')?.split(';')[0];
            if (response.ok && binary) {
                insist(['image/jpeg', 'image/png'].includes(mime));
                const bytes = await race(response.arrayBuffer()); insist(bytes.byteLength > 0 && bytes.byteLength <= 2097152);
                return new Blob([bytes], { type: mime });
            }
            insist(mime === 'application/json');
            const raw = await race(response.text()); insist(raw.length <= 262144);
            const value = JSON.parse(raw);
            if (!response.ok) {
                if (inertia && object(value?.props) && value.props.status === response.status) {
                    throw new PaymentApiError(response.status === 401 ? 'authentication_required' : response.status === 403 ? 'forbidden' : response.status === 404 ? 'not_found' : 'request_failed', response.status, value.props.correlation_id);
                }
                insist(object(value) && object(value.error) && text(value.error.code, 80) && uuid(value.correlation_id));
                throw new PaymentApiError(codes.has(value.error.code) ? value.error.code : 'request_failed', response.status, value.correlation_id);
            }
            return parser(value, response.status);
        } catch (error) {
            if (error instanceof PaymentApiError) throw error;
            throw new PaymentApiError(signal?.aborted ? 'read_aborted' : error?.name === 'TypeError' ? 'network_error' : 'malformed_response');
        } finally { clearTimeout(timer); }
    }
    const target = (id, p) => { insist(uuid(id) && (!p || uuid(p))); return admin + '/invoices/' + id + '/payments/' + p; };
    return {
        list: (role, filters = {}, signal) => request((role === 'admin' ? admin + '/payments' : family) + '?' + new URLSearchParams(filters), { signal, parser: v => page(v, row => {
            if (role !== 'admin') return invoice(row);
            insist(uuid(row.invoice_id) && money(row.amount)); const value = { ...attempt(row), invoice_id: row.invoice_id, amount: row.amount };
            if (row.invoice_number !== undefined) { insist(text(row.invoice_number)); value.invoice_number = row.invoice_number; }
            if (row.resident !== undefined) value.resident = label(row.resident);
            if (row.financial_owner !== undefined) value.financial_owner = label(row.financial_owner);
            if (row.billing_month !== undefined) { insist(date(row.billing_month)); value.billing_month = row.billing_month; }
            return value;
        }) }),
        detail: (role, id, payment, signal) => { insist(uuid(id)); return request(role === 'admin' ? target(id, payment) : family + '/' + id, { signal, parser: v => {
            insist(object(v));
            if (role === 'admin') return { invoice: v.invoice ? invoice(v.invoice) : null, payment: attempt(v.payment, true) };
            insist(Array.isArray(v.payments)); return { invoice: invoice(v.invoice), payments: v.payments.map(p => attempt(p)) };
        } }); },
        commandContext: (operation, id, payment, signal) => { insist(uuid(id)); return request(operation === 'submit' ? family + '/' + id + '/payment-context' : target(id, payment) + '/review-context?operation=' + operation, { signal, parser: v => { insist(object(v) && token(v.command_context) && v.expires_in === 300); return v.command_context; } }); },
        mutate: (operation, id, payment, command, values) => {
            insist(['submit','verify','reject'].includes(operation) && uuid(id) && token(command));
            let body;
            if (operation === 'submit') { body = formData(); body.append('command_context', command); body.append('confirm', '1'); body.append('proof', values.file); }
            else body = { command_context: command, confirm: true, ...(operation === 'verify' ? { bank_receipt_checked: values.bank_receipt_checked === true } : { reason_code: values.reason_code, public_reason: values.public_reason, private_note: values.private_note || null }) };
            return request(operation === 'submit' ? family + '/' + id + '/payments' : target(id, payment) + '/' + operation, { method: 'POST', body, parser: (v, status) => { insist(operation === 'submit' ? [200,201].includes(status) : status === 200); return fact(v, operation, id, payment); } });
        },
        reconcile: async (operation, id, payment, command, beforeSend = () => true) => {
            const recovery = await request(commands + '/recovery-context', { parser: v => { insist(object(v) && token(v.recovery_context) && v.expires_in === 300); return v.recovery_context; } });
            if (!await beforeSend()) throw new PaymentApiError('read_aborted');
            return request(commands + '/reconcile', { method: 'POST', body: { command_context: command, recovery_context: recovery }, parser: v => fact(v, operation, id, payment) });
        },
        proof: (id, payment, signal) => { insist(uuid(id) && uuid(payment)); return request('/billing/invoices/' + id + '/payments/' + payment + '/proof', { signal, binary: true }); },
        uiContext: (path, signal) => request(path, { signal, inertia: true, parser: v => { insist(object(v) && object(v.props) && object(v.props.payment_ui_context)); return v.props.payment_ui_context; } }),
    };
}

export function validateProofFile(file) {
    if (!file || file.size < 1) return 'Pilih satu berkas bukti pembayaran.';
    if (file.size > 2097152) return 'Ukuran bukti maksimal 2 MiB.';
    if (!/\.(png|jpe?g)$/i.test(file.name) || !['image/png','image/jpeg'].includes(file.type)) return 'Gunakan berkas JPEG atau PNG.';
    return null;
}
