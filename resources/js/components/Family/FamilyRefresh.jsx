import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Button from '../ui/Button';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function FamilyRefresh({ asOf }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const errorRef = useRef(null);
    useEffect(() => { if (error) errorRef.current?.focus(); }, [error]);
    function refresh() {
        setError('');
        const fail = () => { setError('Data belum dapat diperbarui. Coba kembali atau hubungi Admin panti.'); return false; };
        router.reload({ preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    const validTime = asOf && !Number.isNaN(new Date(asOf).getTime());
    return <div className="ui-family-refresh" aria-busy={loading || undefined}>
        <p>Data dimuat {validTime ? <time dateTime={asOf}>{formatMonitoringTime(asOf)}</time> : formatMonitoringTime(asOf)}</p>
        <Button type="button" variant="secondary" onClick={refresh} processing={loading} disabled={loading}>{loading ? 'Memuat…' : 'Muat ulang data'}</Button>
        {error && <p ref={errorRef} tabIndex={-1} role="alert" className="ui-family-notice ui-family-notice--error">{error}</p>}
    </div>;
}

