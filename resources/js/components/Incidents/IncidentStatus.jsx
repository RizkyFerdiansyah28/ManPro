import StatusBadge from '../ui/StatusBadge';

export const incidentStatuses = { pending: 'Menunggu tindak lanjut', acknowledged: 'Diakui · belum selesai', resolved: 'Selesai', false_alarm: 'Alarm palsu' };
const tones = { pending: 'warning', acknowledged: 'info', resolved: 'neutral', false_alarm: 'neutral' };

export default function IncidentStatus({ status }) {
    return <StatusBadge tone={tones[status] ?? 'neutral'}>{incidentStatuses[status] ?? 'Status insiden tidak dikenal'}</StatusBadge>;
}
