import { Head } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import OperationPagination from '../../components/OperationPagination';
import MonitoringHeader from '../../components/Monitoring/MonitoringHeader';
import MonitoringFilters from '../../components/Monitoring/MonitoringFilters';
import MonitoringActivityTable from '../../components/Monitoring/MonitoringActivityTable';
import { ContextNote } from '../../components/Monitoring/MonitoringDashboard';

export default function OpenIncidents({ records, filters, as_of, classes }) {
    return <AppLayout><Head title="Insiden terbuka · Silansia" />
        <MonitoringHeader title="Insiden terbuka" description="Insiden pending dan acknowledged yang belum selesai, dari seluruh waktu. Halaman ini hanya membaca record; verifikasi dan penyelesaian belum tersedia." asOf={as_of} />
        <MonitoringFilters filters={filters} classes={classes} url="/monitoring/open-incidents" mode="open" />
        <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"><h2 className="mb-5 text-lg font-semibold">Urutan kejadian tertua · seluruh waktu</h2><MonitoringActivityTable records={records.data} emptyMessage="Tidak ada insiden terbuka yang sesuai filter." /><OperationPagination page={records} label="insiden terbuka" />{!records.data.length && records.total > 0 && <p className="mt-4 text-sm text-slate-600">Halaman ini di luar hasil yang tersedia. Terapkan kembali filter untuk kembali ke halaman pertama.</p>}<ContextNote /></section>
    </AppLayout>;
}
