export const actor = '12345678-1234-4234-8234-123456789abc';
export const resident = '22345678-1234-4234-8234-123456789abc';
export const requestId = '32345678-1234-4234-8234-123456789abc';
export const invoiceId = '42345678-1234-4234-8234-123456789abc';
export const revisionId = '52345678-1234-4234-8234-123456789abc';
export const ctx = { version: 1, actor_id: actor, session_context: 'a'.repeat(64), audience_context: 'b'.repeat(64) };
export const target = { elderly_id: resident, billing_month: '2026-10-01' };
export const token = 'SYNTHETIC-OPAQUE-CONFIRMATION-FIXTURE-ONLY';
export const time = '2026-11-15T01:00:00.000000Z';
export const preview = { kind: 'preview', issued: false, decision: 'ISSUE', reason: 'eligible', calculation: {
    monthly_price: '999999999999.99', amount: '999999999999.99', currency: 'IDR', effective_started_at: '2026-09-30T17:00:00.000000Z', effective_ended_at: '2026-10-31T17:00:00.000000Z', chargeable_days: 31, days_in_month: 31,
    financial_owner_id: actor, ownership_period_id: revisionId,
}, confirmation: { request_id: requestId, token, expires_at: time } };
export const issued = { kind: 'issued', issued: true, decision: 'ISSUE', invoice_id: invoiceId, revision_id: revisionId, amount: preview.calculation.amount, issued_at: time, replayed: false, detail_url: '/admin/billing/invoices/' + invoiceId };
export const listInvoice = { id: invoiceId, number: 'SIL-202610-' + invoiceId, billing_month: target.billing_month, resident: { id: resident, name_current: '<img src=x onerror=alert(1)> Lansia fixture' }, amount: issued.amount, currency: 'IDR', status: 'unpaid', due_date: '2026-11-22', grace_period_ends_on: '2026-11-25', record_kind: 'managed_v1', detail_url: issued.detail_url };
export const detailInvoice = { ...listInvoice, financial_owner: { id: actor, name_current: 'Keluarga fixture' }, ownership_period_id: revisionId, service_enrollment_id: requestId,
    current_revision: { id: revisionId, revision_no: 1, monthly_price: issued.amount, amount: issued.amount, currency: 'IDR', effective_started_at: preview.calculation.effective_started_at, effective_ended_at: preview.calculation.effective_ended_at, chargeable_days: 31, days_in_month: 31, issued_at: time, policy_version: 'synthetic-fixture-v1' },
    initial_issued_at: time, initial_issued_by: actor, actions: [{ action: 'invoice_issued', sequence: 1, occurred_at: time }], legacy_warning: null };
export const legacyInvoice = { ...detailInvoice, record_kind: 'legacy', financial_owner: null, ownership_period_id: null, current_revision: null, initial_issued_at: null, initial_issued_by: null, actions: [], legacy_warning: 'Legacy' };
export const pagination = (data = []) => ({ data, total: data.length, current_page: 1, per_page: 15, last_page: 1, prev_page_url: null, next_page_url: null });
export class MemoryStorage {
    data = new Map();
    get length() { return this.data.size; }
    key(i) { return [...this.data.keys()][i] ?? null; }
    getItem(k) { return this.data.get(k) ?? null; }
    setItem(k, v) { this.data.set(k, String(v)); }
    removeItem(k) { this.data.delete(k); }
}
export const frozen = () => ({ version: 1, context: { ...ctx }, ...target, request_id: requestId, confirmation_token: token, expires_at: time, status: 'prepared' });
