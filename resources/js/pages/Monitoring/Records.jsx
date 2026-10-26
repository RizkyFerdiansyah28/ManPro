import { Head } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import MonitoringHeader from '../../components/Monitoring/MonitoringHeader';
import MonitoringFilters from '../../components/Monitoring/MonitoringFilters';
import MonitoringActivityTable from '../../components/Monitoring/MonitoringActivityTable';
import { ContextNote } from '../../components/Monitoring/MonitoringDashboard';

export default function Records({ records, filters, as_of, classes }) {
    return <AppLayout><Head title="Rekaman monitoring · Silansia" />
        <MonitoringHeader title="Rekaman monitoring" description="Deteksi fall dan observasi gait berdasarkan waktu kejadian. Waktu diterima ditampilkan terpisah; identitas hanya ditampilkan jika atribusinya sah." asOf={as_of} />
        <MonitoringFilters filters={filters} classes={classes} url="/monitoring/records" />
        <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"><h2 className="mb-5 text-lg font-semibold">Hasil rekaman · periode terpilih</h2><MonitoringActivityTable records={records.data} /><OperationPagination page={records} label="rekaman monitoring" />{!records.data.length && records.total > 0 && <p className="mt-4 text-sm text-slate-600">Halaman ini di luar hasil yang tersedia. Gunakan pagination atau terapkan kembali filter.</p>}<ContextNote /></section>
    </AppLayout>;
}
