import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function MonitoringHeader({ title, description, asOf }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function reload() {
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data di bawah belum diperbarui. Periksa koneksi lalu coba kembali.'); return false; };
        router.reload({ preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <header className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-800">Monitoring petugas</p>
        <div className="mt-3 flex flex-wrap items-start justify-between gap-5">
            <div><h1 className="text-3xl font-bold tracking-tight">{title}</h1><p className="mt-3 max-w-2xl text-sm leading-7 text-slate-600">{description}</p></div>
            <button type="button" onClick={reload} disabled={loading} aria-busy={loading} className="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold focus-visible:ring-4 focus-visible:ring-emerald-100 disabled:opacity-50">{loading ? 'Memuat…' : 'Muat ulang data'}</button>
        </div>
        <p className="mt-4 text-xs text-slate-500" role="status">Data dimuat: {formatMonitoringTime(asOf)} · WIB (UTC+07:00) · Pembaruan manual.</p>
        {error && <p role="alert" className="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}</p>}
        <nav aria-label="Halaman monitoring" className="mt-5 flex flex-wrap gap-5 text-sm font-semibold text-emerald-800"><Link href="/monitoring/records" className="hover:underline">Semua rekaman →</Link><Link href="/monitoring/open-incidents" className="hover:underline">Insiden terbuka · seluruh waktu →</Link></nav>
    </header>;
}
