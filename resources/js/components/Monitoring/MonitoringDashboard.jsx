import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import MonitoringHeader from './MonitoringHeader';
import MonitoringFilters from './MonitoringFilters';
import MonitoringStats from './MonitoringStats';
import MonitoringActivityTable from './MonitoringActivityTable';

export default function MonitoringDashboard({ filters, as_of, stats, cameras, open_incidents, recent, pending_preview }) {
    const admin = usePage().props.auth.user.role === 'admin';
    return <AppLayout><Head title={'Monitoring ' + (admin ? 'Admin' : 'Perawat') + ' · Silansia'} />
        <MonitoringHeader title={admin ? 'Ringkasan monitoring keselamatan' : 'Monitoring keselamatan lansia'} description="Pantau rekaman kejadian dan insiden yang tersimpan untuk seluruh kelas layanan. Status pembayaran tidak membatasi monitoring petugas." asOf={as_of} />
        <MonitoringFilters filters={filters} url={admin ? '/admin' : '/perawat'} mode="dashboard" />
        {!admin && <OpenIncidents counts={open_incidents} records={pending_preview} />}
        <MonitoringStats stats={stats} cameras={cameras} admin={admin} />
        {admin && <OpenIncidents counts={open_incidents} records={pending_preview} />}
        <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"><div className="mb-5 flex flex-wrap items-center justify-between gap-4"><h2 className="text-lg font-semibold">Aktivitas terbaru · periode terpilih</h2><Link href={'/monitoring/records?' + new URLSearchParams(filters)} className="text-sm font-semibold text-emerald-800 hover:underline">Lihat rekaman →</Link></div><MonitoringActivityTable records={recent} /><ContextNote /></section>
    </AppLayout>;
}
function OpenIncidents({ counts, records }) {
    return <section className="my-8 rounded-2xl border border-amber-200 bg-white p-5 sm:p-6"><p className="text-xs font-semibold uppercase tracking-wider text-amber-900">Insiden terbuka · seluruh waktu</p><div className="mt-3 flex flex-wrap gap-x-8 gap-y-3 text-sm"><Link href="/monitoring/open-incidents?status=pending" className="font-semibold text-amber-900 hover:underline">{counts.pending} menunggu tindak lanjut →</Link><Link href="/monitoring/open-incidents?status=acknowledged" className="font-semibold text-emerald-800 hover:underline">{counts.acknowledged} diakui · belum selesai →</Link></div><p className="my-5 text-xs leading-6 text-slate-500">Lima pending tertua ditampilkan. Filter waktu di atas tidak menyembunyikan insiden lama. Tindakan penanganan belum tersedia pada fase ini.</p><MonitoringActivityTable records={records} emptyMessage="Tidak ada insiden pending tercatat. Periksa juga insiden acknowledged yang belum selesai." /><ContextNote /></section>;
}
export function ContextNote() {
    return <p className="mt-5 text-xs leading-6 text-slate-500">Asosiasi kamar/kelas berasal dari periode kejadian; nama kamera, kode/nama kamar, dan nama kelas memakai label master saat ini. Histori legacy tidak diberi lokasi perkiraan. Tidak ada streaming, identifikasi visual, atau pembaruan otomatis.</p>;
}
