import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../../components/Management/ManagementLayout';
import ManagementPageHeader from '../../../../components/Management/ManagementPageHeader';
import ManagementForm, { ManagementControl } from '../../../../components/Management/ManagementForm';
import Button from '../../../../components/ui/Button';
import EmptyState from '../../../../components/ui/EmptyState';
import TableFrame from '../../../../components/ui/TableFrame';
import { InvoiceStatus, dateLabel } from '../../../../components/Billing/InvoiceParts';
import { invoice, invoiceBase, page } from '../../../../utils/billing/contracts';
import formatPrice from '../../../../utils/formatPrice';

export function InvoiceIndexContent({ invoices, filters = {} }) {
    const form = useForm({ elderly_id: filters.elderly_id ?? '', billing_month: (filters.billing_month ?? '').slice(0, 7), status: filters.status ?? '', sort: filters.sort ?? 'billing_month_desc', per_page: filters.per_page ?? 15 });
    let valid = true;
    try { page(invoices, (r) => invoice(r), invoiceBase); } catch { valid = false; }
    function filter(e) {
        e.preventDefault();
        const values = { ...form.data, billing_month: form.data.billing_month ? form.data.billing_month + '-01' : '' };
        form.transform(() => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')));
        form.get(invoiceBase, { preserveState: true, replace: true });
    }
    return <section className="ui-management-list">
        <ManagementForm label="Filter daftar invoice" kind="filters" form={form} onSubmit={filter}>
            <div><label htmlFor="invoice-resident-filter" className="ui-field-label">UUID lansia</label><ManagementControl id="invoice-resident-filter" field="elderly_id" value={form.data.elderly_id} onChange={(e) => form.setData('elderly_id', e.target.value)} maxLength={36} /></div>
            <div><label htmlFor="invoice-month-filter" className="ui-field-label">Bulan layanan</label><ManagementControl id="invoice-month-filter" field="billing_month" type="month" value={form.data.billing_month} onChange={(e) => form.setData('billing_month', e.target.value)} /></div>
            <div><label htmlFor="invoice-status-filter" className="ui-field-label">Status invoice</label><ManagementControl id="invoice-status-filter" field="status" as="select" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="">Semua status</option><option value="unpaid">Belum lunas</option><option value="paid">Lunas</option><option value="cancelled">Dibatalkan</option></ManagementControl></div>
            <div><label htmlFor="invoice-sort" className="ui-field-label">Urutan</label><ManagementControl id="invoice-sort" field="sort" as="select" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)}><option value="billing_month_desc">Bulan terbaru</option><option value="billing_month_asc">Bulan terlama</option><option value="due_date_asc">Jatuh tempo terdekat</option></ManagementControl></div>
            <div><label htmlFor="invoice-page-size" className="ui-field-label">Per halaman</label><ManagementControl id="invoice-page-size" field="per_page" as="select" value={form.data.per_page} onChange={(e) => form.setData('per_page', e.target.value)}>{[15, 30, 50].map((n) => <option key={n} value={n}>{n}</option>)}</ManagementControl></div>
            <Button type="submit" variant="secondary" processing={form.processing}>Terapkan filter</Button>
        </ManagementForm>
        {form.processing && <p role="status" className="ui-invoice-loading">Memuat daftar invoice…</p>}
        {!valid ? <div role="alert" className="ui-management-error-summary"><p>Data invoice tidak dapat diverifikasi. Daftar tidak ditampilkan sebagai hasil kosong. Muat ulang atau minta Admin memeriksa integritas data.</p></div> : <>
            {invoices.data.length ? <TableFrame label="Daftar invoice layanan"><table className="ui-management-table ui-invoice-table"><caption className="sr-only">Invoice per lansia dan bulan layanan</caption><thead><tr><th scope="col">Invoice / lansia</th><th scope="col">Bulan</th><th scope="col">Nominal</th><th scope="col">Status</th><th scope="col">Jatuh tempo</th><th scope="col">Tindakan</th></tr></thead><tbody>
                {invoices.data.map((r) => <tr key={r.id}><td><p className="font-semibold break-all">{r.number}</p><p className="mt-2">{r.resident.name_current ?? 'Nama kini tidak tersedia'}</p><p className="ui-field-hint break-all">{r.resident.id}</p></td><td>{r.billing_month.slice(0, 7)}</td><td className="ui-invoice-money">{formatPrice(r.amount)}</td><td><InvoiceStatus status={r.status} legacy={r.record_kind === 'legacy'} /></td><td>{dateLabel(r.due_date)}</td><td><Link href={r.detail_url} className="ui-management-link" aria-label={`Detail invoice ${r.number}`}>Detail</Link></td></tr>)}
            </tbody></table></TableFrame> : <EmptyState title="Belum ada invoice yang sesuai" description="Ubah filter atau periksa kelayakan layanan melalui preview penerbitan." />}
            <p className="ui-invoice-loading ui-field-hint">Penanggung finansial snapshot dibaca pada detail jika tersedia. Owner historis untuk legacy tidak direkonstruksi dari keluarga saat ini.</p>
            <div className="ui-management-pagination"><p>{invoices.total} invoice · Halaman {invoices.current_page} dari {invoices.last_page}</p><nav aria-label="Halaman invoice">{invoices.prev_page_url ? <Link href={invoices.prev_page_url} preserveState className="ui-management-link">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}{invoices.next_page_url ? <Link href={invoices.next_page_url} preserveState className="ui-management-link">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}</nav></div>
        </>}
    </section>;
}
export default function Index(props) { return <ManagementLayout><Head title="Invoice · Silansia" /><ManagementPageHeader><div className="ui-management-actions"><div><h1 className="ui-management-title">Invoice layanan</h1><p>Daftar tagihan berdasarkan enrollment dan bulan layanan.</p></div><Link href={invoiceBase + '/create'} className="ui-button ui-button--primary">Terbitkan invoice</Link></div></ManagementPageHeader><InvoiceIndexContent {...props} /></ManagementLayout>; }
