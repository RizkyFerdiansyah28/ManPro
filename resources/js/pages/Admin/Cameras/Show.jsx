import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import CameraStatusAction from '../../../components/CameraStatusAction';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';

const types = { webcam: 'Webcam browser', uploaded_video: 'Berkas video privat', rtsp: 'Referensi RTSP' };
export default function Show({ camera }) {
    return <ManagementLayout>
        <Head title="Detail kamera · Silansia" />
        <ManagementPageHeader><Link href="/admin/cameras" className="ui-management-link">← Daftar kamera</Link>
        <h1 className="ui-management-title">{camera.name}</h1></ManagementPageHeader>
        <section className="ui-management-panel">
            <h2 className="ui-management-section-title">Informasi kamera</h2>
            <dl className="ui-management-facts"><div><dt className="ui-management-facts__label">Kamar</dt><dd className="ui-management-facts__value">{camera.room.code} · {camera.room.name}</dd><dd className="ui-management-facts__value">{camera.room.is_active ? 'Kamar aktif' : 'Kamar nonaktif; asosiasi dipertahankan'}</dd></div><div><dt className="ui-management-facts__label">Kelas</dt><dd className="ui-management-facts__value">{camera.room.service_class.name}</dd></div><div><dt className="ui-management-facts__label">Jenis sumber</dt><dd className="ui-management-facts__value">{types[camera.source_type] ?? 'Jenis sumber tidak dikenali'}</dd></div><div><dt className="ui-management-facts__label">Status administrasi</dt><dd className="ui-management-facts__value">{camera.is_active ? 'Aktif' : 'Nonaktif'}</dd></div><div><dt className="ui-management-facts__label">Sumber privat</dt><dd className="ui-management-facts__value">{camera.has_source_reference ? 'Sudah diatur · nilai disembunyikan' : camera.source_type === 'webcam' ? 'Tidak diperlukan untuk webcam' : 'Belum diatur'}</dd></div><div><dt className="ui-management-facts__label">Deteksi historis tertaut</dt><dd className="ui-management-facts__value">{camera.detections_count}</dd></div></dl>
            <p className="ui-management-note">Status administrasi tidak menyatakan kamera online. Tidak ada koneksi, streaming, perekaman, atau pemrosesan AI yang dijalankan oleh halaman ini.</p>
            <div className="ui-management-actions"><Link href={`/admin/cameras/${camera.id}/edit`} className="ui-button ui-button--primary">Edit konfigurasi</Link><CameraStatusAction camera={camera} /></div>
            <Link href={`/admin/rooms/${camera.room.id}`} className="ui-management-link">Detail kamar →</Link>
            <p className="text-xs leading-6 text-ui-text-secondary">Kamera tetap menghalangi penonaktifan kamar/kelas meskipun nonaktif. Kamera dengan deteksi tidak dapat dipindah. Akses CCTV keluarga hanya merupakan manfaat VIP dan belum tersedia pada fase ini; pengelolaan kamera petugas berlaku untuk semua kelas.</p>
        </section>
    </ManagementLayout>;
}
