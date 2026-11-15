import { context, exact, insist, invoice, invoiceBase, issued, object, page, preview, uuid } from './contracts.js';

export const messages = {
    authentication_required: 'Sesi perlu diperbarui. Hasil upaya sebelumnya belum dapat disimpulkan.', forbidden: 'Akses invoice tidak lagi diizinkan.', session_expired: 'Sesi telah berakhir. Jangan mengulang penerbitan.',
    billing_unavailable: 'Layanan billing belum tersedia.', outcome_unknown: 'Hasil penerbitan belum diketahui. Periksa intent yang sama.', outcome_unresolved: 'Request belum terlihat sebagai hasil committed. Ini bukan bukti rollback.',
    confirmation_expired: 'Konfirmasi telah berakhir. Gunakan pemeriksaan hasil intent yang sama.', confirmation_invalid: 'Konfirmasi tidak valid. Perlu pemeriksaan manual.',
    preview_stale: 'Sumber layanan berubah. Jangan mengganti intent yang mungkin sudah dikirim.', invoice_exists: 'Invoice bulan ini sudah ada. Periksa daftar invoice.', request_conflict: 'Request telah digunakan untuk intent berbeda.',
    billing_review_required: 'Data layanan perlu review Admin.', no_chargeable_service: 'Tidak ada layanan yang dapat ditagih.', billing_rejected: 'Penerbitan belum diizinkan.', invalid_input: 'Periksa pilihan lansia dan bulan.',
    rate_limited: 'Terlalu banyak permintaan. Tunggu sebelum memeriksa hasil kembali.', billing_busy: 'Layanan sedang sibuk. Pertahankan intent yang sama.', retry_exhausted: 'Layanan belum dapat mengonfirmasi hasil.',
    billing_integrity_error: 'Integritas data invoice perlu diperiksa Admin.', not_found: 'Data invoice atau lansia tidak ditemukan.', method_not_allowed: 'Metode permintaan tidak tersedia.',
    json_required: 'Format permintaan belum sesuai.', json_body_required: 'Format permintaan belum sesuai.', request_too_large: 'Permintaan terlalu besar.', request_failed: 'Permintaan belum dapat diproses.',
    network_error: 'Koneksi terputus atau melewati batas waktu. Hasil penerbitan belum dapat disimpulkan.', malformed_response: 'Respons server tidak dapat diverifikasi. Jangan mengulang penerbitan.',
    context_mismatch: 'Konteks sesi atau lingkungan berubah. Intent lama tidak boleh dibuka.', storage_unavailable: 'Penyimpanan recovery tidak tersedia. Penerbitan diblokir.', manual_recovery: 'Intent memerlukan pemeriksaan manual. Jangan membuat penerbitan pengganti.',
};
export class InvoiceApiError extends Error {
    constructor(code, status = 0, correlation = null) { super(messages[code] ?? messages.request_failed); this.code = code; this.status = status; this.correlation = uuid(correlation) ? correlation : null; }
}
function csrf(doc) {
    const item = doc?.cookie?.split(';').map((s) => s.trim()).find((s) => s.startsWith('XSRF-TOKEN='));
    if (!item) throw new InvoiceApiError('session_expired', 419);
    try { return decodeURIComponent(item.slice('XSRF-TOKEN='.length)); } catch { throw new InvoiceApiError('session_expired', 419); }
}
export function createInvoiceApi({ fetcher = (...args) => fetch(...args), doc = typeof document === 'undefined' ? null : document, version = () => '' , timeout = 15000 } = {}) {
    async function request(path, { method = 'GET', body, signal, inertia = false, parse }) {
        const headers = { Accept: 'application/json' };
        if (inertia) { headers['X-Inertia'] = 'true'; headers['X-Inertia-Version'] = version() ?? ''; }
        if (body) { headers['Content-Type'] = 'application/json'; headers['X-XSRF-TOKEN'] = csrf(doc); }
        let timer;
        const timedOut = new Promise((_, reject) => { timer = setTimeout(() => reject(new InvoiceApiError('network_error')), timeout); });
        const network = async (promise) => { try { return await Promise.race([promise, timedOut]); } catch (error) { if (error instanceof InvoiceApiError) throw error; throw new InvoiceApiError('network_error'); } };
        try {
            // No AbortController for financial POSTs: a timeout does not cancel COMMIT.
            const response = await network(fetcher(path, { method, headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error', ...(signal ? { signal } : {}), ...(body ? { body: JSON.stringify(body) } : {}) }));
            insist(response.headers.get('content-type')?.includes('application/json'));
            const text = await network(response.text());
            insist(text.length <= 262144);
            const json = JSON.parse(text);
            if (!response.ok) {
                exact(json, ['error', 'financial_outcome', 'correlation_id', ...(Object.hasOwn(json, 'errors') ? ['errors'] : [])]);
                insist(object(json.error) && Object.hasOwn(messages, json.error.code) && ['unknown', 'undisclosed'].includes(json.financial_outcome) && uuid(json.correlation_id));
                throw new InvoiceApiError(json.error.code, response.status, json.correlation_id);
            }
            return parse(json, response.status);
        } catch (error) {
            if (error instanceof InvoiceApiError) throw error;
            if (signal?.aborted) throw new InvoiceApiError('network_error');
            throw new InvoiceApiError('malformed_response');
        } finally { clearTimeout(timer); }
    }
    const inertiaPage = (name, props) => (v, status) => { insist(status === 200 && object(v) && v.component === `Admin/Billing/Invoices/${name}` && object(v.props)); return props(v.props); };
    return {
        list: (filters, signal) => request(invoiceBase + '?' + new URLSearchParams(filters), { signal, inertia: true, parse: inertiaPage('Index', (v) => page(v.invoices, (r) => invoice(r), invoiceBase)) }),
        detail: (id, signal) => { insist(uuid(id)); return request(invoiceBase + '/' + id, { signal, inertia: true, parse: inertiaPage('Show', (v) => invoice(v.invoice, true)) }); },
        context: (signal) => request(invoiceBase + '/create', { signal, inertia: true, parse: inertiaPage('Create', (v) => context(v.recovery_context)) }),
        options: (filters, signal) => request(invoiceBase + '/resident-options?' + new URLSearchParams(filters), { signal, parse: (v) => page(v, (r) => { exact(r, ['id', 'name_current', 'is_active']); insist(uuid(r.id) && typeof r.name_current === 'string' && typeof r.is_active === 'boolean'); }, invoiceBase + '/resident-options') }),
        preview: (target, signal) => request(invoiceBase + '/preview', { method: 'POST', body: target, signal, parse: (v, status) => { insist(status === 200); return preview(v); } }),
        issue: (body) => request(invoiceBase, { method: 'POST', body: { ...body, confirm: true }, parse: issued }),
        reconcile: (body) => request(invoiceBase + '/reconcile', { method: 'POST', body, parse: (v, status) => { insist(status === 200); return issued(v, status); } }),
    };
}
