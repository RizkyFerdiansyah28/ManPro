import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FacilityStatusAction from '../../../components/FacilityStatusAction';

export default function Show({ room }) {
    return (
        <AppLayout>
            <Head title="Detail kamar · Silansia" />
            <Link href="/admin/rooms" className="text-sm font-semibold text-emerald-800 hover:underline">← Daftar kamar</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">{room.name}</h1><p className="mt-3 text-sm text-slate-500">{room.code} · {room.is_active ? 'Aktif' : 'Nonaktif'}</p>
            <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <h2 className="text-lg font-semibold">Informasi kamar</h2>
                <dl className="mt-6 grid gap-6 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">Kelas layanan</dt><dd className="mt-2 font-semibold"><Link href={`/admin/service-classes/${room.service_class.id}`} className="text-emerald-800 hover:underline">{room.service_class.name}</Link>{!room.service_class.is_active && <span className="mt-1 block text-xs text-amber-800">Kelas nonaktif</span>}</dd></div>
                    <div><dt className="text-slate-500">Jenis kamar</dt><dd className="mt-2 font-semibold">{room.service_class.room_type === 'private' ? 'Pribadi' : 'Bersama'}</dd></div>
                    <div><dt className="text-slate-500">Kapasitas</dt><dd className="mt-2 font-semibold">{room.capacity} penghuni</dd></div>
                    <div><dt className="text-slate-500">Penghuni aktif</dt><dd className="mt-2 font-semibold">{room.active_occupancy}</dd></div>
                    <div><dt className="text-slate-500">Riwayat masa tinggal</dt><dd className="mt-2 font-semibold">{room.has_stay_history ? 'Tersedia' : 'Belum ada'}</dd></div>
                    <div><dt className="text-slate-500">Kamera tertaut</dt><dd className="mt-2 font-semibold">{room.has_camera ? 'Ada' : 'Tidak ada'}</dd></div>
                </dl>
            </section>
            <div className="mt-6 flex flex-wrap items-start gap-4"><Link href={`/admin/rooms/${room.id}/edit`} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Edit kamar</Link><FacilityStatusAction target={room} kind="rooms" /></div>
            <p className="mt-5 text-sm leading-6 text-slate-500">Penonaktifan ditolak bila masih ada stay aktif atau kamera tertaut. Perubahan kelas dibatasi untuk kamar tanpa riwayat stay dan tanpa kamera. Kapasitas tidak boleh kurang dari okupansi aktif.</p>
        </AppLayout>
    );
}
