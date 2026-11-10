import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

const statuses = { pending: 'Menunggu tindak lanjut', acknowledged: 'Diakui · belum selesai', resolved: 'Selesai', false_alarm: 'Alarm palsu' };
function changed() { window.dispatchEvent(new Event('silansia:notifications-changed')); }
function ReadButton({ record, context }) {
    const form = useForm(context);
    const [error, setError] = useState('');
    if (record.read_at) return <span className="text-xs text-slate-500">Dibaca {formatMonitoringTime(record.read_at)}</span>;
    function submit() {
        setError('');
        const fail = () => { setError('Status baca belum dapat dipastikan. Muat ulang daftar atau coba kembali.'); return false; };
        form.post(`/notifications/${record.id}/read`, { preserveScroll: true, onSuccess: changed, onNetworkError: fail, onHttpException: fail });
    }
    return <div><button type="button" onClick={submit} disabled={form.processing} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Tandai dibaca'}</button>{error && <p role="alert" className="mt-2 text-xs text-red-800">{error}</p>}</div>;
}
export default function Index({ records, filters, as_of, errors }) {
    const [state, setState] = useState(filters.state);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const context = { state: filters.state, per_page: filters.per_page, page: records.current_page };
    const all = useForm({ ...context, confirm: false });
    const fail = () => { setError('Permintaan belum dapat dipastikan. Muat ulang daftar lalu coba kembali.'); return false; };
    function load(event) {
        event.preventDefault();
        setError('');
        router.get('/notifications', { state, per_page: perPage }, { onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    function readAll(event) {
        event.preventDefault();
        setError('');
        all.post('/notifications/read-all', { preserveScroll: true, onSuccess: () => { changed(); all.reset('confirm'); }, onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Notifikasi saya · Silansia" />
        <header className="mb-8"><h1 className="text-3xl font-bold">Notifikasi saya</h1><p className="mt-3 max-w-3xl text-sm leading-7 text-slate-600">Pemberitahuan dugaan jatuh untuk staf. Membaca notifikasi tidak mengakui atau menyelesaikan insiden. Status penanganan dapat berubah setelah notifikasi dibuat.</p><p className="mt-3 text-xs text-slate-500">Daftar dimuat {formatMonitoringTime(as_of)}. Gunakan muat ulang untuk memperbarui daftar; ringkasan di atas diperbarui terpisah.</p></header>
        {error && <p role="alert" className="mb-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}</p>}
        {Object.values(errors || {}).length > 0 && <div role="alert" className="mb-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{Object.values(errors).map((message, i) => <p key={i}>{message}</p>)}</div>}
        <form onSubmit={load} className="mb-5 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5">
            <label className="text-sm">Status baca<select value={state} onChange={(e) => setState(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2"><option value="all">Semua</option><option value="unread">Belum dibaca</option><option value="read">Sudah dibaca</option></select></label>
            <label className="text-sm">Per halaman<select value={perPage} onChange={(e) => setPerPage(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2">{[15, 30, 50].map((n) => <option key={n}>{n}</option>)}</select></label>
            <button disabled={loading} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{loading ? 'Memuat…' : 'Terapkan / muat ulang daftar'}</button>
        </form>
        <form onSubmit={readAll} className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
            <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" required checked={all.data.confirm} onChange={(e) => all.setData('confirm', e.target.checked)} className="mt-1" /><span>Tandai seluruh notifikasi insiden saya yang sudah tersimpan saat tindakan diproses, termasuk halaman dan filter lain, sebagai dibaca.</span></label>
            <button disabled={all.processing} className="mt-4 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-50">{all.processing ? 'Menyimpan…' : 'Tandai semua sebagai dibaca'}</button>
        </form>
        <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"><h2 className="text-lg font-semibold">Inbox insiden</h2>
            {!records.data.length ? <p role="status" className="py-10 text-sm text-slate-600">Tidak ada notifikasi pada halaman dan filter ini. Periksa juga antrean insiden terbuka.</p> : <ul className="mt-4 divide-y divide-slate-100">{records.data.map((record) => <li key={record.id} className="flex flex-wrap items-start justify-between gap-4 py-5">
                <div className="min-w-0 flex-1"><p className="text-sm font-semibold">{!record.read_at && <span className="mr-2 text-emerald-800">Belum dibaca ·</span>}{record.message}</p><time dateTime={record.created_at} className="mt-2 block text-xs text-slate-500">{formatMonitoringTime(record.created_at)}</time>
                    {record.incident && <><p className="mt-3 text-xs text-slate-600">Status kini: {statuses[record.incident.status]}{record.incident.is_verified ? ' · terverifikasi manusia' : ''}</p><Link href={record.incident.detail_url} className="mt-3 inline-block text-sm font-semibold text-emerald-800 hover:underline">Buka detail insiden →</Link></>}</div>
                <ReadButton key={`${record.id}:${record.read_at || 'unread'}`} record={record} context={context} />
            </li>)}</ul>}
            <OperationPagination page={records} label="notifikasi" />
        </section>
    </AppLayout>;
}
