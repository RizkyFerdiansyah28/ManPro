import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import IncidentActions from '../../../components/Incidents/IncidentActions';
import IncidentStatus from '../../../components/Incidents/IncidentStatus';
import IncidentContext from '../../../components/Incidents/IncidentContext';
import IncidentTimeline from '../../../components/Incidents/IncidentTimeline';
import MonitoringSection from '../../../components/Monitoring/MonitoringSection';
import Button from '../../../components/ui/Button';
import StatusBadge from '../../../components/ui/StatusBadge';
import formatMonitoringTime from '../../../utils/formatMonitoringTime';

export default function Show({ incident, actions: journal }) {
    const admin = usePage().props.auth.user.role === 'admin';
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function reload() {
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data belum diperbarui; periksa koneksi lalu coba kembali.'); return false; };
        router.reload({ preserveState: false, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Detail insiden · Silansia" />
        <div className="ui-incident-page">
            <header className="ui-incident-page-header">
                <Link href="/monitoring/incidents" className="ui-monitoring-link">Kembali ke seluruh insiden</Link>
                <div className="ui-incident-page-header__row"><div><h1>Detail insiden</h1><p>Rekaman deteksi AI, verifikasi manual, dan penanganan ditampilkan terpisah.</p></div><Button variant="secondary" onClick={reload} processing={loading}>{loading ? 'Memuat…' : 'Muat ulang detail'}</Button></div>
                <div className="ui-incident-status-line"><IncidentStatus status={incident.status} /><StatusBadge tone={incident.is_verified ? 'info' : 'neutral'}>{incident.is_verified ? 'Jatuh benar terverifikasi manusia' : 'Belum/tidak terverifikasi'}</StatusBadge></div>
            </header>
            {error && <p role="alert" className="ui-incident-error">{error}</p>}
            <div className="ui-incident-detail-grid">
                <div className="ui-incident-fact-panels">
                    <MonitoringSection title="Informasi kejadian" description="Waktu kejadian AI dan waktu diterima gateway bukan waktu yang sama.">
                        <dl className="ui-incident-facts">
                            <div><dt>Kejadian AI</dt><dd><time dateTime={incident.occurred_at}>{formatMonitoringTime(incident.occurred_at)}</time></dd></div>
                            <div><dt>Diterima gateway</dt><dd><time dateTime={incident.received_at}>{formatMonitoringTime(incident.received_at)}</time></dd></div>
                            <div><dt>Kamera</dt><dd>{incident.camera_name}</dd></div>
                            <div><dt>Kamar / kelas historis</dt><dd><IncidentContext context={incident.context} /></dd></div>
                        </dl>
                        <p className="ui-incident-context-note">Lokasi memakai periode operasi saat kejadian. Label nama/kode berasal dari master saat ini. Lokasi legacy tidak ditebak dari posisi kamera sekarang.</p>
                    </MonitoringSection>
                    <MonitoringSection title="Verifikasi" description="Verifikasi manual merupakan atribut terpisah dari status penanganan.">
                        <dl className="ui-incident-facts">
                            <div><dt>Hasil verifikasi</dt><dd>{incident.is_verified ? 'Jatuh benar terverifikasi manusia' : 'Belum/tidak terverifikasi'}</dd></div>
                            <div><dt>Identitas terverifikasi</dt><dd>{incident.identity_name || 'Identitas belum diketahui'}</dd></div>
                            <div><dt>Petugas verifikasi</dt><dd>{incident.verifier_name || 'Belum ada'}</dd></div>
                            <div><dt>Waktu verifikasi</dt><dd><time dateTime={incident.verified_at ?? undefined}>{formatMonitoringTime(incident.verified_at)}</time></dd></div>
                        </dl>
                        <p className="ui-incident-context-note">Identitas belum diketahui tetap sah. Tidak ada identifikasi visual atau kesimpulan klinis pada halaman ini.</p>
                    </MonitoringSection>
                    <MonitoringSection title="Penanganan">
                        <dl className="ui-incident-facts">
                            <div><dt>Status saat ini</dt><dd><IncidentStatus status={incident.status} /></dd></div>
                            <div><dt>Petugas acknowledge pertama</dt><dd>{incident.responder_name || 'Belum ada'}</dd></div>
                            <div><dt>Waktu acknowledge</dt><dd><time dateTime={incident.acknowledged_at ?? undefined}>{formatMonitoringTime(incident.acknowledged_at)}</time></dd></div>
                            <div><dt>Waktu penutupan</dt><dd><time dateTime={incident.resolved_at ?? undefined}>{formatMonitoringTime(incident.resolved_at)}</time></dd></div>
                        </dl>
                        {incident.legacy_notes && <div className="ui-incident-legacy-notes"><h3>Catatan legacy tersimpan</h3><p className="ui-incident-notes">{incident.legacy_notes}</p></div>}
                    </MonitoringSection>
                </div>
                <div className="ui-incident-action-panel"><MonitoringSection title={admin ? 'Akses penanganan' : 'Tindakan Perawat'}>
                    <IncidentActions key={incident.id + ':' + (incident.version || incident.status)} incident={incident} />
                </MonitoringSection></div>
            </div>
            <MonitoringSection title="Jurnal tindakan" description="Urutan tindakan tersimpan. Histori sebelum jurnal tersedia tidak dibuat ulang.">
                <IncidentTimeline journal={journal} />
            </MonitoringSection>
        </div>
    </AppLayout>;
}
