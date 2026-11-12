import { Head } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import MonitoringHeader from '../../components/Monitoring/MonitoringHeader';
import MonitoringFilters from '../../components/Monitoring/MonitoringFilters';
import MonitoringActivityTable from '../../components/Monitoring/MonitoringActivityTable';
import MonitoringSection from '../../components/Monitoring/MonitoringSection';
import MonitoringContextNote from '../../components/Monitoring/MonitoringContextNote';
import StatusBadge from '../../components/ui/StatusBadge';

export default function OpenIncidents({ records, filters, as_of, classes }) {
    return (
        <AppLayout>
            <Head title="Insiden terbuka · Silansia" />
            <div className="ui-monitoring-page">
                <MonitoringHeader title="Insiden terbuka" description="Insiden pending dan acknowledged yang belum selesai, dari seluruh waktu. Buka detail insiden untuk verifikasi dan penyelesaian manual oleh Perawat." asOf={as_of}>
                    <StatusBadge tone="warning">Seluruh waktu · kejadian tertua lebih dahulu</StatusBadge>
                </MonitoringHeader>
                <MonitoringFilters filters={filters} classes={classes} url="/monitoring/open-incidents" mode="open" />
                <MonitoringSection title="Antrean insiden" description="Urutan kejadian tertua · seluruh waktu" tone="attention">
                    <MonitoringActivityTable records={records.data} emptyMessage="Tidak ada insiden terbuka yang sesuai filter." />
                    <div className="ui-monitoring-pagination"><OperationPagination page={records} label="insiden terbuka" /></div>
                    {!records.data.length && records.total > 0 && <p className="ui-monitoring-copy ui-monitoring-result-note">Halaman ini di luar hasil yang tersedia. Terapkan kembali filter untuk kembali ke halaman pertama.</p>}
                    <MonitoringContextNote />
                </MonitoringSection>
            </div>
        </AppLayout>
    );
}
