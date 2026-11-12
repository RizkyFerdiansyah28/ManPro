import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import MonitoringSection from '../../components/Monitoring/MonitoringSection';
import IncidentStatus from '../../components/Incidents/IncidentStatus';
import NotificationReadAction, { notificationReadChanged } from '../../components/Notifications/NotificationReadAction';
import Button from '../../components/ui/Button';
import StatusBadge from '../../components/ui/StatusBadge';
import EmptyState from '../../components/ui/EmptyState';
import focusFormErrors from '../../utils/focusFormErrors';
import formatMonitoringTime from '../../utils/formatMonitoringTime';
import { notificationReadContext, submitNotificationRead } from '../../utils/notificationRead';

export default function Index({ records, filters, as_of, errors = {} }) {
    const [state, setState] = useState(filters.state);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const context = notificationReadContext(filters, records);
    const all = useForm({ ...context, confirm: false });
    const id = useId();
    const filterRef = useRef(null);
    const allRef = useRef(null);
    const summaryRef = useRef(null);
    const allLock = useRef(false);
    const fieldErrors = { ...errors, ...all.errors };
    const signature = JSON.stringify(fieldErrors);
    const fail = () => { setError('Permintaan belum dapat dipastikan. Muat ulang daftar lalu coba kembali.'); return false; };
    useEffect(() => { setState(filters.state); setPerPage(filters.per_page); }, [filters.state, filters.per_page]);
    useEffect(() => {
        if (!Object.keys(fieldErrors).length || all.processing || loading) return;
        focusFormErrors(fieldErrors.confirm ? allRef.current : filterRef.current, summaryRef.current, fieldErrors);
    }, [signature, all.processing, loading]);
    function load(event) {
        event.preventDefault();
        setError('');
        router.get('/notifications', { state, per_page: perPage }, { onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    function readAll(event) {
        event.preventDefault();
        if (all.processing || allLock.current) return;
        setError('');
        submitNotificationRead({
            form: all, lock: allLock, onSuccess: () => { notificationReadChanged(); all.reset('confirm'); },
            onFailure: () => { fail(); },
        });
    }
    return <AppLayout><Head title="Notifikasi saya · Silansia" />
        <div className="ui-incident-page">
            <header className="ui-incident-page-header"><h1>Notifikasi saya</h1><p>Pemberitahuan dugaan jatuh untuk staf. Membaca notifikasi tidak mengakui atau menyelesaikan insiden. Status penanganan dapat berubah setelah notifikasi dibuat.</p><p className="ui-incident-time">Daftar dimuat <time dateTime={as_of}>{formatMonitoringTime(as_of)}</time>. Ringkasan staf diperbarui terpisah; daftar dimuat ulang secara manual.</p></header>
            {error && <p role="alert" className="ui-incident-error">{error}</p>}
            {Object.keys(fieldErrors).length > 0 && <div ref={summaryRef} role="alert" tabIndex={-1} className="ui-incident-error">{[...new Set(Object.values(fieldErrors))].map((message, index) => <p key={index}>{message}</p>)}</div>}
            <form ref={filterRef} onSubmit={load} aria-busy={loading} className="ui-incident-filter-bar">
                <div className="ui-field-group"><label htmlFor={id + '-state'} className="ui-field-label">Status baca</label>
                    <select id={id + '-state'} name="state" value={state} onChange={(event) => setState(event.target.value)} aria-invalid={Boolean(fieldErrors.state)} aria-describedby={fieldErrors.state ? id + '-state-error' : undefined} className="ui-field-control"><option value="all">Semua</option><option value="unread">Belum dibaca</option><option value="read">Sudah dibaca</option></select>
                    {fieldErrors.state && <p id={id + '-state-error'} className="ui-field-error">{fieldErrors.state}</p>}
                </div>
                <div className="ui-field-group"><label htmlFor={id + '-per-page'} className="ui-field-label">Per halaman</label>
                    <select id={id + '-per-page'} name="per_page" value={perPage} onChange={(event) => setPerPage(event.target.value)} aria-invalid={Boolean(fieldErrors.per_page)} aria-describedby={fieldErrors.per_page ? id + '-per-page-error' : undefined} className="ui-field-control">{[15, 30, 50].map((n) => <option key={n}>{n}</option>)}</select>
                    {fieldErrors.per_page && <p id={id + '-per-page-error'} className="ui-field-error">{fieldErrors.per_page}</p>}
                </div>
                <Button type="submit" processing={loading}>{loading ? 'Memuat…' : 'Terapkan / muat ulang daftar'}</Button>
            </form>
            <MonitoringSection title="Inbox insiden" description="Status baca notifikasi terpisah dari status penanganan insiden.">
                {!records.data.length ? <EmptyState title="Tidak ada notifikasi pada halaman dan filter ini." description="Periksa juga antrean insiden terbuka." /> : <ul className="ui-notification-list">{records.data.map((record) => <li key={record.id} className={record.read_at ? 'ui-notification-item' : 'ui-notification-item ui-notification-item--unread'}>
                    <div className="ui-notification-item__content">
                        <StatusBadge tone={record.read_at ? 'neutral' : 'info'}>{record.read_at ? 'Sudah dibaca' : 'Belum dibaca'}</StatusBadge>
                        <p className="ui-notification-message">{record.message}</p>
                        <time dateTime={record.created_at} className="ui-incident-time">{formatMonitoringTime(record.created_at)}</time>
                        {record.incident && <div className="ui-notification-incident">
                            <p className="ui-monitoring-copy">Status insiden saat ini</p><IncidentStatus status={record.incident.status} />
                            <p className="ui-monitoring-copy">{record.incident.is_verified ? 'Terverifikasi manusia' : 'Belum/tidak terverifikasi'}</p>
                            <Link href={record.incident.detail_url} className="ui-monitoring-link">Buka detail insiden</Link>
                        </div>}
                    </div>
                    <NotificationReadAction key={record.id + ':' + (record.read_at || 'unread')} record={record} context={context} />
                </li>)}</ul>}
                <div className="ui-monitoring-pagination"><OperationPagination page={records} label="notifikasi" /></div>
            </MonitoringSection>
            <MonitoringSection title="Tandai seluruh notifikasi saya">
                <form ref={allRef} onSubmit={readAll} aria-busy={all.processing} className="ui-notification-read-all">
                    <label htmlFor={id + '-all-confirm'} className="ui-incident-confirm"><input id={id + '-all-confirm'} name="confirm" type="checkbox" required checked={all.data.confirm} disabled={all.processing} onChange={(event) => all.setData('confirm', event.target.checked)} aria-invalid={Boolean(fieldErrors.confirm)} aria-describedby={fieldErrors.confirm ? id + '-all-confirm-error' : undefined} /><span>Tandai seluruh notifikasi insiden saya yang sudah tersimpan saat tindakan diproses, termasuk halaman dan filter lain, sebagai dibaca.</span></label>
                    {fieldErrors.confirm && <p id={id + '-all-confirm-error'} className="ui-field-error">{fieldErrors.confirm}</p>}
                    <Button type="submit" variant="secondary" processing={all.processing}>{all.processing ? 'Menyimpan…' : 'Tandai semua sebagai dibaca'}</Button>
                </form>
            </MonitoringSection>
        </div>
    </AppLayout>;
}
