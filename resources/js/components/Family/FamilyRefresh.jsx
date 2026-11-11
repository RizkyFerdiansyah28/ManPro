import { router } from '@inertiajs/react';
import { useState } from 'react';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function FamilyRefresh({ asOf }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function refresh() {
        setError('');
        const fail = () => { setError('Data belum dapat diperbarui. Coba kembali atau hubungi Admin panti.'); return false; };
        router.reload({ preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <div className="mt-5 flex flex-wrap items-center gap-4"><p className="text-xs text-slate-500">Data dimuat {formatMonitoringTime(asOf)}</p><button type="button" onClick={refresh} disabled={loading} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:opacity-50">{loading ? 'Memuat…' : 'Muat ulang data'}</button>{error && <p role="alert" className="text-xs text-red-800">{error}</p>}</div>;
}

