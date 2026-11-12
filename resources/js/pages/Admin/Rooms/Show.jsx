import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FacilityStatusAction from '../../../components/FacilityStatusAction';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';

export default function Show({ room }) {
    return (
        <ManagementLayout>
            <Head title="Detail kamar · Silansia" />
            <ManagementPageHeader><Link href="/admin/rooms" className="ui-management-link">← Daftar kamar</Link>
            <h1 className="ui-management-title">{room.name}</h1><p className="mt-3 text-sm text-ui-text-secondary">{room.code} · {room.is_active ? 'Aktif' : 'Nonaktif'}</p></ManagementPageHeader>
            <section className="ui-management-panel">
                <h2 className="ui-management-section-title">Informasi kamar</h2>
                <dl className="ui-management-facts">
                    <div><dt className="ui-management-facts__label">Kelas layanan</dt><dd className="ui-management-facts__value"><Link href={`/admin/service-classes/${room.service_class.id}`} className="ui-management-link">{room.service_class.name}</Link>{!room.service_class.is_active && <span className="mt-1 block text-xs text-amber-800">Kelas nonaktif</span>}</dd></div>
                    <div><dt className="ui-management-facts__label">Jenis kamar</dt><dd className="ui-management-facts__value">{room.service_class.room_type === 'private' ? 'Pribadi' : 'Bersama'}</dd></div>
                    <div><dt className="ui-management-facts__label">Kapasitas</dt><dd className="ui-management-facts__value">{room.capacity} penghuni</dd></div>
                    <div><dt className="ui-management-facts__label">Penghuni aktif</dt><dd className="ui-management-facts__value">{room.active_occupancy}</dd></div>
                    <div><dt className="ui-management-facts__label">Riwayat masa tinggal</dt><dd className="ui-management-facts__value">{room.has_stay_history ? 'Tersedia' : 'Belum ada'}</dd></div>
                    <div><dt className="ui-management-facts__label">Kamera tertaut</dt><dd className="ui-management-facts__value">{room.has_camera ? 'Ada' : 'Tidak ada'}</dd></div>
                </dl>
            </section>
            <div className="ui-management-actions"><Link href={`/admin/rooms/${room.id}/edit`} className="ui-button ui-button--primary">Edit kamar</Link><FacilityStatusAction target={room} kind="rooms" /></div>
            <p className="mt-5 text-sm leading-6 text-ui-text-secondary">Penonaktifan ditolak bila masih ada stay aktif atau kamera tertaut. Perubahan kelas dibatasi untuk kamar tanpa riwayat stay dan tanpa kamera. Kapasitas tidak boleh kurang dari okupansi aktif.</p>
        </ManagementLayout>
    );
}
