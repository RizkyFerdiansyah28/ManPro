import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import ManagementPageHeader from '../Management/ManagementPageHeader';
import FamilyPageHeader from '../Family/FamilyPageHeader';
import Button from '../ui/Button';
import StatusBadge from '../ui/StatusBadge';
import TableFrame from '../ui/TableFrame';
import FormField from '../FormField';
import { Fact, InvoiceStatus, dateLabel } from '../Billing/InvoiceParts';
import formatPrice from '../../utils/formatPrice';
import { createPaymentApi, validateProofFile } from '../../utils/payments/api';
import { createPaymentWorkflow, uiContext, sameUiContext } from '../../utils/payments/workflow';

const pageBase = role => role === 'admin' ? '/admin/pembayaran' : '/keluarga/tagihan';
const detailPath = (role, row) => role === 'admin' ? pageBase(role) + '/' + row.invoice_id + '/' + row.id : pageBase(role) + '/' + row.id;
const name = label => label?.name_current || 'Nama tidak tersedia';
const busyNames = ['checking','preparing','submitting','reconciling'];
const errorText = e => ({ invalid_proof: 'Periksa bukti: JPEG/PNG, maksimal 2 MiB dan batas dimensi yang tercantum.', invalid_input: 'Periksa kembali isian Anda.',
    confirmation_required: 'Konfirmasi tindakan wajib diisi.', invalid_rejection: 'Periksa kode dan alasan penolakan.', bank_confirmation_required: 'Pemeriksaan penerimaan bank wajib dikonfirmasi.',
    authentication_required: 'Silakan masuk kembali.', session_expired: 'Sesi berakhir. Masuk kembali dan minta pemeriksaan bila ada tindakan yang belum pasti.',
    rate_limited: 'Terlalu banyak permintaan. Tunggu sebelum memeriksa kembali.', storage_unavailable: 'Penyimpanan penanda keamanan tab tidak tersedia. Pengajuan ditahan.',
    forbidden: 'Akses pembayaran dibatasi.', not_found: 'Tagihan atau pembayaran tidak tersedia untuk akun ini.' })[e?.code] || 'Informasi pembayaran belum dapat diproses. Coba pembacaan ulang atau hubungi Admin.';

function useApi() {
    const page = usePage();
    const ref = useRef(null);
    if (!ref.current) ref.current = createPaymentApi({ version: () => page.version ?? '' });
    return ref.current;
}
function Frame({ role, title, children }) {
    return <AppLayout><Head title={title + ' · Silansia'} /><div className={role === 'keluarga' ? 'ui-family-page' : 'ui-management-page'}>
        {role === 'keluarga' ? <FamilyPageHeader title={title} description="Informasi tagihan dan pengajuan bukti transfer keluarga." /> : <ManagementPageHeader><h1 className="ui-management-title">{title}</h1><p>Periksa bukti dan penerimaan transfer secara manual.</p></ManagementPageHeader>}{children}
    </div></AppLayout>;
}
export function PaymentStatus({ status }) {
    const [tone, label] = ({ pending: ['warning','Menunggu verifikasi'], verified: ['positive','Terverifikasi'], rejected: ['danger','Ditolak'] })[status] ?? ['neutral','Tidak tersedia'];
    return <StatusBadge tone={tone}>{label}</StatusBadge>;
}
function ErrorBox({ error }) {
    const ref = useRef(null);
    useEffect(() => { if (error) ref.current?.focus(); }, [error]);
    if (!error) return null;
    return <div role="alert" tabIndex={-1} ref={ref} className="ui-management-error-summary"><p>{errorText(error)}</p>{error.correlation && <p>ID bantuan: {error.correlation}</p>}</div>;
}
export function PaymentListContent({ role, page, loading, error, onPage, status, onStatus, onReload }) {
    return <><section className="ui-management-panel"><div className="ui-management-actions">
        <label className="ui-field-label">Status<select className="ui-field-control" value={status} onChange={e => onStatus(e.target.value)} disabled={loading}>
            {role === 'keluarga' && <option value="">Semua status</option>}
            {(role === 'admin' ? [['pending','Menunggu verifikasi'],['verified','Terverifikasi'],['rejected','Ditolak']] : [['unpaid','Belum lunas'],['paid','Lunas'],['cancelled','Dibatalkan']]).map(([v,l]) => <option key={v} value={v}>{l}</option>)}
        </select></label><Button variant="secondary" onClick={onReload} disabled={loading}>Muat ulang daftar</Button></div></section>
        <ErrorBox error={error} />
        {loading ? <p role="status" className="ui-management-panel">Memuat daftar pembayaran…</p> : page && (page.data.length === 0 ? <section className="ui-management-panel"><h2 className="ui-management-section-title">Belum ada data untuk ditampilkan</h2><p>Daftar hanya mencakup tagihan yang dapat diakses dan filter yang dipilih.</p></section> : <section className="ui-management-panel">
            <TableFrame label={role === 'admin' ? 'Antrean pembayaran' : 'Tagihan keluarga'}><table className="ui-management-table"><thead><tr><th scope="col">Invoice dan lansia</th>{role === 'admin' && <th scope="col">Penanggung finansial snapshot</th>}<th scope="col">Nominal</th><th scope="col">{role === 'admin' ? 'Diajukan' : 'Jatuh tempo'}</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
                <tbody>{page.data.map(row => <tr key={row.id}><td><p>{role === 'admin' ? row.invoice_number || row.invoice_id : row.number}</p><p>{name(row.resident)}</p><small>{row.billing_month?.slice(0,7)}</small></td>
                    {role === 'admin' && <td>{name(row.financial_owner)}<small className="block">Nama direktori saat ini; penanggung mengikuti snapshot tagihan.</small></td>}
                    <td>{formatPrice(row.amount)}</td><td>{dateLabel(role === 'admin' ? row.submitted_at : row.due_date, role === 'admin')}</td><td>{role === 'admin' ? <PaymentStatus status={row.status} /> : <InvoiceStatus status={row.status} />}</td>
                    <td><Link className="ui-management-link" href={detailPath(role, row)}>Lihat detail</Link></td></tr>)}</tbody></table></TableFrame>
            <nav aria-label="Halaman daftar pembayaran" className="ui-management-actions"><Button variant="secondary" disabled={loading || page.page <= 1} onClick={() => onPage(page.page - 1)}>Sebelumnya</Button><span>Halaman {page.page}</span><Button variant="secondary" disabled={loading || !page.has_more} onClick={() => onPage(page.page + 1)}>Berikutnya</Button></nav>
        </section>)}
    </>;
}
export function PaymentListPage({ role, payment_ui_context }) {
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
    return <Frame role={role} title={role === 'admin' ? 'Pembayaran' : 'Tagihan'}><PaymentListContent role={role} page={data} loading={loading} error={error} status={status} onStatus={v => { setStatus(v); setNumber(1); }} onPage={setNumber} onReload={() => setVersion(v => v + 1)} /></Frame>;
}
export function PaymentFlowNotice({ state, onReconcile, onCheck }) {
    const ref = useRef(null);
    const titles = { checking: 'Memeriksa akses pembayaran', preparing: 'Menyiapkan permintaan', submitting: 'Menunggu hasil tindakan', committed: 'Tindakan tercatat', resolved: 'Hasil tindakan ditemukan', rejected: 'Pengajuan belum dicatat', conflict: 'Data berubah atau hasil perlu diperiksa', unknown: 'Hasil tindakan belum diketahui', unresolved: 'Hasil belum dapat dipastikan', manual_hold: 'Perlu pemeriksaan manual', session_lost: 'Akses sesi berubah', unavailable: 'Pembayaran belum tersedia' };
    useEffect(() => { if (!['idle','checking','preparing'].includes(state.name)) ref.current?.focus(); }, [state.name]);
    if (!titles[state.name]) return null;
    const uncertain = ['unknown','unresolved','conflict','manual_hold','session_lost'].includes(state.name);
    const factLabel = { submission_recorded: 'Pengajuan bukti tercatat. Ini tidak menyatakan dana sudah diterima.', verification_recorded: 'Verifikasi pembayaran tercatat.', rejection_recorded: 'Penolakan bukti tercatat.' }[state.fact?.command_fact];
    return <section ref={ref} tabIndex={-1} aria-live="polite" aria-busy={busyNames.includes(state.name)} className="ui-management-panel"><h2 className="ui-management-section-title">{titles[state.name]}</h2>
        {uncertain && <p>Jangan mengirim pengajuan pengganti. Koneksi terputus, respons 500/503 atau hasil yang belum terlihat bukan bukti transaksi dibatalkan.</p>}
        {state.name === 'manual_hold' && <p>Context asli sudah tidak tersedia di halaman ini. Hubungi Admin untuk pemeriksaan aman; halaman tidak membuat request pengganti.</p>}
        {state.name === 'session_lost' && <p>Masuk kembali untuk membaca data terbaru. Context tindakan lama tidak digunakan lintas sesi.</p>}
        {state.error && <p>{errorText(state.error)}{state.error.correlation && <span className="block">ID bantuan: {state.error.correlation}</span>}</p>}
        {factLabel && <p>{factLabel} Status terkini ditampilkan dari pembacaan tagihan terbaru.</p>}
        {['unknown','unresolved','conflict'].includes(state.name) && <Button onClick={onReconcile}>Periksa hasil tindakan yang sama</Button>}
        {state.name === 'unavailable' && <Button variant="secondary" onClick={onCheck}>Periksa akses kembali</Button>}
    </section>;
}
function InvoiceFacts({ invoice }) {
    return <section className="ui-management-panel"><div className="ui-management-actions"><h2 className="ui-management-section-title">{invoice.number}</h2><InvoiceStatus status={invoice.status} /></div><dl className="ui-management-facts">
        <Fact label="Lansia · nama saat ini"><span>{name(invoice.resident)}</span><small className="block break-all">{invoice.elderly_id}</small></Fact><Fact label="Periode tagihan">{invoice.billing_month.slice(0,7)}</Fact><Fact label="Nominal final"><strong className="ui-invoice-amount">{formatPrice(invoice.amount)}</strong></Fact><Fact label="Jatuh tempo">{dateLabel(invoice.due_date)}</Fact><Fact label="Akhir masa tenggang">{dateLabel(invoice.grace_period_ends_on)}</Fact>
        {invoice.financial_owner && <Fact label="Penanggung finansial snapshot">{name(invoice.financial_owner)}<small className="block break-all">{invoice.financial_owner.id}</small><small className="block">Nama direktori saat ini; UUID penanggung berasal dari snapshot.</small></Fact>}
    </dl><p className="ui-field-hint">Nominal mengikuti tagihan. Untuk rekening tujuan resmi atau rincian periode layanan, hubungi Admin panti.</p></section>;
}
function ProofViewer({ api, invoiceId, paymentId }) {
    const [open,setOpen] = useState(false), [url,setUrl] = useState(null), [error,setError] = useState(null);
    useEffect(() => {
        if (!open) return;
        const read = new AbortController(); let live = true, objectUrl = null;
        api.proof(invoiceId, paymentId, read.signal).then(blob => { if (live) { objectUrl = URL.createObjectURL(blob); setUrl(objectUrl); } }).catch(e => { if (live && e.code !== 'read_aborted') setError(e); });
        const close = () => { live = false; read.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); setUrl(null); setOpen(false); };
        window.addEventListener('silansia:payment-access-lost', close); window.addEventListener('pagehide', close);
        return () => { live = false; read.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); setUrl(null); window.removeEventListener('silansia:payment-access-lost', close); window.removeEventListener('pagehide', close); };
    }, [open, api, invoiceId, paymentId]);
    return <section className="ui-management-panel"><h2 className="ui-management-section-title">Bukti transfer privat</h2><Button variant="secondary" onClick={() => { setError(null); setOpen(v => !v); }}>{open ? 'Tutup bukti transfer' : 'Buka bukti transfer'}</Button><ErrorBox error={error} />
        {open && !url && !error && <p role="status">Memuat bukti yang diizinkan…</p>}{url && <div className="mt-4"><img data-payment-proof src={url} alt="Bukti transfer yang diajukan" className="max-h-96 max-w-full rounded-lg border border-slate-200 object-contain" /><a href={url} download="bukti-transfer" className="ui-management-link">Unduh salinan bukti</a></div>}
    </section>;
}
export function ProofSubmissionForm({ disabled, onSubmit }) {
    const [file,setFile] = useState(null), [confirm,setConfirm] = useState(false), [error,setError] = useState(null);
    function submit(event) { event.preventDefault(); const invalid = validateProofFile(file); setError(invalid); if (!invalid && confirm && !disabled) onSubmit({ file, confirm: true }); }
    return <section className="ui-management-panel"><h2 className="ui-management-section-title">Ajukan bukti pembayaran</h2><p>Bukti akan diperiksa Admin. Pengajuan tidak otomatis membuat tagihan lunas.</p><form onSubmit={submit}>
        <FormField id="payment-proof" label="Bukti transfer" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required disabled={disabled} onChange={e => { const selected = e.target.files?.[0]; setFile(selected ?? null); setError(validateProofFile(selected)); }} error={error} hint="JPEG/PNG maksimal 2 MiB; lebar dan tinggi maksimal 2.048 px, total maksimal 4 juta piksel. Isi berkas tetap divalidasi server." />
        <label className="ui-management-choice"><input type="checkbox" className="ui-management-choice-control" checked={confirm} disabled={disabled} onChange={e => setConfirm(e.target.checked)} /><span>Saya mengonfirmasi bukti transfer ini untuk tagihan yang ditampilkan.</span></label>
        <Button type="submit" disabled={disabled || !confirm || !file || Boolean(error)}>Kirim bukti pembayaran</Button>
    </form></section>;
}
function ReviewDialog({ mode, onClose, onSubmit, disabled }) {
    const dialog = useRef(null); const [confirm,setConfirm] = useState(false), [bank,setBank] = useState(false), [reason,setReason] = useState(''), [publicReason,setPublicReason] = useState(''), [note,setNote] = useState('');
    useEffect(() => {
        if (!mode) return;
        const el = dialog.current, previous = document.activeElement;
        setConfirm(false); setBank(false); setReason(''); setPublicReason(''); setNote('');
        el.showModal(); el.querySelector('input')?.focus();
        return () => { if (el.open) el.close(); setNote(''); if (previous?.isConnected) previous.focus(); };
    }, [mode]);
    const validReason = /^[a-z][a-z0-9_]{0,39}$/.test(reason) && publicReason.trim().length > 0 && publicReason.length <= 2000 && note.length <= 2000;
    function submit(e) { e.preventDefault(); if (disabled || !confirm || (mode === 'verify' ? !bank : !validReason)) return; onSubmit({ operation: mode, confirm: true, ...(mode === 'verify' ? { bank_receipt_checked: true } : { reason_code: reason, public_reason: publicReason, private_note: note || null }) }); setNote(''); onClose(); }
    function keys(e) { if (e.key !== 'Tab') return; const controls = [...dialog.current.querySelectorAll('input,textarea,button')].filter(el => !el.disabled); const first = controls[0], last = controls.at(-1); if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); } }
    return <dialog ref={dialog} className="ui-invoice-dialog" aria-labelledby="payment-review-title" onCancel={e => { e.preventDefault(); onClose(); }} onKeyDown={keys}><form onSubmit={submit}>
        <h2 id="payment-review-title" className="ui-management-section-title">{mode === 'verify' ? 'Konfirmasi verifikasi pembayaran' : 'Konfirmasi penolakan bukti'}</h2>
        {mode === 'verify' ? <><p>Verifikasi bersifat final dan menjadikan tagihan lunas. Bukti unggahan saja tidak membuktikan dana telah diterima.</p><label className="ui-management-choice"><input type="checkbox" className="ui-management-choice-control" checked={bank} onChange={e => setBank(e.target.checked)} /><span>Saya sudah memeriksa mutasi bank dan memastikan penerimaan dana sesuai tagihan.</span></label></> : <><p>Penolakan menutup attempt ini; tagihan tetap belum lunas. Keluarga dapat mengajukan bukti baru bila tagihan masih memenuhi syarat.</p><FormField id="payment-reason" label="Kode alasan" required maxLength={40} pattern="[a-z][a-z0-9_]{0,39}" value={reason} onChange={e => setReason(e.target.value)} hint="Huruf kecil, angka atau garis bawah; diawali huruf. Contoh: receipt_not_confirmed." />
            <label className="ui-field-label" htmlFor="payment-public-reason">Alasan untuk keluarga</label><textarea id="payment-public-reason" className="ui-field-control" required maxLength={2000} value={publicReason} onChange={e => setPublicReason(e.target.value)} />
            <label className="ui-field-label" htmlFor="payment-private-note">Catatan privat Admin (opsional)</label><textarea id="payment-private-note" className="ui-field-control" maxLength={2000} value={note} onChange={e => setNote(e.target.value)} /><p className="ui-field-hint">Catatan ini tidak ditampilkan kepada keluarga.</p></>}
        <label className="ui-management-choice"><input type="checkbox" className="ui-management-choice-control" checked={confirm} onChange={e => setConfirm(e.target.checked)} /><span>Saya mengonfirmasi tindakan final ini pada pembayaran yang ditampilkan.</span></label>
        <div className="ui-management-actions"><Button variant="secondary" onClick={onClose}>Kembali</Button><Button type="submit" disabled={disabled || !confirm || (mode === 'verify' ? !bank : !validReason)}> {mode === 'verify' ? 'Konfirmasi dan verifikasi' : 'Konfirmasi dan tolak'}</Button></div>
    </form></dialog>;
}
export function PaymentDetailPage({ role, invoice_id, payment_id, payment_ui_context }) {
    const api = useApi(); const flow = useRef(null);
    const lostAccess = useRef(false);
    const [generation,setGeneration] = useState(0);
    const [state,setState] = useState({ name: 'checking' }), [data,setData] = useState(null), [error,setError] = useState(null), [loading,setLoading] = useState(true), [version,setVersion] = useState(0), [mode,setMode] = useState(null);
    const uiPath = role === 'admin' ? pageBase(role) + '/' + invoice_id + '/' + payment_id : pageBase(role) + '/' + invoice_id;
    useEffect(() => {
        let instance;
        try { if (uiContext(payment_ui_context).role !== role) throw new Error('role'); instance = createPaymentWorkflow({ api, target: { operation: role === 'keluarga' ? 'submit' : null, invoiceId: invoice_id, paymentId: payment_id, uiPath }, context: payment_ui_context, storage: window.sessionStorage, onChange: value => { if (value.name === 'session_lost') { lostAccess.current = true; setData(null); setMode(null); } setState(value); } }); }
        catch { setState({ name: 'unavailable', error: { code: 'storage_unavailable' } }); return; }
        flow.current = instance; instance.initialize();
        const lost = () => { lostAccess.current = true; instance.loseAccess(); setData(null); setMode(null); };
        const hide = () => { lostAccess.current = true; instance.dispose(); setData(null); setMode(null); setState({ name: 'manual_hold' }); };
        window.addEventListener('silansia:payment-access-lost', lost); window.addEventListener('pagehide', hide);
        return () => { instance.dispose(); flow.current = null; window.removeEventListener('silansia:payment-access-lost', lost); window.removeEventListener('pagehide', hide); };
    }, [api, role, invoice_id, payment_id, payment_ui_context, uiPath, generation]);
    useEffect(() => { if (['committed','resolved'].includes(state.name)) setVersion(v => v + 1); }, [state.name]);
    useEffect(() => {
        const read = new AbortController(); let live = true; setLoading(true); setData(null); setError(null);
        if (lostAccess.current) { setLoading(false); return; }
        api.detail(role, invoice_id, payment_id, read.signal).then(v => { if (live && !lostAccess.current) setData(v); }).catch(e => { if (live && !lostAccess.current && e.code !== 'read_aborted') { setError(e); if ([401,403,404,419].includes(e.status)) flow.current?.loseAccess(); } }).finally(() => { if (live) setLoading(false); });
        return () => { live = false; read.abort(); };
    }, [api, role, invoice_id, payment_id, version]);
    const locked = !['idle','rejected'].includes(state.name) || loading || Boolean(error);
    const i = data?.invoice, p = data?.payment;
    const canSubmit = i?.status === 'unpaid' && i.amount !== '0.00' && data?.payments && !data.payments.some(p => ['pending','verified'].includes(p.status));
    const canReview = i?.status === 'unpaid' && p?.status === 'pending' && i.amount === p.amount && i.current_revision_id === p.invoice_revision_id;
    return <Frame role={role} title={role === 'admin' ? 'Detail pembayaran' : 'Detail tagihan'}><Link className="ui-management-link" href={pageBase(role)}>← {role === 'admin' ? 'Daftar pembayaran' : 'Daftar tagihan'}</Link>
        <PaymentFlowNotice state={state} onReconcile={() => flow.current?.reconcile()} onCheck={() => flow.current?.initialize()} /><ErrorBox error={error} />
        <div className="ui-management-actions"><Button variant="secondary" disabled={loading || busyNames.includes(state.name)} onClick={() => setVersion(v => v + 1)}>Muat ulang informasi</Button></div>
        {loading && <p role="status">Memuat informasi pembayaran…</p>}
        {i && <><InvoiceFacts invoice={i} />{role === 'keluarga' ? <>
            <section className="ui-management-panel"><h2 className="ui-management-section-title">Riwayat pengajuan bukti</h2>{data.payments.length === 0 ? <p>{i.status === 'paid' && i.amount === '0.00' ? 'Tagihan tanpa biaya. Tidak diperlukan payment atau bukti transfer.' : 'Belum ada pengajuan bukti.'}</p> : <ol className="ui-invoice-history">{data.payments.map(attempt => <li key={attempt.id}><PaymentStatus status={attempt.status} /><p>{dateLabel(attempt.submitted_at, true)}</p>{attempt.public_rejection_reason && <p>Alasan untuk keluarga: {attempt.public_rejection_reason}</p>}<small className="break-all">ID pengajuan {attempt.id}</small></li>)}</ol>}
                {data.payments.some(p => p.status === 'pending') && <p>Menunggu verifikasi Admin. Tagihan belum dinyatakan lunas.</p>}</section>
            {canSubmit && ['committed','resolved'].includes(state.name) && <Button variant="secondary" onClick={() => { setGeneration(v => v + 1); setVersion(v => v + 1); }}>Siapkan pengajuan bukti baru</Button>}
            {canSubmit && <ProofSubmissionForm key={generation} disabled={locked} onSubmit={values => flow.current?.execute(values)} />}
        </> : p && <><section className="ui-management-panel"><h2 className="ui-management-section-title">Pengajuan pembayaran</h2><PaymentStatus status={p.status} /><dl className="ui-management-facts"><Fact label="Nominal pengajuan">{formatPrice(p.amount)}</Fact><Fact label="Diajukan">{dateLabel(p.submitted_at, true)}</Fact><Fact label="Waktu review">{dateLabel(p.verified_at, true)}</Fact><Fact label="UUID revisi pengajuan">{p.invoice_revision_id}</Fact></dl>{p.public_rejection_reason && <p>Alasan untuk keluarga: {p.public_rejection_reason}</p>}</section>
            <ProofViewer api={api} invoiceId={invoice_id} paymentId={payment_id} />
            {canReview && <section className="ui-management-panel"><h2 className="ui-management-section-title">Pemeriksaan Admin</h2><div className="ui-management-actions"><Button disabled={locked} onClick={() => setMode('verify')}>Tinjau verifikasi</Button><Button variant="secondary" disabled={locked} onClick={() => setMode('reject')}>Tolak bukti</Button></div></section>}
        </>}
        <p className="ui-field-hint">Pemantauan keselamatan dan penanganan Perawat tetap berjalan terlepas dari status tagihan.</p></>}
        {role === 'admin' && <ReviewDialog mode={mode} disabled={locked} onClose={() => setMode(null)} onSubmit={values => flow.current?.execute(values)} />}
    </Frame>;
}
