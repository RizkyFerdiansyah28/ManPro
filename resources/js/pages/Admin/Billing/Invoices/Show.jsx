import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../../components/Management/ManagementLayout';
import ManagementPageHeader from '../../../../components/Management/ManagementPageHeader';
import { Fact, InvoiceStatus, dateLabel } from '../../../../components/Billing/InvoiceParts';
import { invoice as validateInvoice, invoiceBase } from '../../../../utils/billing/contracts';
import formatPrice from '../../../../utils/formatPrice';

export function InvoiceDetailContent({ invoice }) {
    try { validateInvoice(invoice, true); } catch { return <div role="alert" className="ui-management-error-summary">Data detail invoice tidak dapat diverifikasi. Minta Admin memeriksa integritasnya.</div>; }
    const i = invoice; const r = i.current_revision;
    return <><section className="ui-management-panel"><div className="ui-management-actions"><h2 className="ui-management-section-title">{i.number}</h2><InvoiceStatus status={i.status} legacy={i.record_kind === 'legacy'} /></div><dl className="ui-management-facts">
        <Fact label="Nominal invoice"><strong className="ui-invoice-amount">{formatPrice(i.amount)}</strong></Fact><Fact label="Bulan layanan">{i.billing_month.slice(0, 7)}</Fact>
        <Fact label="UUID invoice">{i.id}</Fact><Fact label="Lansia · nama saat ini">{i.resident.name_current ?? 'Tidak tersedia'}<span className="block break-all">{i.resident.id}</span></Fact>
        <Fact label="Jatuh tempo">{dateLabel(i.due_date)}</Fact><Fact label="Akhir tenggang">{dateLabel(i.grace_period_ends_on)}</Fact>
        <Fact label="Penanggung finansial snapshot">{i.financial_owner ? <>{i.financial_owner.name_current ?? 'Nama kini tidak tersedia'}<span className="block break-all">{i.financial_owner.id}</span><small>Nama di atas adalah nama direktori saat ini; UUID penanggung berasal dari snapshot invoice.</small></> : 'Owner historis tidak tersedia untuk legacy.'}</Fact>
        <Fact label="UUID enrollment sumber">{i.service_enrollment_id}</Fact>
    </dl></section>
    {r ? <section className="ui-management-panel"><h2 className="ui-management-section-title">Snapshot revisi saat ini</h2><dl className="ui-management-facts"><Fact label="Revisi">{r.revision_no}</Fact><Fact label="UUID revisi">{r.id}</Fact><Fact label="Tarif snapshot">{formatPrice(r.monthly_price)}</Fact><Fact label="Hari ditagih / hari bulan">{r.chargeable_days} / {r.days_in_month}</Fact><Fact label="Awal interval">{dateLabel(r.effective_started_at, true)}</Fact><Fact label="Akhir interval (eksklusif)">{dateLabel(r.effective_ended_at, true)}</Fact><Fact label="Penerbitan awal">{dateLabel(i.initial_issued_at, true)}</Fact><Fact label="Waktu revisi">{dateLabel(r.issued_at, true)}</Fact></dl></section> : <p className="ui-management-note ui-management-note--warning">Legacy · snapshot finansial dan riwayat penerbitan terkelola belum tersedia. Invoice ini hanya dibaca; tidak dibuat revisi atau owner perkiraan.</p>}
    {r && <section className="ui-management-panel"><h2 className="ui-management-section-title">Riwayat penerbitan immutable</h2><ol className="ui-invoice-history">{i.actions.map((a) => <li key={a.sequence}><p>{a.action === 'zero_charge' ? 'Invoice tanpa biaya dicatat' : 'Invoice diterbitkan'}</p><p className="ui-field-hint">{dateLabel(a.occurred_at, true)}</p></li>)}</ol></section>}
    <p className="ui-field-hint">Halaman ini hanya baca. Monitoring keselamatan dan tindakan Perawat tidak dipengaruhi status tagihan.</p></>;
}
export default function Show(props) { return <ManagementLayout><Head title="Detail invoice · Silansia" /><ManagementPageHeader><Link href={invoiceBase} className="ui-management-link">Daftar invoice</Link><h1 className="ui-management-title">Detail invoice</h1><p>Fakta finansial dari server, tanpa perubahan histori.</p></ManagementPageHeader><InvoiceDetailContent {...props} /></ManagementLayout>; }
