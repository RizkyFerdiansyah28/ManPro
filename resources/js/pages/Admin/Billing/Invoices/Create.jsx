import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import ManagementLayout from '../../../../components/Management/ManagementLayout';
import ManagementPageHeader from '../../../../components/Management/ManagementPageHeader';
import Button from '../../../../components/ui/Button';
import FormField from '../../../../components/FormField';
import ResidentOptions from '../../../../components/Billing/ResidentOptions';
import { InvoiceConfirmation, InvoiceError, PreviewSummary, RecoveryNotice } from '../../../../components/Billing/InvoiceParts';
import { createInvoiceApi } from '../../../../utils/billing/api';
import { invoiceBase } from '../../../../utils/billing/contracts';
import { createIntentStorage } from '../../../../utils/billing/intentStorage';
import { InvoiceWorkflow, observeInvoicePage } from '../../../../utils/billing/workflow';

export function InvoiceCreateContent({ actor, api, filters = {}, storage, environment = typeof window === 'undefined' ? null : window }) {
    const [resident, setResident] = useState(filters.elderly_id ?? '');
    const [billingMonth, setMonth] = useState((filters.billing_month ?? '').slice(0, 7));
    const [reviewed, setReviewed] = useState(false);
    const trigger = useRef(null);
    const workflow = useMemo(() => new InvoiceWorkflow({ api, store: createIntentStorage(storage), actor,
        supported: Boolean(environment && typeof environment.BroadcastChannel === 'function' && typeof environment.HTMLDialogElement?.prototype.showModal === 'function') }), [api, storage, actor, environment]);
    const state = useSyncExternalStore(workflow.subscribe, workflow.getSnapshot, workflow.getSnapshot);
    useEffect(() => {
        if (!environment) return;
        return observeInvoicePage({ workflow, target: environment, doc: environment.document });
    }, [workflow, environment]);
    useEffect(() => { if (state.result) router.visit(state.result.detail_url); }, [state.result]);
    const idle = state.name === 'idle' && !state.checking;
    return <>
        <InvoiceError error={state.error} />
        <p className="ui-management-note ui-management-note--warning">Penerbitan manual memakai enrollment dan tarif snapshot server. Jika hasil upaya sebelumnya belum jelas atau data browser hilang, periksa invoice dan minta review Admin sebelum membuat preview baru.</p>
        <form className="ui-management-form" aria-label="Pilihan penerbitan invoice" aria-busy={state.checking || state.name === 'previewing'} onSubmit={(e) => { e.preventDefault(); if (reviewed && idle) void workflow.preview({ elderly_id: resident, billing_month: billingMonth + '-01' }); }}>
            <fieldset className="ui-management-form__fields" disabled={!idle}>
                <legend className="sr-only">Pilihan layanan dan konfirmasi pemeriksaan</legend>
                <ResidentOptions api={api} value={resident} onChange={setResident} disabled={!idle} />
                <FormField id="invoice-month" label="Bulan layanan" type="month" required value={billingMonth} onChange={(e) => setMonth(e.target.value)} hint="Pilih bulan yang sudah selesai. Periode dikonversi ke tanggal 1 untuk kontrak server, tanpa perhitungan tagihan di browser." />
                <label className="ui-management-choice"><input type="checkbox" className="ui-management-choice-control" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} /><span>Saya sudah memastikan tidak ada proses penerbitan sebelumnya yang hasilnya belum jelas.</span></label>
                <Button type="submit" disabled={!reviewed || !resident || !billingMonth || !idle} processing={state.name === 'previewing'}>Preview invoice</Button>
            </fieldset>
        </form>
        <div role="status" aria-live="polite" className="ui-field-hint">{state.checking ? 'Memverifikasi konteks sesi dari server…' : ({ idle: 'Belum ada invoice diterbitkan dari pilihan ini.', previewing: 'Memeriksa kelayakan layanan…', eligible: 'Preview memenuhi syarat. Konfirmasi belum dikirim.', issued: 'Penerbitan committed dikonfirmasi server.', replayed: 'Hasil committed yang sama ditemukan kembali.' }[state.name] ?? '')}</div>
        <PreviewSummary preview={state.preview} />
        {['eligible', 'confirming'].includes(state.name) && <div className="ui-management-actions"><Button ref={trigger} onClick={() => workflow.confirm()} disabled={state.checking || state.name === 'confirming'}>Tinjau konfirmasi penerbitan</Button><Button variant="secondary" onClick={() => workflow.discardUnsent()} disabled={state.checking || state.name === 'confirming'}>Ubah pilihan sebelum dikirim</Button></div>}
        <InvoiceConfirmation trigger={trigger} open={state.name === 'confirming'} state={state} onCancel={() => workflow.cancelConfirmation()} onIssue={() => void workflow.issue()} />
        <RecoveryNotice state={state} onReconcile={() => void workflow.reconcile()} onCheck={() => void workflow.checkContext()} />
        {state.result && <Link href={state.result.detail_url} className="ui-button ui-button--primary">Buka invoice committed</Link>}
    </>;
}

export default function Create({ filters = {} }) {
    const page = usePage();
    const actor = page.props.auth?.user;
    const api = useMemo(() => createInvoiceApi({ version: () => page.version }), [page.version]);
    let storage = null;
    try { if (typeof window !== 'undefined') storage = window.sessionStorage; } catch { /* UI fails closed. */ }
    return <ManagementLayout><Head title="Terbitkan invoice · Silansia" /><ManagementPageHeader><Link href={invoiceBase} className="ui-management-link">Daftar invoice</Link><h1 className="ui-management-title">Terbitkan invoice</h1><p>Preview layanan, konfirmasi penerbitan, dan periksa hasil request yang sama bila koneksi terputus.</p></ManagementPageHeader>
        {actor?.role === 'admin' ? <InvoiceCreateContent key={actor.id} actor={actor.id} api={api} storage={storage} filters={filters} /> : <p role="alert">Hanya Admin aktif yang dapat mengakses penerbitan invoice.</p>}
    </ManagementLayout>;
}
