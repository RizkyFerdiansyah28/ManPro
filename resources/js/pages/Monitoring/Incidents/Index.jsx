import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import OperationPagination from '../../../components/OperationPagination';
import formatMonitoringTime from '../../../utils/formatMonitoringTime';

export const incidentStatuses = { pending: 'Menunggu tindak lanjut', acknowledged: 'Diakui · belum selesai', resolved: 'Selesai', false_alarm: 'Alarm palsu' };

export default function Index({ records, filters }) {
    const [status, setStatus] = useState(filters.status);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function load(event) {
        event?.preventDefault();
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data belum diperbarui; periksa koneksi lalu coba kembali.'); return false; };
        router.get('/monitoring/incidents', { status, per_page: perPage }, { onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Seluruh insiden · Silansia" />
        <header className="mb-8"><p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">Monitoring petugas</p><h1 className="mt-3 text-3xl font-bold">Seluruh insiden</h1><p className="mt-3 text-sm leading-7 text-slate-600">Histori sepanjang waktu, termasuk insiden selesai dan alarm palsu. Pembaruan dilakukan secara manual.</p><Link href="/monitoring/open-incidents" className="mt-4 inline-block text-sm font-semibold text-emerald-800">Lihat antrean terbuka →</Link></header>
        <form onSubmit={load} className="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5">
            <label className="text-sm">Status<select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2"><option value="">Semua status</option>{Object.entries(incidentStatuses).map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select></label>
            <label className="text-sm">Per halaman<select value={perPage} onChange={(e) => setPerPage(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2">{[15, 30, 50].map((n) => <option key={n}>{n}</option>)}</select></label>
            <button disabled={loading} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{loading ? 'Memuat…' : 'Terapkan / muat ulang'}</button>
        </form>
        {error && <p role="alert" className="mb-5 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}</p>}
        <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"><h2 className="mb-4 text-lg font-semibold">Kejadian terbaru</h2>
            {!records.data.length ? <p role="status" className="py-10 text-sm text-slate-600">Tidak ada insiden pada halaman dan filter ini.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><caption className="sr-only">Insiden seluruh waktu</caption><thead><tr>{['Kejadian', 'Kamera / lokasi historis', 'Status / identitas', 'Detail'].map((label) => <th key={label} scope="col" className="border-b px-3 py-4">{label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{records.data.map((record) => <tr key={record.id}>
                <td className="px-3 py-5"><time dateTime={record.occurred_at}>{formatMonitoringTime(record.occurred_at)}</time></td>
                <td className="px-3 py-5"><p>{record.camera_name}</p><p className="mt-2 text-xs text-slate-500">{record.context.room_code ? `${record.context.room_code} · ${record.context.room_name} · ${record.context.class_name}` : 'Lokasi historis tidak diketahui'}</p></td>
                <td className="px-3 py-5"><p>{incidentStatuses[record.status]}</p><p className="mt-2 text-xs text-slate-500">{record.is_verified ? 'Jatuh terverifikasi' : 'Belum/tidak terverifikasi'} · {record.identity_name || 'Identitas belum diketahui'}</p></td>
                <td className="px-3 py-5"><Link href={record.detail_url} className="font-semibold text-emerald-800 hover:underline" aria-label={`Detail insiden ${formatMonitoringTime(record.occurred_at)}`}>Buka detail →</Link></td>
            </tr>)}</tbody></table></div>}
            <OperationPagination page={records} label="insiden" />
        </section>
    </AppLayout>;
}
