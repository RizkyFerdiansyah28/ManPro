import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import CameraStatusAction from '../../../components/CameraStatusAction';

const types = { webcam: 'Webcam browser', uploaded_video: 'Berkas video privat', rtsp: 'Referensi RTSP' };
export default function Show({ camera }) {
    return <AppLayout>
        <Head title="Detail kamera · Silansia" />
        <Link href="/admin/cameras" className="text-sm font-semibold text-emerald-800 hover:underline">← Daftar kamera</Link>
        <h1 className="mt-5 text-3xl font-bold">{camera.name}</h1>
        <section className="mt-8 max-w-3xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <dl className="grid gap-6 text-sm sm:grid-cols-2"><div><dt className="text-slate-500">Kamar</dt><dd className="mt-2 font-semibold">{camera.room.code} · {camera.room.name}</dd><dd className="mt-1 text-xs text-slate-500">{camera.room.is_active ? 'Kamar aktif' : 'Kamar nonaktif; asosiasi dipertahankan'}</dd></div><div><dt className="text-slate-500">Kelas</dt><dd className="mt-2 font-semibold">{camera.room.service_class.name}</dd></div><div><dt className="text-slate-500">Jenis sumber</dt><dd className="mt-2 font-semibold">{types[camera.source_type]}</dd></div><div><dt className="text-slate-500">Status administrasi</dt><dd className="mt-2 font-semibold">{camera.is_active ? 'Aktif' : 'Nonaktif'}</dd></div><div><dt className="text-slate-500">Sumber privat</dt><dd className="mt-2">{camera.has_source_reference ? 'Sudah diatur · nilai disembunyikan' : camera.source_type === 'webcam' ? 'Tidak diperlukan untuk webcam' : 'Belum diatur'}</dd></div><div><dt className="text-slate-500">Deteksi historis tertaut</dt><dd className="mt-2 font-semibold">{camera.detections_count}</dd></div></dl>
            <p className="rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-600">Status administrasi tidak menyatakan kamera online. Tidak ada koneksi, streaming, perekaman, atau pemrosesan AI yang dijalankan oleh halaman ini.</p>
            <div className="flex flex-wrap items-start gap-4"><Link href={`/admin/cameras/${camera.id}/edit`} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white">Edit konfigurasi</Link><CameraStatusAction camera={camera} /></div>
            <Link href={`/admin/rooms/${camera.room.id}`} className="inline-block text-sm font-semibold text-emerald-800 hover:underline">Detail kamar →</Link>
            <p className="text-xs leading-6 text-slate-500">Kamera tetap menghalangi penonaktifan kamar/kelas meskipun nonaktif. Kamera dengan deteksi tidak dapat dipindah. Akses CCTV keluarga hanya merupakan manfaat VIP dan belum tersedia pada fase ini; pengelolaan kamera petugas berlaku untuk semua kelas.</p>
        </section>
    </AppLayout>;
}
