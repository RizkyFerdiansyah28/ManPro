import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FacilityStatusAction from '../../../components/FacilityStatusAction';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import ServiceBenefits from '../../../components/ServiceBenefits';
import formatPrice from '../../../utils/formatPrice';

export default function Show({ serviceClass: item }) {
    return (
        <ManagementLayout>
            <Head title="Detail kelas layanan · Silansia" />
            <ManagementPageHeader><Link href="/admin/service-classes" className="ui-management-link">← Kelas layanan</Link>
            <h1 className="ui-management-title">{item.name}</h1>
            <p className="mt-3 text-sm text-ui-text-secondary">{item.code} · {item.room_type === 'private' ? 'Kamar pribadi, kapasitas tepat 1' : 'Kamar bersama'} · {item.is_active ? 'Aktif' : 'Nonaktif'}</p></ManagementPageHeader>
            {!item.is_canonical && <p role="alert" className="ui-management-note ui-management-note--danger">Konfigurasi kelas tidak sesuai ketentuan. Perubahan diblokir sampai diperiksa operator.</p>}
            <div className="ui-management-two-column">
                <section className="ui-management-panel">
                    <div><h2 className="ui-management-section-title">Tarif bulanan</h2><p className="mt-4 text-3xl font-bold">{formatPrice(item.monthly_price)}</p><p className="mt-3 text-sm leading-6 text-ui-text-secondary">Perubahan tarif hanya berlaku pada konfigurasi kelas. Nominal enrollment dan invoice yang sudah tersimpan tetap dipertahankan.</p></div>
                    <dl className="ui-management-facts">
                        <div><dt className="ui-management-facts__label">Kamar aktif / total</dt><dd className="ui-management-facts__value">{item.active_rooms_count} / {item.rooms_count}</dd></div>
                        <div><dt className="ui-management-facts__label">Stay aktif</dt><dd className="ui-management-facts__value">{item.active_stays_count}</dd></div>
                        <div><dt className="ui-management-facts__label">Enrollment aktif</dt><dd className="ui-management-facts__value">{item.active_enrollments_count}</dd></div>
                        <div><dt className="ui-management-facts__label">Kamera tertaut</dt><dd className="ui-management-facts__value">{item.cameras_count}</dd></div>
                    </dl>
                    <div className="ui-management-actions"><Link href={`/admin/service-classes/${item.id}/edit`} className="ui-button ui-button--primary">Edit informasi / tarif</Link><FacilityStatusAction target={item} kind="service-classes" /></div>
                    <Link href={`/admin/rooms?service_class_id=${item.id}`} className="ui-management-link">Lihat kamar pada kelas ini →</Link>
                    <p className="text-xs leading-6 text-ui-text-secondary">Penonaktifan ditolak selama masih ada kamar aktif, stay aktif, enrollment aktif, atau kamera tertaut. Tidak ada dependensi yang ditutup otomatis.</p>
                </section>
                <ServiceBenefits serviceClass={item} />
            </div>
        </ManagementLayout>
    );
}
