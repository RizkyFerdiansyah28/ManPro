import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import ManagementPageHeader from '../Management/ManagementPageHeader';
import FamilyPageHeader from '../Family/FamilyPageHeader';
import Button from '../ui/Button';
import EmptyState from '../ui/EmptyState';
import StatusBadge from '../ui/StatusBadge';
import TableFrame from '../ui/TableFrame';
import FormField from '../FormField';
import { Fact, InvoiceStatus, dateLabel, useInvoiceDay } from '../Billing/InvoiceParts';
import formatPrice from '../../utils/formatPrice';
import { createPaymentApi, validateProofFile } from '../../utils/payments/api';
import { createPaymentWorkflow, uiContext, sameUiContext } from '../../utils/payments/workflow';
import { watchPaymentHold } from '../../utils/payments/sessionBoundary';

const pageBase = role => role === 'admin' ? '/admin/pembayaran' : '/keluarga/tagihan';
const detailPath = (role, row) => role === 'admin' ? pageBase(role) + '/' + row.invoice_id + '/' + row.id : pageBase(role) + '/' + row.id;
const name = label => label?.name_current || 'Nama tidak tersedia';
const busyNames = ['checking','preparing','submitting','reconciling'];
const adminSimulationDescription = 'Pemeriksaan ini menggunakan bukti pembayaran sintetis untuk pengujian sistem. Tidak ada transaksi bank nyata yang dilakukan.';
const paymentStatuses = {
    pending: ['warning', 'Menunggu keputusan Admin'],
    verified: ['positive', 'Terverifikasi'],
    rejected: ['danger', 'Ditolak'],
};
const pendingProofNotice = 'Bukti perlu diperiksa sebelum Admin dapat memverifikasi pembayaran.';
const errorText = e => ({ invalid_proof: 'Periksa bukti: JPEG/PNG, maksimal 2 MiB dan batas dimensi yang tercantum.', invalid_input: 'Periksa kembali isian Anda.',
    confirmation_required: 'Konfirmasi tindakan wajib diisi.', invalid_rejection: 'Periksa kode dan alasan penolakan.', bank_confirmation_required: 'Pemeriksaan penerimaan bank wajib dikonfirmasi.',
    authentication_required: 'Silakan masuk kembali.', session_expired: 'Sesi berakhir. Masuk kembali dan minta pemeriksaan bila ada tindakan yang belum pasti.',
    rate_limited: 'Terlalu banyak permintaan. Tunggu sebelum memeriksa kembali.', storage_unavailable: 'Penyimpanan penanda keamanan browser tidak tersedia. Tindakan ditahan.',
    coordination_unavailable: 'Koordinasi antar-tab tidak tersedia di browser ini. Tindakan pembayaran ditahan.',
    coordination_busy: 'Pengajuan ini sedang diproses di tab lain. Periksa kembali sebelum melakukan tindakan.',
    context_expired: 'Konteks tindakan telah kedaluwarsa.',
    forbidden: 'Akses pembayaran dibatasi.', not_found: 'Tagihan atau pembayaran tidak tersedia untuk akun ini.',
    method_not_allowed: 'Tindakan ini tidak tersedia.', gate_closed: 'Layanan pembayaran belum tersedia saat ini.',
    proof_boundary_unavailable: 'Layanan pembayaran belum tersedia saat ini.', network_error: 'Koneksi ke layanan pembayaran terganggu.',
    proof_scan_unavailable: 'Pemeriksaan bukti pembayaran sementara tidak tersedia.',
    malformed_response: 'Respons dari layanan pembayaran tidak dapat dibaca.' })[e?.code] || 'Informasi pembayaran belum dapat diproses.';

function useApi() {
    const page = usePage();
    const ref = useRef(null);
    if (!ref.current) ref.current = createPaymentApi({ version: () => page.version ?? '' });
    return ref.current;
}
function Frame({ role, title, headingRef, children }) {
    return <AppLayout><Head title={title + ' · Silansia'} /><div className={role === 'keluarga' ? 'ui-family-page' : 'ui-management-page'}>
        {role === 'keluarga' ? <FamilyPageHeader title={title} description="Tagihan dan bukti pembayaran di halaman ini menggunakan data sintetis untuk simulasi akademik." /> : <ManagementPageHeader><h1 ref={headingRef} tabIndex={headingRef ? -1 : undefined} className="ui-management-title">{title}</h1><p>{adminSimulationDescription}</p></ManagementPageHeader>}{children}
    </div></AppLayout>;
}
export function PaymentStatus({ status }) {
    const [tone, label] = paymentStatuses[status] ?? ['neutral', 'Tidak tersedia'];
    return <StatusBadge tone={tone}>{label}</StatusBadge>;
}
export function ErrorBox({ error, proofPreview = false }) {
    const ref = useRef(null);
    useEffect(() => { if (error) ref.current?.focus(); }, [error]);
    if (!error) return null;
    const message = proofPreview && error.status === 503 && error.code === 'proof_use_unavailable'
        ? 'Bukti pembayaran belum dapat dibuka saat ini. Pemeriksaan bukti mungkin belum selesai. Buka kembali pratinjau nanti.'
        : errorText(error);
    return <div role="alert" tabIndex={-1} ref={ref} className="ui-management-error-summary"><p>{message}</p>{error.correlation && <p>ID bantuan: {error.correlation}</p>}</div>;
}
export function PaymentListContent({ role, page, loading, error, onPage, status, onStatus, onReload }) {
    const today = useInvoiceDay();
    const admin = role === 'admin';
    const listName = admin ? 'pembayaran' : 'tagihan';
    const filters = admin ? Object.entries(paymentStatuses).map(([value, [, label]]) => [value, label])
        : [['unpaid', 'Belum lunas'], ['paid', 'Lunas'], ['cancelled', 'Dibatalkan']];
    const pager = page && (page.data.length > 0 || page.page > 1) && (
        <nav aria-label={`Halaman daftar ${listName}`} className="ui-management-actions">
            <Button variant="secondary" disabled={loading || page.page <= 1} onClick={() => onPage(page.page - 1)}>Sebelumnya</Button>
            <span>Halaman {page.page}</span>
            <Button variant="secondary" disabled={loading || !page.has_more} onClick={() => onPage(page.page + 1)}>Berikutnya</Button>
        </nav>
    );
    return <><section className="ui-management-panel"><div className="ui-management-actions">
        <label className="ui-field-label">Status {listName}<select className="ui-field-control" value={status} onChange={e => onStatus(e.target.value)} disabled={loading}>
            {!admin && <option value="">Semua status</option>}
            {filters.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select></label><Button variant="secondary" onClick={onReload} disabled={loading}>Muat ulang daftar</Button></div>
        {admin && <p className="ui-field-hint">Status pembayaran menunjukkan keputusan Admin. Buka detail untuk memeriksa apakah bukti dapat dibuka.</p>}
    </section>
        <ErrorBox error={error} />
        {loading ? <p role="status" className="ui-management-panel">Memuat daftar {listName}…</p> : !error && page && (page.data.length === 0 ? <>
            <EmptyState
                title={page.page > 1 ? 'Halaman ini tidak memuat data' : admin ? 'Belum ada pengajuan pembayaran pada status ini' : status ? 'Belum ada tagihan dengan status ini' : 'Belum ada tagihan untuk ditampilkan'}
                description={page.page > 1 ? 'Kembali ke halaman sebelumnya untuk melihat data lain.' : admin || status ? 'Ubah filter status untuk melihat data lain.' : 'Tagihan yang dapat diakses oleh akun Anda akan tampil di sini setelah diterbitkan.'}
            />
            {pager}
        </> : <section className="ui-management-panel">
            <TableFrame label={admin ? 'Antrean pembayaran' : 'Tagihan keluarga'}><table className="ui-management-table"><thead><tr><th scope="col">Invoice dan lansia</th>{admin && <th scope="col">Penanggung finansial snapshot</th>}<th scope="col">Nominal</th><th scope="col">{admin ? 'Diajukan' : 'Jatuh tempo'}</th><th scope="col">Status {listName}</th><th scope="col">Aksi</th></tr></thead>
                <tbody>{page.data.map(row => <tr key={row.id}><td><p>{role === 'admin' ? row.invoice_number || row.invoice_id : row.number}</p><p>{name(row.resident)}</p><small>{row.billing_month?.slice(0,7)}</small></td>
                    {role === 'admin' && <td>{name(row.financial_owner)}<small className="block">Nama direktori saat ini; penanggung mengikuti snapshot tagihan.</small></td>}
                    <td>{formatPrice(row.amount)}</td><td>{dateLabel(admin ? row.submitted_at : row.due_date, admin)}
                        {!admin && row.grace_period_ends_on && <small className="block">Masa tenggang sampai {dateLabel(row.grace_period_ends_on)}</small>}</td>
                    <td>{admin ? <PaymentStatus status={row.status} /> : <InvoiceStatus invoice={row} today={today} />}</td>
                    <td><Link className="ui-management-link" href={detailPath(role, row)}>Lihat detail</Link></td></tr>)}</tbody></table></TableFrame>
            {pager}
        </section>)}
    </>;
}
function paymentPageScope({ role, invoice_id, payment_id, payment_ui_context }) {
    const context = payment_ui_context;
    return JSON.stringify([role, invoice_id, payment_id, context?.version, context?.role, context?.actor_id,
        context?.session_context, context?.audience_context, context?.hold_key]);
}

export function PaymentListPage(props) {
    return <PaymentListScreen key={paymentPageScope(props)} {...props} />;
}

function PaymentListScreen({ role, payment_ui_context }) {
    const api = useApi();
    const [status,setStatus] = useState(role === 'admin' ? 'pending' : '');
    const [number,setNumber] = useState(1), [version,setVersion] = useState(0);
    const [data,setData] = useState(null), [loading,setLoading] = useState(true), [error,setError] = useState(null);
    const lostAccess = useRef(false);
    useEffect(() => {
        const reads = new AbortController(); let current = true;
        setLoading(true); setData(null); setError(null);
        if (lostAccess.current) { setLoading(false); setError({ code: 'session_expired' }); return; }
        try { if (uiContext(payment_ui_context).role !== role) throw new Error('role'); }
        catch { setLoading(false); setError({ code: 'forbidden' }); return; }
        const check = async () => { const fresh = uiContext(await api.uiContext(pageBase(role), reads.signal)); if (!sameUiContext(payment_ui_context, fresh)) { const e = new Error('session'); e.code = 'session_expired'; throw e; } };
        (async () => { await check(); const value = await api.list(role, { page: number, ...(status ? { status } : {}) }, reads.signal); await check(); if (current && !lostAccess.current) setData(value); })().catch(e => { if (current && e.code !== 'read_aborted') setError(e); }).finally(() => { if (current) setLoading(false); });
        const hide = () => { current = false; lostAccess.current = true; reads.abort(); setData(null); setError({ code: 'session_expired' }); setLoading(false); };
        window.addEventListener('silansia:payment-access-lost', hide); window.addEventListener('pagehide', hide);
        return () => { current = false; reads.abort(); window.removeEventListener('silansia:payment-access-lost', hide); window.removeEventListener('pagehide', hide); };
    }, [api, role, number, status, version, payment_ui_context]);
    return <Frame role={role} title={role === 'admin' ? 'Pemeriksaan Pembayaran Simulasi' : 'Simulasi Pembayaran'}><PaymentListContent role={role} page={data} loading={loading} error={error} status={status} onStatus={v => { setStatus(v); setNumber(1); }} onPage={setNumber} onReload={() => setVersion(v => v + 1)} /></Frame>;
}
export function PaymentFlowNotice({ state, onReconcile, onCheck, simulation = false }) {
    const ref = useRef(null);
    const titles = { checking: 'Memeriksa akses pembayaran', preparing: 'Menyiapkan permintaan', submitting: 'Menunggu hasil tindakan', committed: 'Tindakan tercatat', resolved: 'Hasil tindakan ditemukan', rejected: 'Pengajuan belum dicatat', conflict: 'Data berubah atau hasil perlu diperiksa', unknown: 'Hasil tindakan belum diketahui', unresolved: 'Hasil belum dapat dipastikan', manual_hold: 'Perlu pemeriksaan manual', session_lost: 'Akses sesi berubah', unavailable: 'Pembayaran belum tersedia' };
    useEffect(() => { if (!['idle','checking','preparing'].includes(state.name)) ref.current?.focus(); }, [state.name]);
    if (!titles[state.name]) return null;
    const canReconcile = ['unknown','unresolved','conflict'].includes(state.name);
    const uncertain = canReconcile || ['manual_hold','session_lost'].includes(state.name);
    const factLabel = { submission_recorded: simulation ? 'Pengajuan bukti simulasi tercatat.' : 'Pengajuan bukti tercatat. Ini tidak menyatakan dana sudah diterima.', verification_recorded: 'Verifikasi pembayaran tercatat.', rejection_recorded: 'Penolakan bukti tercatat.' }[state.fact?.command_fact];
    return <section ref={ref} tabIndex={-1} aria-live="polite" aria-busy={busyNames.includes(state.name)} className="ui-management-panel"><h2 className="ui-management-section-title">{titles[state.name]}</h2>
        {canReconcile && <p>Hasil tindakan belum dapat dipastikan. Periksa hasil tindakan yang sama sebelum melakukan tindakan lain.</p>}
        {uncertain && <p>Jangan mengirim ulang tindakan atau membuat pengajuan pengganti.</p>}
        {state.name === 'manual_hold' && <p>Context asli sudah tidak tersedia di halaman ini. Hasil tindakan sebelumnya belum dapat dipastikan. Hubungi pengelola untuk pemeriksaan manual.</p>}
        {state.name === 'session_lost' && <p>Masuk kembali untuk membaca data terbaru. Context tindakan lama tidak digunakan lintas sesi.</p>}
        {state.error && <p>{errorText(state.error)}{state.error.correlation && <span className="block">ID bantuan: {state.error.correlation}</span>}</p>}
        {factLabel && <p>{factLabel} {state.fact.replayed && 'Hasil ini sudah tercatat sebelumnya. '}Status terkini ditampilkan dari pembacaan tagihan terbaru.</p>}
        {canReconcile && <Button onClick={onReconcile}>Periksa hasil tindakan yang sama</Button>}
        {state.name === 'unavailable' && <Button variant="secondary" onClick={onCheck}>Periksa akses kembali</Button>}
    </section>;
}
function InvoiceFacts({ invoice, simulation = false }) {
    const today = useInvoiceDay();
    return <section className="ui-management-panel"><div className="ui-management-actions"><h2 className={'ui-management-section-title' + (simulation ? ' break-all' : '')}>{invoice.number}</h2><InvoiceStatus invoice={invoice} today={today} /></div><dl className="ui-management-facts">
        <Fact label="Lansia · nama saat ini"><span>{name(invoice.resident)}</span><small className="block break-all">{invoice.elderly_id}</small></Fact><Fact label="Periode tagihan">{invoice.billing_month.slice(0,7)}</Fact><Fact label="Nominal final"><strong className="ui-invoice-amount">{formatPrice(invoice.amount)}</strong></Fact><Fact label="Jatuh tempo">{dateLabel(invoice.due_date)}</Fact><Fact label="Akhir masa tenggang">{dateLabel(invoice.grace_period_ends_on)}</Fact>
        {invoice.financial_owner && <Fact label="Penanggung finansial snapshot">{name(invoice.financial_owner)}<small className="block break-all">{invoice.financial_owner.id}</small><small className="block">Nama direktori saat ini; UUID penanggung berasal dari snapshot.</small></Fact>}
    </dl>{!simulation && <p className="ui-field-hint">Nominal mengikuti tagihan. Untuk rekening tujuan resmi atau rincian periode layanan, hubungi Admin panti.</p>}</section>;
}
export function loadProofPreview(api, invoiceId, paymentId, { onLoad, onError, onAccessLost }, events = window) {
    const request = new AbortController();
    let objectUrl;
    function stop() {
        if (request.signal.aborted) return;
        request.abort();
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        events.removeEventListener('silansia:payment-access-lost', accessLost);
        events.removeEventListener('pagehide', accessLost);
    }
    function accessLost() {
        stop();
        onAccessLost();
    }
    events.addEventListener('silansia:payment-access-lost', accessLost);
    events.addEventListener('pagehide', accessLost);
    api.proof(invoiceId, paymentId, request.signal).then(blob => {
        if (request.signal.aborted) return;
        objectUrl = URL.createObjectURL(blob);
        onLoad(objectUrl);
    }).catch(error => {
        if (!request.signal.aborted && error.code !== 'read_aborted') onError(error);
    });
    return stop;
}
export function ProofViewer({ api, invoiceId, paymentId, dialogId, onDismiss, simulation = false, pending = false }) {
    const [open,setOpen] = useState(false), [url,setUrl] = useState(null), [error,setError] = useState(null);
    const titleId = useId();
    const closeButton = useRef(null);
    const stopRequest = useRef(null);
    const family = Boolean(onDismiss);
    const visible = family || open;
    const Heading = family ? 'h3' : 'h2';
    useEffect(() => {
        if (!visible) return;
        const stop = loadProofPreview(api, invoiceId, paymentId, {
            onLoad: setUrl,
            onError: setError,
            onAccessLost: () => {
                setUrl(null);
                if (onDismiss) onDismiss(false);
                else setOpen(false);
            },
        });
        stopRequest.current = stop;
        return () => {
            stop();
            stopRequest.current = null;
            setUrl(null);
        };
    }, [visible, api, invoiceId, paymentId, onDismiss]);
    useEffect(() => { if (family) closeButton.current?.focus(); }, [family]);

    function dismissFamily() {
        stopRequest.current?.();
        onDismiss(true);
    }

    return <section className="ui-management-panel" id={dialogId} role={family ? 'dialog' : undefined} aria-labelledby={family ? titleId : undefined}
        onKeyDown={family ? event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            dismissFamily();
        } : undefined}>
        <Heading id={family ? titleId : undefined} className="ui-management-section-title">{family || simulation ? 'Bukti pembayaran simulasi' : 'Bukti transfer privat'}</Heading>
        {pending && <p className="ui-field-hint">{pendingProofNotice} Pratinjau hanya tampil jika server mengizinkan akses bukti.</p>}
        {family
            ? <Button ref={closeButton} variant="secondary" onClick={dismissFamily}>Tutup bukti pembayaran simulasi</Button>
            : <Button variant="secondary" onClick={() => { setError(null); setOpen(v => !v); }}>{open ? 'Tutup bukti transfer' : 'Buka bukti transfer'}</Button>}
        <ErrorBox error={error} proofPreview />
        {visible && !url && !error && <p role="status">Memeriksa akses dan memuat pratinjau bukti…</p>}
        {visible && url && <div className="mt-4"><img data-payment-proof src={url} alt={family ? 'Bukti pembayaran simulasi yang diajukan' : 'Bukti transfer yang diajukan'} className="max-h-96 max-w-full rounded-lg border border-slate-200 object-contain" /><a href={url} download={family ? 'bukti-pembayaran-simulasi' : 'bukti-transfer'} className="ui-management-link">Unduh salinan bukti</a></div>}
    </section>;
}
export function ProofSubmissionForm({ disabled, onSubmit }) {
    const [file,setFile] = useState(null), [confirm,setConfirm] = useState(false), [error,setError] = useState(null);
    function submit(event) {
        event.preventDefault();
        const invalid = validateProofFile(file);
        setError(invalid);
        if (invalid || !confirm || disabled) return;
        onSubmit({ file, confirm: true });
        event.currentTarget.reset();
        setFile(null);
        setConfirm(false);
    }
    return <section className="ui-management-panel"><h2 className="ui-management-section-title">Pengajuan pembayaran simulasi</h2><p>Gunakan bukti sintetis untuk pengujian sistem. Setelah bukti diajukan, status tagihan mengikuti hasil pemeriksaan Admin.</p><form onSubmit={submit}>
        <FormField id="payment-proof" label="Bukti pembayaran simulasi" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required disabled={disabled} onChange={e => { const selected = e.target.files?.[0]; setFile(selected ?? null); setError(validateProofFile(selected)); }} error={error} hint="JPEG/PNG maksimal 2 MiB; lebar dan tinggi maksimal 2.048 px, total maksimal 4 juta piksel. Isi berkas tetap divalidasi server." />
        <label className="ui-management-choice"><input type="checkbox" className="ui-management-choice-control" checked={confirm} disabled={disabled} onChange={e => setConfirm(e.target.checked)} /><span>Saya mengonfirmasi bukti transfer simulasi ini untuk tagihan yang ditampilkan.</span></label>
        <Button type="submit" disabled={disabled || !confirm || !file || Boolean(error)}>Kirim bukti pembayaran</Button>
    </form></section>;
}
export function FamilyPaymentDetails({ invoice, payments, api, stateName, locked, generation, onNewAttempt, onSubmit }) {
    const [selectedAttempt, setSelectedAttempt] = useState(null);
    const trigger = useRef(null);
    const dialogId = useId();
    const closePreview = useCallback(returnFocus => {
        setSelectedAttempt(null);
        if (returnFocus && trigger.current?.isConnected) trigger.current.focus();
        trigger.current = null;
    }, []);
    const pending = payments.some(attempt => attempt.status === 'pending');
    const canSubmit = invoice.status === 'unpaid' && invoice.amount !== '0.00'
        && !pending && !payments.some(attempt => attempt.status === 'verified');

    return <>
        <InvoiceFacts invoice={invoice} simulation />
        <section className="ui-management-panel">
            <h2 className="ui-management-section-title">Riwayat pengajuan simulasi</h2>
            {payments.length === 0
                ? <p>{invoice.amount === '0.00' ? 'Tagihan tanpa biaya. Bukti pembayaran tidak diperlukan.' : 'Belum ada pengajuan bukti simulasi.'}</p>
                : <ol className="ui-invoice-history">{payments.map((attempt, index) => {
                    const open = selectedAttempt === attempt.id;
                    return <li key={attempt.id} className="space-y-2">
                        <PaymentStatus status={attempt.status} />
                        <p>{dateLabel(attempt.submitted_at, true)}</p>
                        {attempt.public_rejection_reason && <p>Alasan untuk keluarga: {attempt.public_rejection_reason}</p>}
                        <small className="block break-all">ID pengajuan {attempt.id}</small>
                        <Button variant="secondary" aria-haspopup="dialog" aria-expanded={open} aria-controls={open ? dialogId : undefined}
                            aria-label={(open ? 'Tutup' : 'Buka') + ' bukti pembayaran simulasi pengajuan ' + (index + 1)}
                            onClick={event => {
                                if (open) {
                                    closePreview(true);
                                    return;
                                }
                                trigger.current = event.currentTarget;
                                setSelectedAttempt(attempt.id);
                            }}>{open ? 'Tutup bukti' : 'Buka bukti'}</Button>
                        {open && <ProofViewer key={invoice.id + ':' + attempt.id} api={api} invoiceId={invoice.id} paymentId={attempt.id} dialogId={dialogId} onDismiss={closePreview} />}
                    </li>;
                })}</ol>}
            {pending && <div>
                <p role="status">Menunggu verifikasi Admin. Tagihan belum dinyatakan lunas.</p>
                <p className="ui-field-hint">{pendingProofNotice} Jika pratinjau belum tersedia, periksa kembali nanti.</p>
            </div>}
        </section>
        {canSubmit && ['committed','resolved'].includes(stateName) && <Button variant="secondary" onClick={onNewAttempt}>Siapkan pengajuan bukti baru</Button>}
        {canSubmit && <ProofSubmissionForm key={generation} disabled={locked} onSubmit={onSubmit} />}
    </>;
}
export function ReviewDialog({ mode, invoice, payment, onClose, onSubmit }) {
    const dialog = useRef(null);
    const initialField = useRef(null);
    const id = useId();
    const [confirm, setConfirm] = useState(false);
    const [receiptChecked, setReceiptChecked] = useState(false);
    const [reason, setReason] = useState('');
    const [publicReason, setPublicReason] = useState('');
    const [note, setNote] = useState('');
    const verify = mode === 'verify';
    useEffect(() => {
        const element = dialog.current;
        element.showModal();
        initialField.current?.focus();
        return () => { if (element.open) element.close(); };
    }, []);

    const validReason = /^[a-z][a-z0-9_]{0,39}$/.test(reason) && publicReason.trim().length > 0 && publicReason.length <= 2000 && note.length <= 2000;
    const canSubmit = confirm && (verify ? receiptChecked : validReason);
    function submit(event) {
        event.preventDefault();
        if (!canSubmit) return;
        onSubmit(verify
            ? { operation: 'verify', confirm: true, bank_receipt_checked: true }
            : { operation: 'reject', confirm: true, reason_code: reason, public_reason: publicReason, private_note: note || null });
    }
    function keepFocus(event) {
        if (event.key !== 'Tab') return;
        const controls = dialog.current.querySelectorAll('input:not(:disabled), textarea:not(:disabled), button:not(:disabled)');
        const first = controls[0], last = controls[controls.length - 1];
        const active = dialog.current.ownerDocument.activeElement;
        if (event.shiftKey ? active !== first : active !== last) return;
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
    return <dialog ref={dialog} className="ui-invoice-dialog" aria-labelledby={id + '-title'} aria-describedby={id + '-description'}
        onCancel={event => { event.preventDefault(); onClose(); }} onKeyDown={keepFocus}>
        <form className="space-y-4" onSubmit={submit}>
            <h2 id={id + '-title'} className="ui-management-section-title">{verify ? 'Verifikasi Pembayaran Simulasi' : 'Tolak Pengajuan Simulasi'}</h2>
            <p id={id + '-description'}>{verify
                ? 'Periksa receipt dummy terhadap tagihan ini. Server memeriksa ulang syarat verifikasi saat tindakan dikirim.'
                : 'Alasan publik akan terlihat oleh keluarga. Penolakan pengajuan simulasi dapat dilakukan berdasarkan metadata pembayaran.'}</p>
            <dl className="ui-management-facts">
                <Fact label="Nomor invoice"><span className="break-all">{invoice.number}</span></Fact>
                <Fact label="Nominal pengajuan">{formatPrice(payment.amount)}</Fact>
                <Fact label="ID pengajuan"><span className="break-all">{payment.id}</span></Fact>
            </dl>
            {verify
                ? <label className="ui-management-choice">
                    <input ref={initialField} type="checkbox" className="ui-management-choice-control" required checked={receiptChecked} onChange={event => setReceiptChecked(event.target.checked)} />
                    <span>Saya telah memeriksa receipt dummy dan mencocokkannya dengan tagihan.</span>
                </label>
                : <>
                    <FormField ref={initialField} id={id + '-reason'} label="Kode alasan" required maxLength={40} pattern="[a-z][a-z0-9_]{0,39}" value={reason}
                        onChange={event => setReason(event.target.value)} hint="Awali dengan huruf kecil. Gunakan huruf kecil, angka, atau garis bawah. Contoh receipt_not_confirmed." />
                    <div className="ui-field-group">
                        <label className="ui-field-label" htmlFor={id + '-public'}>Alasan untuk keluarga <span aria-hidden="true" className="ui-field-required">Wajib</span></label>
                        <textarea id={id + '-public'} className="ui-field-control" required maxLength={2000} value={publicReason} aria-describedby={id + '-public-hint'}
                            onChange={event => setPublicReason(event.target.value)} />
                        <p id={id + '-public-hint'} className="ui-field-hint">Keluarga dapat membaca alasan ini di riwayat pengajuan.</p>
                    </div>
                    <div className="ui-field-group">
                        <label className="ui-field-label" htmlFor={id + '-private'}>Catatan privat Admin (opsional)</label>
                        <textarea id={id + '-private'} className="ui-field-control" maxLength={2000} value={note} aria-describedby={id + '-private-hint'}
                            onChange={event => setNote(event.target.value)} />
                        <p id={id + '-private-hint'} className="ui-field-hint">Untuk catatan internal Admin. Keluarga hanya melihat alasan publik.</p>
                    </div>
                </>}
            <label className="ui-management-choice">
                <input type="checkbox" className="ui-management-choice-control" required checked={confirm} onChange={event => setConfirm(event.target.checked)} />
                <span>Saya mengonfirmasi tindakan ini pada pengajuan simulasi yang ditampilkan.</span>
            </label>
            <div className="ui-management-actions">
                <Button variant="secondary" onClick={onClose}>Kembali</Button>
                <Button type="submit" disabled={!canSubmit}>{verify ? 'Konfirmasi dan verifikasi' : 'Konfirmasi dan tolak'}</Button>
            </div>
        </form>
    </dialog>;
}

export function AdminPaymentDetails({ invoice, payment, api, locked, onSubmit }) {
    const [mode, setMode] = useState(null);
    const trigger = useRef(null);
    const reviewHeading = useRef(null);
    const canReview = invoice.status === 'unpaid' && payment.status === 'pending' && invoice.amount === payment.amount
        && invoice.current_revision_id === payment.invoice_revision_id;
    const activeMode = canReview && !locked ? mode : null;

    useEffect(() => {
        if (activeMode || !trigger.current) return;
        const button = trigger.current;
        trigger.current = null;
        (button.isConnected && !button.disabled ? button : reviewHeading.current)?.focus();
        if (mode) setMode(null);
    }, [activeMode, mode]);

    function openReview(event, operation) {
        trigger.current = event.currentTarget;
        setMode(operation);
    }

    return <>
        <InvoiceFacts invoice={invoice} simulation />
        <section className="ui-management-panel">
            <h2 className="ui-management-section-title">Pengajuan pembayaran simulasi</h2>
            <PaymentStatus status={payment.status} />
            <dl className="ui-management-facts">
                <Fact label="Nominal pengajuan">{formatPrice(payment.amount)}</Fact>
                <Fact label="Diajukan">{dateLabel(payment.submitted_at, true)}</Fact>
                <Fact label="Waktu review">{dateLabel(payment.verified_at, true)}</Fact>
                <Fact label="UUID revisi pengajuan"><span className="break-all">{payment.invoice_revision_id}</span></Fact>
            </dl>
            {payment.public_rejection_reason && <p>Alasan untuk keluarga: {payment.public_rejection_reason}</p>}
        </section>
        <ProofViewer api={api} invoiceId={invoice.id} paymentId={payment.id} simulation pending={payment.status === 'pending'} />
        <section className="ui-management-panel">
            <h2 ref={reviewHeading} tabIndex={-1} className="ui-management-section-title">Keputusan pemeriksaan simulasi</h2>
            {canReview ? <>
                <p>Verifikasi dapat dilanjutkan jika pemeriksaan server menyatakan bukti memenuhi syarat. Penolakan berdasarkan metadata tetap dapat diajukan saat bukti belum dapat dibuka.</p>
                <div className="ui-management-actions">
                    <Button disabled={locked} onClick={event => openReview(event, 'verify')}>Tinjau verifikasi</Button>
                    <Button variant="secondary" disabled={locked} onClick={event => openReview(event, 'reject')}>Tolak pengajuan simulasi</Button>
                </div>
            </> : <p>Pengajuan ini tidak tersedia untuk ditinjau saat ini.</p>}
        </section>
        {activeMode && <ReviewDialog key={activeMode} mode={activeMode} invoice={invoice} payment={payment}
            onClose={() => setMode(null)} onSubmit={values => { setMode(null); onSubmit(values); }} />}
    </>;
}
export function PaymentDetailPage(props) {
    return <PaymentDetailScreen key={paymentPageScope(props)} {...props} />;
}

function PaymentDetailScreen({ role, invoice_id, payment_id, payment_ui_context }) {
    const api = useApi(); const flow = useRef(null);
    const heading = useRef(null);
    const lostAccess = useRef(false);
    const [generation,setGeneration] = useState(0);
    const [state,setState] = useState({ name: 'checking' }), [loadedDetail,setData] = useState(null), [error,setError] = useState(null), [loading,setLoading] = useState(true), [version,setVersion] = useState(0);
    const family = role === 'keluarga';
    const familyScope = family ? [invoice_id, payment_ui_context?.session_context, payment_ui_context?.audience_context, payment_ui_context?.actor_id].join(':') : null;
    const uiPath = role === 'admin' ? pageBase(role) + '/' + invoice_id + '/' + payment_id : pageBase(role) + '/' + invoice_id;
    useEffect(() => {
        let instance, unwatch;
        try {
            const context = uiContext(payment_ui_context);
            if (context.role !== role) throw new Error('role');
            const storage = window.localStorage;
            instance = createPaymentWorkflow({
                api, target: { operation: family ? 'submit' : null, invoiceId: invoice_id, paymentId: payment_id, uiPath },
                context, storage, legacyStorage: window.sessionStorage, locks: window.navigator.locks,
                onChange: value => {
                    if (value.name === 'session_lost') { lostAccess.current = true; setData(null); }
                    setState(value);
                },
            });
            unwatch = watchPaymentHold(window, storage, context.hold_key, instance.observeHold);
        } catch {
            setState({ name: 'unavailable', error: { code: 'storage_unavailable' } });
            return;
        }
        flow.current = instance; instance.initialize();
        const lost = () => { lostAccess.current = true; instance.loseAccess(); setData(null); };
        const hide = () => { lostAccess.current = true; instance.suspend(); setData(null); };
        window.addEventListener('silansia:payment-access-lost', lost); window.addEventListener('pagehide', hide);
        return () => {
            instance.dispose(); unwatch(); flow.current = null;
            window.removeEventListener('silansia:payment-access-lost', lost);
            window.removeEventListener('pagehide', hide);
        };
    // PaymentDetailPage remounts this screen when any context field changes.
    }, [api, family, role, invoice_id, payment_id, uiPath, generation]);
    useEffect(() => { if (role === 'admin') heading.current?.focus(); }, [role]);
    useEffect(() => { if (['committed','resolved'].includes(state.name)) setVersion(v => v + 1); }, [state.name]);
    useEffect(() => {
        const read = new AbortController(); let live = true; setLoading(true); setData(null); setError(null);
        if (lostAccess.current) { setLoading(false); return; }
        api.detail(role, invoice_id, payment_id, read.signal).then(v => {
            if (!live || lostAccess.current) return;
            const wrongPayment = role === 'admin' && (v.payment?.id !== payment_id || v.payment?.invoice_id !== invoice_id);
            if (v.invoice?.id !== invoice_id || wrongPayment) {
                setError({ code: 'not_found' });
                return;
            }
            setData({ scope: familyScope, value: v });
        }).catch(e => { if (live && !lostAccess.current && e.code !== 'read_aborted') { setError(e); if ([401,403,404,419].includes(e.status)) flow.current?.loseAccess(); } }).finally(() => { if (live) setLoading(false); });
        return () => { live = false; read.abort(); };
    }, [api, role, invoice_id, payment_id, version, familyScope]);
    const locked = !['idle','rejected'].includes(state.name) || loading || Boolean(error);
    const data = loadedDetail?.scope === familyScope ? loadedDetail.value : null;
    const i = data?.invoice;
    return <Frame role={role} headingRef={family ? undefined : heading} title={role === 'admin' ? 'Detail Pemeriksaan Pembayaran Simulasi' : 'Detail Tagihan Simulasi'}><Link className="ui-management-link" href={pageBase(role)}>← {role === 'admin' ? 'Daftar pembayaran' : 'Daftar tagihan'}</Link>
        <PaymentFlowNotice state={state} simulation={family} onReconcile={() => flow.current?.reconcile()} onCheck={() => flow.current?.initialize()} /><ErrorBox error={error} />
        <div className="ui-management-actions"><Button variant="secondary" disabled={loading || busyNames.includes(state.name)} onClick={() => setVersion(v => v + 1)}>Muat ulang informasi</Button></div>
        {loading && <p role="status">Memuat informasi pembayaran…</p>}
        {i && <>{family
            ? <FamilyPaymentDetails key={familyScope} invoice={i} payments={data.payments} api={api} stateName={state.name} locked={locked} generation={generation}
                onNewAttempt={() => { setGeneration(v => v + 1); setVersion(v => v + 1); }} onSubmit={values => flow.current?.execute(values)} />
            : <AdminPaymentDetails invoice={i} payment={data.payment} api={api} locked={locked} onSubmit={values => flow.current?.execute(values)} />}
        <p className="ui-field-hint">Pemantauan keselamatan dan penanganan Perawat tetap berjalan terlepas dari status tagihan.</p></>}
    </Frame>;
}
