export const invoiceBase = '/admin/billing/invoices';
export const uuid = (v) => typeof v === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(v);
export const money = (v) => typeof v === 'string' && /^(?:0|[1-9]\d{0,11})\.\d{2}$/.test(v);
export const object = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
export function insist(condition) { if (!condition) throw new Error('invalid_contract'); }
export function exact(v, keys) { insist(object(v) && Object.keys(v).length === keys.length && keys.every((k) => Object.hasOwn(v, k))); }
export function month(v) { return typeof v === 'string' && /^\d{4}-(0[1-9]|1[0-2])-01$/.test(v) && v.slice(0, 4) !== '0000'; }
export function instant(v) { return typeof v === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(v) && Number.isFinite(Date.parse(v)); }
export function context(v) {
    exact(v, ['version', 'actor_id', 'session_context', 'audience_context']);
    insist(v.version === 1 && uuid(v.actor_id) && ['session_context', 'audience_context'].every((k) => typeof v[k] === 'string' && /^[a-f0-9]{64}$/.test(v[k])));
    return Object.freeze({ ...v });
}
export const sameContext = (a, b) => a && b && ['version', 'actor_id', 'session_context', 'audience_context'].every((k) => a[k] === b[k]);
export const reasons = {
    eligible: 'Layanan memenuhi syarat penerbitan.', no_chargeable_service: 'Tidak ada layanan yang dapat ditagih pada bulan ini.',
    null_monthly_rate: 'Tarif snapshot belum tersedia. Perlu review Admin.', multiple_enrollments: 'Ada lebih dari satu enrollment dalam bulan ini. Perlu review Admin.',
    ownership_cutover_required: 'Ledger penanggung finansial belum tersedia.', mixed_owner_calendar_month: 'Ada pergantian penanggung dalam bulan ini. Perlu review Admin.',
    partial_ownership_coverage: 'Bukti penanggung belum mencakup seluruh periode layanan.', billing_month_not_ended: 'Bulan layanan belum selesai.',
    existing_invoice_root: 'Invoice bulan ini sudah tersedia.', incomplete_snapshot_evidence: 'Bukti snapshot belum lengkap.',
    invalid_enrollment_snapshot: 'Snapshot enrollment perlu diperiksa.', invalid_monthly_rate: 'Tarif snapshot perlu diperiksa.',
    invalid_ownership_chain: 'Rantai penanggung perlu diperiksa.', stale_current_owner: 'Data penanggung berubah.', invalid_ownership_audit: 'Audit penanggung perlu diperiksa.',
    invalid_billing_month: 'Bulan tidak valid.', unsupported_policy: 'Kebijakan belum didukung.', unsupported_integer_runtime: 'Lingkungan perhitungan belum tersedia.',
    integer_overflow: 'Perhitungan belum dapat diproses.', invalid_command_context: 'Konteks tidak valid.', invalid_as_of: 'Waktu penerbitan belum valid.',
    invalid_instant: 'Waktu layanan perlu diperiksa.', invalid_interval: 'Interval layanan perlu diperiksa.',
};
export function preview(v) {
    exact(v, ['kind', 'issued', 'decision', 'reason', 'calculation', 'confirmation']);
    insist(v.kind === 'preview' && v.issued === false && ['ISSUE', 'HOLD', 'SKIP', 'REJECT'].includes(v.decision) && Object.hasOwn(reasons, v.reason));
    if (v.decision !== 'ISSUE') { insist(v.calculation === null && v.confirmation === null); return v; }
    const c = v.calculation;
    exact(c, ['monthly_price', 'amount', 'currency', 'effective_started_at', 'effective_ended_at', 'chargeable_days', 'days_in_month', 'financial_owner_id', 'ownership_period_id']);
    insist(money(c.monthly_price) && money(c.amount) && c.currency === 'IDR' && instant(c.effective_started_at) && instant(c.effective_ended_at)
        && Number.isInteger(c.chargeable_days) && c.chargeable_days > 0 && c.chargeable_days <= c.days_in_month
        && Number.isInteger(c.days_in_month) && c.days_in_month >= 28 && c.days_in_month <= 31 && uuid(c.financial_owner_id) && uuid(c.ownership_period_id));
    exact(v.confirmation, ['request_id', 'token', 'expires_at']);
    insist(uuid(v.confirmation.request_id) && typeof v.confirmation.token === 'string' && /^[!-~]{16,4096}$/.test(v.confirmation.token) && instant(v.confirmation.expires_at));
    return v;
}
export function issued(v, status) {
    exact(v, ['kind', 'issued', 'decision', 'invoice_id', 'revision_id', 'amount', 'issued_at', 'replayed', 'detail_url']);
    insist(v.kind === 'issued' && v.issued === true && v.decision === 'ISSUE' && uuid(v.invoice_id) && uuid(v.revision_id)
        && money(v.amount) && instant(v.issued_at) && v.replayed === (status === 200) && [200, 201].includes(status)
        && v.detail_url === `${invoiceBase}/${v.invoice_id}`);
    return v;
}
export function invoice(v, detail = false) {
    exact(v, ['id', 'number', 'billing_month', 'resident', 'amount', 'currency', 'status', 'due_date', 'grace_period_ends_on', 'record_kind', 'detail_url',
        ...(detail ? ['financial_owner', 'ownership_period_id', 'service_enrollment_id', 'current_revision', 'initial_issued_at', 'initial_issued_by', 'actions', 'legacy_warning'] : [])]);
    exact(v.resident, ['id', 'name_current']);
    insist(object(v) && uuid(v.id) && typeof v.number === 'string' && v.number.length <= 255 && month(v.billing_month)
        && object(v.resident) && uuid(v.resident.id) && (v.resident.name_current === null || typeof v.resident.name_current === 'string')
        && money(v.amount) && v.currency === 'IDR' && ['unpaid', 'paid', 'cancelled'].includes(v.status)
        && ['due_date', 'grace_period_ends_on'].every((k) => typeof v[k] === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v[k]))
        && ['legacy', 'managed_v1'].includes(v.record_kind) && v.detail_url === `${invoiceBase}/${v.id}`);
    if (detail) {
        insist(Array.isArray(v.actions));
        if (v.record_kind === 'legacy') insist(v.financial_owner === null && v.current_revision === null && v.actions.length === 0);
        else {
            exact(v.financial_owner, ['id', 'name_current']);
            insist(object(v.financial_owner) && uuid(v.financial_owner.id) && (v.financial_owner.name_current === null || typeof v.financial_owner.name_current === 'string')
                && uuid(v.ownership_period_id) && uuid(v.service_enrollment_id) && uuid(v.initial_issued_by) && instant(v.initial_issued_at));
            const r = v.current_revision;
            exact(r, ['id', 'revision_no', 'monthly_price', 'amount', 'currency', 'effective_started_at', 'effective_ended_at', 'chargeable_days', 'days_in_month', 'issued_at', 'policy_version']);
            insist(object(r) && uuid(r.id) && Number.isInteger(r.revision_no) && r.revision_no >= 1 && money(r.monthly_price) && r.amount === v.amount && r.currency === 'IDR'
                && instant(r.effective_started_at) && instant(r.effective_ended_at) && instant(r.issued_at) && typeof r.policy_version === 'string'
                && Number.isInteger(r.chargeable_days) && Number.isInteger(r.days_in_month));
            insist(v.actions.every((a) => { exact(a, ['action', 'sequence', 'occurred_at']); return ['invoice_issued', 'zero_charge'].includes(a.action) && Number.isInteger(a.sequence) && instant(a.occurred_at); }));
        }
    }
    return v;
}
export function page(v, item, path) {
    exact(v, ['data', 'total', 'current_page', 'per_page', 'last_page', 'prev_page_url', 'next_page_url']);
    insist(object(v) && Array.isArray(v.data) && v.data.length <= 50 && ['total', 'current_page', 'per_page', 'last_page'].every((k) => Number.isInteger(v[k]) && v[k] >= (k === 'total' ? 0 : 1))
        && [15, 30, 50].includes(v.per_page));
    for (const key of ['prev_page_url', 'next_page_url']) {
        if (v[key] === null) continue;
        insist(typeof v[key] === 'string' && v[key].startsWith(path + '?') && !/[#\r\n]/.test(v[key]));
        const query = new URLSearchParams(v[key].slice(path.length + 1));
        const allowed = path.endsWith('resident-options') ? ['search', 'page', 'per_page'] : ['elderly_id', 'billing_month', 'status', 'page', 'per_page', 'sort'];
        for (const [name, value] of query) {
            insist(allowed.includes(name));
            if (name === 'elderly_id') insist(uuid(value));
            if (name === 'billing_month') insist(month(value));
            if (name === 'page') insist(/^[1-9]\d{0,3}$|^10000$/.test(value));
            if (name === 'per_page') insist(['15', '30', '50'].includes(value));
            if (name === 'status') insist(['unpaid', 'paid', 'cancelled'].includes(value));
            if (name === 'sort') insist(['billing_month_desc', 'billing_month_asc', 'due_date_asc'].includes(value));
            if (name === 'search') insist(value.length <= 100);
        }
    }
    v.data.forEach(item);
    return v;
}
