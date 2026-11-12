import { Link } from '@inertiajs/react';
import formatMonitoringTime from '../../utils/formatMonitoringTime';
import MonitoringContext from './MonitoringContext';
import TableFrame from '../ui/TableFrame';
import EmptyState from '../ui/EmptyState';
import StatusBadge from '../ui/StatusBadge';

const categories = { walking_normal: 'Berjalan normal', walking_abnormal: 'Berjalan tidak normal', no_walking_detected: 'Tidak terdeteksi aktivitas berjalan' };
const statuses = { pending: 'Menunggu tindak lanjut', acknowledged: 'Diakui · belum selesai', resolved: 'Selesai', false_alarm: 'Alarm palsu' };
const tones = { pending: 'warning', acknowledged: 'info', resolved: 'neutral', false_alarm: 'neutral' };

export default function MonitoringActivityTable({ records, emptyMessage = 'Belum ada rekaman yang sesuai filter.' }) {
    if (!records.length) return <EmptyState title={emptyMessage} description="Halaman ini membaca data tersimpan. Tidak ada event buatan atau pemrosesan video." />;
    return (
        <TableFrame label="Rekaman monitoring">
            <table className="ui-monitoring-table">
                <caption className="sr-only">Rekaman monitoring dengan waktu kejadian dan lokasi historis</caption>
                <thead><tr>{['Waktu · WIB', 'Event / kamera', 'Kamar / kelas historis', 'Rincian tersimpan', 'Identitas'].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
                <tbody>{records.map((record) => <tr key={record.id}>
                    <td>
                        <p className="ui-monitoring-table__label">Kejadian</p>
                        <time dateTime={record.occurred_at} title={record.occurred_at} className="ui-monitoring-table__time">{formatMonitoringTime(record.occurred_at)}</time>
                        <p className="ui-monitoring-table__label ui-monitoring-table__received">Diterima</p>
                        <time dateTime={record.received_at} title={record.received_at} className="ui-monitoring-table__time ui-monitoring-table__time--secondary">{formatMonitoringTime(record.received_at)}</time>
                    </td>
                    <td>
                        <StatusBadge tone={record.event_type === 'fall' ? 'warning' : 'neutral'}>{record.event_type === 'fall' ? 'Fall' : record.event_type === 'gait' ? 'Gait' : 'Jenis event tidak dikenal'}</StatusBadge>
                        <p className="ui-monitoring-table__entity">{record.camera.name}</p>
                        {record.camera.configuration_url && <Link href={record.camera.configuration_url} className="ui-monitoring-link">Konfigurasi saat ini</Link>}
                    </td>
                    <td><MonitoringContext context={record.context} /></td>
                    <td>{record.fall ? <>
                        <StatusBadge tone={tones[record.fall.status] ?? 'neutral'}>{statuses[record.fall.status] ?? 'Status insiden tidak dikenal'}</StatusBadge>
                        <p className="ui-monitoring-table__note">{record.fall.is_verified ? 'Tercatat terverifikasi' : 'Belum/tidak terverifikasi'}</p>
                        {record.fall.verified_at && <time dateTime={record.fall.verified_at} className="ui-monitoring-table__note">{formatMonitoringTime(record.fall.verified_at)}</time>}
                        <Link href={record.fall.detail_url} className="ui-monitoring-link">Detail insiden</Link>
                    </> : record.gait ? <>
                        <p className="ui-monitoring-table__entity">{categories[record.gait.category] ?? 'Kategori gait tidak dikenal'}</p>
                        <p className="ui-monitoring-table__note">Kategori observasi, bukan diagnosis.</p>
                    </> : <p className="ui-monitoring-table__note">Data rincian belum tersedia.</p>}</td>
                    <td>{record.identity.state === 'known' ? <>
                        {record.identity.resident_url ? <Link href={record.identity.resident_url} className="ui-monitoring-link">{record.identity.name}</Link> : <p className="ui-monitoring-table__entity">{record.identity.name}</p>}
                        <p className="ui-monitoring-table__note">{record.identity.basis === 'vip_room_single_resident' ? 'Atribusi berdasarkan kamar VIP' : 'Identitas pada verifikasi tersimpan'}</p>
                    </> : <p className="ui-monitoring-table__note">Identitas belum diketahui</p>}</td>
                </tr>)}</tbody>
            </table>
        </TableFrame>
    );
}
