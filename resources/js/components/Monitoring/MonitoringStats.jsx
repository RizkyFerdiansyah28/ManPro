import { Link } from '@inertiajs/react';
import StatusBadge from '../ui/StatusBadge';
import MonitoringSection from './MonitoringSection';

const numbers = new Intl.NumberFormat('id-ID');
function count(value) {
    return typeof value === 'number' && Number.isFinite(value) ? numbers.format(value) : 'Tidak tersedia';
}

export default function MonitoringStats({ stats }) {
    return (
        <div className="ui-monitoring-stats">
            <dl className="ui-monitoring-metrics">
                <div><dt>Deteksi fall</dt><dd>{count(stats.fall_detections)}</dd></div>
                <div><dt>Observasi gait</dt><dd>{count(stats.gait_observations)}</dd></div>
            </dl>
            <div className="ui-monitoring-status-summary">
                <h3>Status insiden pada periode terpilih</h3>
                <dl>{[
                    ['Menunggu tindak lanjut', stats.pending], ['Diakui · belum selesai', stats.acknowledged],
                    ['Selesai', stats.resolved], ['Alarm palsu', stats.false_alarm],
                ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{count(value)}</dd></div>)}</dl>
            </div>
            <p className="ui-monitoring-copy ui-monitoring-verification"><StatusBadge tone="info">Tercatat terverifikasi: {count(stats.verified)}</StatusBadge><span>Verifikasi adalah atribut terpisah dari status. Pending bukan fall terkonfirmasi.</span></p>
        </div>
    );
}

export function CameraAdministration({ cameras }) {
    return (
        <MonitoringSection title="Kamera" description="Kondisi administrasi saat ini, terpisah dari periode kejadian." actions={<Link href="/admin/cameras" className="ui-monitoring-link">Kelola kamera</Link>}>
            <dl className="ui-monitoring-camera-counts">{[
                ['Kamera terdaftar', cameras.registered], ['Aktif administrasi', cameras.active], ['Nonaktif administrasi', cameras.inactive],
            ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{count(value)}</dd></div>)}</dl>
            <p className="ui-monitoring-copy">Keaktifan administrasi tidak menunjukkan konektivitas kamera. Ringkasan ini tidak mengikuti filter waktu kejadian.</p>
        </MonitoringSection>
    );
}
