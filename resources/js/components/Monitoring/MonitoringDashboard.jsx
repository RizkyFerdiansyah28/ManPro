import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import StatusBadge from '../ui/StatusBadge';
import MonitoringHeader from './MonitoringHeader';
import MonitoringFilters from './MonitoringFilters';
import MonitoringStats, { CameraAdministration } from './MonitoringStats';
import MonitoringActivityTable from './MonitoringActivityTable';
import MonitoringSection from './MonitoringSection';
import MonitoringContextNote from './MonitoringContextNote';
import { monitoringRangeLabel } from '../../utils/monitoringFilters';

export default function MonitoringDashboard({ filters, as_of, stats, cameras, open_incidents, recent, pending_preview }) {
    const admin = usePage().props.auth.user.role === 'admin';
    return (
        <AppLayout>
            <Head title={'Monitoring ' + (admin ? 'Admin' : 'Perawat') + ' · Silansia'} />
            <div className="ui-monitoring-page">
                <MonitoringHeader title={admin ? 'Ringkasan monitoring keselamatan' : 'Monitoring keselamatan lansia'} description="Pantau rekaman kejadian dan insiden yang tersimpan untuk seluruh kelas layanan. Status pembayaran tidak membatasi monitoring petugas." asOf={as_of}>
                    <StatusBadge tone={admin ? 'neutral' : 'info'}>{admin ? 'Pengawasan Admin · baca saja' : 'Penanganan oleh Perawat melalui detail insiden'}</StatusBadge>
                </MonitoringHeader>
                <OpenIncidents counts={open_incidents} records={pending_preview} admin={admin} />
                <MonitoringSection title="Aktivitas periode terpilih" description={monitoringRangeLabel(filters)} className="ui-monitoring-period">
                    <MonitoringFilters filters={filters} url={admin ? '/admin' : '/perawat'} mode="dashboard" headingLevel={3} />
                    <MonitoringStats stats={stats} />
                </MonitoringSection>
                <MonitoringSection title="Aktivitas terbaru" description="Maksimal 10 rekaman terbaru dalam periode terpilih, berdasarkan waktu kejadian." actions={<Link href={'/monitoring/records?' + new URLSearchParams(filters)} className="ui-monitoring-link">Lihat rekaman</Link>}>
                    <MonitoringActivityTable records={recent} />
                    <MonitoringContextNote />
                </MonitoringSection>
                {admin && <CameraAdministration cameras={cameras} />}
            </div>
        </AppLayout>
    );
}

function OpenIncidents({ counts, records, admin }) {
    return (
        <MonitoringSection title="Antrean insiden terbuka" description="Seluruh waktu. Filter periode aktivitas tidak membatasi antrean ini." tone="attention" actions={<StatusBadge tone="warning">Seluruh waktu</StatusBadge>}>
            <div className="ui-monitoring-queue-counts">
                <Link href="/monitoring/open-incidents?status=pending" className="ui-monitoring-queue-count ui-monitoring-queue-count--pending">
                    <span className="ui-monitoring-queue-count__value">{counts.pending}</span><span><strong>Menunggu tindak lanjut</strong><span className="ui-monitoring-queue-count__hint">Lihat insiden pending</span></span>
                </Link>
                <Link href="/monitoring/open-incidents?status=acknowledged" className="ui-monitoring-queue-count">
                    <span className="ui-monitoring-queue-count__value">{counts.acknowledged}</span><span><strong>Diakui · belum selesai</strong><span className="ui-monitoring-queue-count__hint">Lihat insiden acknowledged</span></span>
                </Link>
            </div>
            <div className="ui-monitoring-queue-intro">
                <h3>Pending tertua</h3>
                <p className="ui-monitoring-copy">Maksimal lima insiden pending, sesuai urutan kejadian tertua. {admin ? 'Admin membaca dan mengawasi; penanganan dilakukan oleh Perawat melalui detail insiden.' : 'Buka detail untuk penanganan manual. Insiden acknowledged tetap tersedia melalui tautan antrean di atas.'}</p>
            </div>
            <MonitoringActivityTable records={records} emptyMessage="Tidak ada insiden pending tercatat. Periksa juga insiden acknowledged yang belum selesai." />
            <MonitoringContextNote />
        </MonitoringSection>
    );
}

export { default as ContextNote } from './MonitoringContextNote';
