import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Button from '../ui/Button';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function MonitoringHeader({ title, description, asOf, children }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function reload() {
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data di bawah belum diperbarui. Periksa koneksi lalu coba kembali.'); return false; };
        router.reload({ preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return (
        <header className="ui-monitoring-header">
            <div className="ui-monitoring-header__row">
                <div className="ui-monitoring-header__content">
                    <h1>{title}</h1>
                    <p className="ui-monitoring-header__description">{description}</p>
                    {children && <div className="ui-monitoring-header__context">{children}</div>}
                </div>
                <Button variant="secondary" onClick={reload} processing={loading}>{loading ? 'Memuat…' : 'Muat ulang data'}</Button>
            </div>
            <p className="ui-monitoring-header__timestamp" role="status">{loading ? 'Memuat pembaruan… ' : 'Data dimuat: '}<time dateTime={asOf}>{formatMonitoringTime(asOf)}</time><span>Pembaruan manual.</span></p>
            {error && <p role="alert" className="ui-monitoring-error">{error}</p>}
            <nav aria-label="Halaman monitoring" className="ui-monitoring-links">
                <Link href="/monitoring/records" className="ui-monitoring-link">Semua rekaman</Link>
                <Link href="/monitoring/open-incidents" className="ui-monitoring-link">Insiden terbuka · seluruh waktu</Link>
                <Link href="/monitoring/incidents" className="ui-monitoring-link">Seluruh insiden</Link>
            </nav>
        </header>
    );
}
