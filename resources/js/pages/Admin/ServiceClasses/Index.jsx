import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import StatusBadge from '../../../components/ui/StatusBadge';
import formatPrice from '../../../utils/formatPrice';

export default function Index({ classes }) {
    return (
        <ManagementLayout>
            <Head title="Kelas layanan · Silansia" />
            <ManagementPageHeader>
            <h1 className="ui-management-title">Kelas layanan</h1>
            <p className="mt-3 text-sm text-ui-text-secondary">Atur nama, tarif bulanan, dan ketersediaan tiga kelas layanan.</p></ManagementPageHeader>
            {classes.length ? <div className="ui-management-class-grid">{classes.map((item) => (
                <section key={item.id} className="ui-management-panel ui-management-class-card">
                    <div className="ui-management-actions"><span className="text-xs font-semibold uppercase text-ui-primary">{item.code}</span><StatusBadge tone={item.is_active ? 'positive' : 'neutral'}>{item.is_active ? 'Aktif' : 'Nonaktif'}</StatusBadge></div>
                    <h2 className="ui-management-section-title">{item.name}</h2>
                    <p className="mt-2 text-sm text-ui-text-secondary">Kamar {item.room_type === 'private' ? 'pribadi, kapasitas 1' : 'bersama'}</p>
                    <p className="mt-7 text-2xl font-bold tracking-tight">{formatPrice(item.monthly_price)}</p><p className="mt-2 text-xs text-ui-text-secondary">Tarif per bulan</p>
                    <p className="mt-6 text-sm text-ui-neutral">{item.active_rooms_count} kamar aktif dari {item.rooms_count} kamar</p>
                    {!item.is_canonical && <p role="alert" className="ui-management-note ui-management-note--danger">Konfigurasi manfaat perlu diperiksa operator.</p>}
                    <Link href={`/admin/service-classes/${item.id}`} aria-label={`Lihat kelas layanan ${item.name}`} className="ui-button ui-button--primary">Lihat detail</Link>
                </section>
            ))}</div> : <p className="mt-8 rounded-2xl border border-slate-200 bg-white p-8 text-sm text-ui-text-secondary">Belum ada kelas layanan. Hubungi operator untuk menyiapkan data kelas resmi.</p>}
        </ManagementLayout>
    );
}
