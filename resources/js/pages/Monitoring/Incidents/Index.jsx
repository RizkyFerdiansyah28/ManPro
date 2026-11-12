import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import OperationPagination from '../../../components/OperationPagination';
import IncidentStatus, { incidentStatuses } from '../../../components/Incidents/IncidentStatus';
import IncidentContext from '../../../components/Incidents/IncidentContext';
import MonitoringSection from '../../../components/Monitoring/MonitoringSection';
import TableFrame from '../../../components/ui/TableFrame';
import EmptyState from '../../../components/ui/EmptyState';
import Button from '../../../components/ui/Button';
import focusFormErrors from '../../../utils/focusFormErrors';
import formatMonitoringTime from '../../../utils/formatMonitoringTime';

export { incidentStatuses };
export default function Index({ records, filters }) {
    const [status, setStatus] = useState(filters.status);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const errors = usePage().props.errors ?? {};
    const id = useId();
    const formRef = useRef(null);
    const errorRef = useRef(null);
    const errorSignature = JSON.stringify(errors);
    useEffect(() => { setStatus(filters.status); setPerPage(filters.per_page); }, [filters.status, filters.per_page]);
    useEffect(() => { if (Object.keys(errors).length) focusFormErrors(formRef.current, errorRef.current, errors); }, [errorSignature]);
    function load(event) {
        event?.preventDefault();
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data belum diperbarui; periksa koneksi lalu coba kembali.'); return false; };
        router.get('/monitoring/incidents', { status, per_page: perPage }, { onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Seluruh insiden · Silansia" />
        <div className="ui-incident-page">
            <header className="ui-incident-page-header"><h1>Seluruh insiden</h1><p>Histori sepanjang waktu, termasuk insiden selesai dan alarm palsu. Pembaruan dilakukan secara manual.</p><Link href="/monitoring/open-incidents" className="ui-monitoring-link">Lihat antrean terbuka</Link></header>
            <form ref={formRef} onSubmit={load} aria-busy={loading} className="ui-incident-filter-bar">
                <div className="ui-field-group"><label htmlFor={id + '-status'} className="ui-field-label">Status</label>
                    <select id={id + '-status'} name="status" value={status} onChange={(event) => setStatus(event.target.value)} aria-invalid={Boolean(errors.status)} aria-describedby={errors.status ? id + '-status-error' : undefined} className="ui-field-control"><option value="">Semua status</option>{Object.entries(incidentStatuses).map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select>
                    {errors.status && <p id={id + '-status-error'} className="ui-field-error">{errors.status}</p>}
                </div>
                <div className="ui-field-group"><label htmlFor={id + '-per-page'} className="ui-field-label">Per halaman</label>
                    <select id={id + '-per-page'} name="per_page" value={perPage} onChange={(event) => setPerPage(event.target.value)} aria-invalid={Boolean(errors.per_page)} aria-describedby={errors.per_page ? id + '-per-page-error' : undefined} className="ui-field-control">{[15, 30, 50].map((n) => <option key={n}>{n}</option>)}</select>
                    {errors.per_page && <p id={id + '-per-page-error'} className="ui-field-error">{errors.per_page}</p>}
                </div>
                <Button type="submit" processing={loading}>{loading ? 'Memuat…' : 'Terapkan / muat ulang'}</Button>
            </form>
            {error && <p role="alert" className="ui-incident-error">{error}</p>}
            {Object.keys(errors).length > 0 && <div ref={errorRef} role="alert" tabIndex={-1} className="ui-incident-error">{Object.entries(errors).map(([key, message]) => <p key={key}>{message}</p>)}</div>}
            <MonitoringSection title="Kejadian terbaru" description="Urutan kejadian terbaru, sesuai data tersimpan.">
                {!records.data.length ? <EmptyState title="Tidak ada insiden pada halaman dan filter ini." /> : <TableFrame label="Insiden seluruh waktu">
                    <table className="ui-incident-table"><caption className="sr-only">Insiden seluruh waktu</caption>
                        <thead><tr>{['Kejadian', 'Kamera / lokasi historis', 'Status / identitas', 'Detail'].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
                        <tbody>{records.data.map((record) => <tr key={record.id}>
                            <td><time dateTime={record.occurred_at} className="ui-incident-time">{formatMonitoringTime(record.occurred_at)}</time></td>
                            <td><p className="ui-incident-entity">{record.camera_name}</p><IncidentContext context={record.context} /></td>
                            <td><IncidentStatus status={record.status} /><p className="ui-incident-table__note">{record.is_verified ? 'Jatuh terverifikasi' : 'Belum/tidak terverifikasi'}</p><p className="ui-incident-table__note">{record.identity_name || 'Identitas belum diketahui'}</p></td>
                            <td><Link href={record.detail_url} className="ui-monitoring-link" aria-label={'Detail insiden ' + formatMonitoringTime(record.occurred_at)}>Buka detail</Link></td>
                        </tr>)}</tbody>
                    </table>
                </TableFrame>}
                <div className="ui-monitoring-pagination"><OperationPagination page={records} label="insiden" /></div>
            </MonitoringSection>
        </div>
    </AppLayout>;
}
