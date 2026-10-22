import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ServiceBenefits from '../../../components/ServiceBenefits';
import FacilityStatusAction from '../../../components/FacilityStatusAction';
import formatPrice from '../../../utils/formatPrice';

export default function Show({ serviceClass: item }) {
    return (
        <AppLayout>
            <Head title="Detail kelas layanan · Silansia" />
            <Link href="/admin/service-classes" className="text-sm font-semibold text-emerald-800 hover:underline">← Kelas layanan</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">{item.name}</h1>
            <p className="mt-3 text-sm text-slate-500">{item.code} · {item.room_type === 'private' ? 'Kamar pribadi, kapasitas tepat 1' : 'Kamar bersama'} · {item.is_active ? 'Aktif' : 'Nonaktif'}</p>
            {!item.is_canonical && <p role="alert" className="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-800">Konfigurasi kelas tidak sesuai ketentuan. Perubahan diblokir sampai diperiksa operator.</p>}
            <div className="mt-8 grid items-start gap-8 lg:grid-cols-2">
                <section className="space-y-7 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div><h2 className="text-lg font-semibold">Tarif bulanan</h2><p className="mt-4 text-3xl font-bold">{formatPrice(item.monthly_price)}</p><p className="mt-3 text-sm leading-6 text-slate-500">Perubahan tarif hanya berlaku pada konfigurasi kelas. Nominal enrollment dan invoice yang sudah tersimpan tetap dipertahankan.</p></div>
                    <dl className="grid grid-cols-2 gap-5 border-t border-slate-100 pt-6 text-sm">
                        <div><dt className="text-slate-500">Kamar aktif / total</dt><dd className="mt-2 font-semibold">{item.active_rooms_count} / {item.rooms_count}</dd></div>
                        <div><dt className="text-slate-500">Stay aktif</dt><dd className="mt-2 font-semibold">{item.active_stays_count}</dd></div>
                        <div><dt className="text-slate-500">Enrollment aktif</dt><dd className="mt-2 font-semibold">{item.active_enrollments_count}</dd></div>
                        <div><dt className="text-slate-500">Kamera tertaut</dt><dd className="mt-2 font-semibold">{item.cameras_count}</dd></div>
                    </dl>
                    <div className="flex flex-wrap items-start gap-4"><Link href={`/admin/service-classes/${item.id}/edit`} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Edit informasi / tarif</Link><FacilityStatusAction target={item} kind="service-classes" /></div>
                    <Link href={`/admin/rooms?service_class_id=${item.id}`} className="inline-block text-sm font-semibold text-emerald-800 hover:underline">Lihat kamar pada kelas ini →</Link>
                    <p className="text-xs leading-6 text-slate-500">Penonaktifan ditolak selama masih ada kamar aktif, stay aktif, enrollment aktif, atau kamera tertaut. Tidak ada dependensi yang ditutup otomatis.</p>
                </section>
                <ServiceBenefits serviceClass={item} />
            </div>
        </AppLayout>
    );
}
