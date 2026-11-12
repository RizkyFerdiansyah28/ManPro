import EmptyState from '../ui/EmptyState';
import OperationPagination from '../OperationPagination';
import { incidentStatuses } from './IncidentStatus';
import { incidentActionTitles } from '../../utils/incidentForm';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function IncidentTimeline({ journal }) {
    return <>
        {!journal.data.length ? <EmptyState title="Belum ada tindakan pada halaman jurnal ini." description="Histori sebelum jurnal tersedia tidak dibuat ulang." /> : <ol className="ui-incident-timeline">
            {journal.data.map((entry) => <li key={entry.id}>
                <div className="ui-incident-timeline__marker" aria-hidden="true">{entry.sequence}</div>
                <div className="ui-incident-timeline__entry">
                    <h3><span className="sr-only">Urutan {entry.sequence}. </span>{incidentActionTitles[entry.action] ?? 'Tindakan tidak dikenal'}</h3>
                    <p className="ui-incident-timeline__actor">{entry.actor_name || 'Nama petugas tidak tersedia'}</p>
                    <time dateTime={entry.acted_at} className="ui-incident-time">{formatMonitoringTime(entry.acted_at)}</time>
                    <p className="ui-monitoring-copy">{incidentStatuses[entry.from_status] ?? 'Status tidak dikenal'} → {incidentStatuses[entry.to_status] ?? 'Status tidak dikenal'}</p>
                    {entry.action === 'verify' && <p className="ui-incident-timeline__identity">{entry.identity_name || 'Identitas belum diketahui'}</p>}
                    {entry.notes && <p className="ui-incident-notes">{entry.notes}</p>}
                </div>
            </li>)}
        </ol>}
        <div className="ui-monitoring-pagination"><OperationPagination page={journal} label="jurnal tindakan" /></div>
    </>;
}
