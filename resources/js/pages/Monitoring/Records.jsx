import { Head } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import MonitoringHeader from '../../components/Monitoring/MonitoringHeader';
import MonitoringFilters from '../../components/Monitoring/MonitoringFilters';
import MonitoringActivityTable from '../../components/Monitoring/MonitoringActivityTable';
import MonitoringSection from '../../components/Monitoring/MonitoringSection';
import MonitoringContextNote from '../../components/Monitoring/MonitoringContextNote';
import { monitoringRangeLabel } from '../../utils/monitoringFilters';

export default function Records({ records, filters, as_of, classes }) {
    return (
        <AppLayout>
            <Head title="Rekaman monitoring · Silansia" />
            <div className="ui-monitoring-page">
                <MonitoringHeader title="Rekaman monitoring" description="Deteksi fall dan observasi gait berdasarkan waktu kejadian. Waktu diterima ditampilkan terpisah; identitas hanya ditampilkan jika atribusinya sah." asOf={as_of} />
                <MonitoringFilters filters={filters} classes={classes} url="/monitoring/records" />
                <MonitoringSection title="Hasil rekaman" description={'Periode terpilih: ' + monitoringRangeLabel(filters)}>
                    <MonitoringActivityTable records={records.data} />
                    <div className="ui-monitoring-pagination"><OperationPagination page={records} label="rekaman monitoring" /></div>
                    {!records.data.length && records.total > 0 && <p className="ui-monitoring-copy ui-monitoring-result-note">Halaman ini di luar hasil yang tersedia. Gunakan pagination atau terapkan kembali filter.</p>}
                    <MonitoringContextNote />
                </MonitoringSection>
            </div>
        </AppLayout>
    );
}
