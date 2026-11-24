import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import StatusBadge from '../ui/StatusBadge';
import Button from '../ui/Button';
import { invoiceBase, reasons } from '../../utils/billing/contracts';
import { invoiceDisplayStatus, isInvoiceDate, millisecondsUntilNextWibDay, wibDate } from '../../utils/billing/invoiceStatus';
import formatPrice from '../../utils/formatPrice';

export function useInvoiceDay() {
    const [today, setToday] = useState(wibDate);
    useEffect(() => {
        let timer;
        function refreshDay() {
            clearTimeout(timer);
            const now = new Date();
            const day = wibDate(now);
            setToday(day);
            timer = setTimeout(refreshDay, millisecondsUntilNextWibDay(now, day));
        }
        refreshDay();
        window.addEventListener('focus', refreshDay);
        return () => {
            clearTimeout(timer);
            window.removeEventListener('focus', refreshDay);
        };
    }, []);
    return today;
}

const invoiceStatusLabels = {
    not_due: ['neutral', 'Belum jatuh tempo'], in_grace: ['warning', 'Dalam masa tenggang'], past_grace: ['danger', 'Lewat masa tenggang'],
    paid: ['positive', 'Lunas'], cancelled: ['neutral', 'Dibatalkan'], unpaid: ['warning', 'Belum lunas'], unknown: ['neutral', 'Status tidak tersedia'],
};

export function InvoiceStatus({ invoice, today, legacy = false }) {
    const status = invoiceDisplayStatus(invoice, today);
    const [tone, text] = invoiceStatusLabels[status];
    return <div className="ui-invoice-badges"><StatusBadge tone={tone}>{text}</StatusBadge>{legacy && <StatusBadge>Legacy · baca saja</StatusBadge>}
        {status === 'unpaid' && <small className="ui-field-hint">Jadwal tagihan tidak tersedia</small>}</div>;
}
export function InvoiceError({ error }) {
    const ref = useRef(null);
    useEffect(() => { if (error) ref.current?.focus(); }, [error?.code, error?.correlation]);
    if (!error) return null;
    return <div ref={ref} tabIndex={-1} role="alert" className="ui-management-error-summary"><p>{error.message}</p>{error.correlation && <p className="mt-2">ID bantuan: {error.correlation}</p>}</div>;
}
export function Fact({ label, children }) { return <div><dt className="ui-management-facts__label">{label}</dt><dd className="ui-management-facts__value">{children ?? 'Tidak tersedia'}</dd></div>; }
export function dateLabel(value, time = false) {
    if (!value) return 'Tidak tersedia';
    if (!time && !isInvoiceDate(value)) return 'Tanggal tidak valid';
    const date = new Date(time ? value : value + 'T12:00:00+07:00');
    return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', ...(time ? { timeStyle: 'short' } : {}), timeZone: 'Asia/Jakarta' }).format(date) + (time ? ' WIB' : '') : 'Waktu tidak valid';
}
export function PreviewSummary({ preview }) {
    if (!preview) return null;
    const c = preview.calculation;
    return <section className="ui-management-panel" aria-labelledby="invoice-preview-title"><div className="ui-management-actions"><h2 id="invoice-preview-title" className="ui-management-section-title">Hasil preview</h2><StatusBadge tone={preview.decision === 'ISSUE' ? 'positive' : 'warning'}>{preview.decision}</StatusBadge></div>
        <p>{reasons[preview.reason]}</p><p className="ui-management-note">Preview belum menerbitkan invoice.</p>
        {c && <dl className="ui-management-facts"><Fact label="Nominal hasil perhitungan"><strong className="ui-invoice-amount">{formatPrice(c.amount)}</strong></Fact><Fact label="Tarif snapshot enrollment">{formatPrice(c.monthly_price)}</Fact>
            <Fact label="Awal interval layanan">{dateLabel(c.effective_started_at, true)}</Fact><Fact label="Akhir interval (eksklusif)">{dateLabel(c.effective_ended_at, true)}</Fact><Fact label="Hari ditagih / hari dalam bulan">{c.chargeable_days} / {c.days_in_month}</Fact>
            <Fact label="UUID penanggung finansial snapshot">{c.financial_owner_id}</Fact><Fact label="UUID periode ownership">{c.ownership_period_id}</Fact></dl>}
        {c && <p className="ui-field-hint">Tanggal jatuh tempo dan akhir tenggang tersedia setelah penerbitan dikonfirmasi server.</p>}
    </section>;
}
export function InvoiceConfirmation({ open, state, onCancel, onIssue, trigger }) {
    const dialog = useRef(null);
    const [accepted, setAccepted] = useState(false);
    useEffect(() => {
        if (!open) return;
        const el = dialog.current;
        const previous = trigger?.current ?? el.ownerDocument.activeElement;
        setAccepted(false);
        if (typeof el.showModal !== 'function') { onCancel(); return; }
        el.showModal();
        el.querySelector('input')?.focus();
        return () => { if (el.open) el.close(); if (previous?.isConnected) previous.focus(); };
    }, [open]);
    function keepKeyboardFocus(event) {
        if (event.key !== 'Tab') return;
        const controls = [...dialog.current.querySelectorAll('input:not([disabled]), button:not([disabled])')];
        const first = controls[0]; const last = controls.at(-1);
        const active = dialog.current.ownerDocument.activeElement;
        if (event.shiftKey && active === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && active === last) { event.preventDefault(); first?.focus(); }
    }
    return <dialog ref={dialog} className="ui-invoice-dialog" aria-labelledby="invoice-confirm-title" onKeyDown={keepKeyboardFocus} onCancel={(e) => { e.preventDefault(); onCancel(); }}>
        <h2 id="invoice-confirm-title" className="ui-management-section-title">Konfirmasi penerbitan invoice</h2>
        <p>Terbitkan untuk UUID lansia {state.target?.elderly_id} pada bulan {state.target?.billing_month?.slice(0, 7)}?</p>
        <p className="ui-invoice-amount">{state.preview?.calculation && formatPrice(state.preview.calculation.amount)}</p>
        <p>Tarif dan periode berasal dari snapshot server. Setelah dikirim, koneksi terputus tidak membuktikan transaksi dibatalkan.</p>
        <label className="ui-management-choice"><input type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} className="ui-management-choice-control" /><span>Saya telah memeriksa preview dan menyetujui penerbitan.</span></label>
        <div className="ui-management-actions"><Button variant="secondary" onClick={onCancel}>Kembali ke preview</Button><Button disabled={!accepted} onClick={() => { if (accepted) onIssue(); }}>Konfirmasi dan terbitkan</Button></div>
    </dialog>;
}
export function RecoveryNotice({ state, onReconcile, onCheck }) {
    const labels = { readiness_unavailable: 'Konteks belum dapat diverifikasi', authorization_lost: 'Akses sesi berubah', manual_recovery: 'Perlu pemeriksaan manual', outcome_unknown: 'Hasil penerbitan belum diketahui', unresolved: 'Intent menunggu kepastian', submitting: 'Menunggu hasil committed', reconciling: 'Memeriksa hasil intent yang sama' };
    if (!labels[state.name]) return null;
    const busy = ['submitting', 'reconciling'].includes(state.name);
    return <section className="ui-management-panel" aria-labelledby="invoice-recovery-title" aria-busy={busy || state.checking}><h2 id="invoice-recovery-title" className="ui-management-section-title">{labels[state.name]}</h2>
        <p>Jangan membuat penerbitan pengganti. Timeout, token berakhir, request belum terlihat, dan sesi terputus bukan bukti rollback.</p>
        {state.requestId && <p>ID request: <span className="break-all">{state.requestId}</span></p>}
        {state.expiresAt && <p>Konfirmasi awal berlaku sampai {dateLabel(state.expiresAt, true)}. Token expired hanya dapat digunakan untuk rekonsiliasi.</p>}
        <div className="ui-management-actions">{['outcome_unknown', 'unresolved'].includes(state.name) && <Button onClick={onReconcile} disabled={state.checking}>Periksa hasil intent yang sama</Button>}
            {!busy && <Button variant="secondary" onClick={onCheck} processing={state.checking}>Verifikasi ulang konteks</Button>}
            <Link href={invoiceBase} className="ui-management-link">Periksa daftar invoice</Link></div>
        <p className="ui-field-hint">Jika intent tidak dapat dipulihkan, minta Admin memeriksa hasil di database melalui prosedur yang disetujui. Jangan mengubah harga atau histori untuk mengulang penerbitan.</p>
    </section>;
}
