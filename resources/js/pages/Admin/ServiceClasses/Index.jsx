import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import formatPrice from '../../../utils/formatPrice';

export default function Index({ classes }) {
    return (
        <AppLayout>
            <Head title="Kelas layanan · Silansia" />
            <p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">Administrasi</p>
            <h1 className="mt-3 text-3xl font-bold tracking-tight">Kelas layanan</h1>
            <p className="mt-3 text-sm text-slate-500">Atur nama, tarif bulanan, dan ketersediaan tiga kelas layanan.</p>
            {classes.length ? <div className="mt-8 grid gap-6 md:grid-cols-3">{classes.map((item) => (
                <section key={item.id} className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div className="flex flex-wrap items-center justify-between gap-3"><span className="text-xs font-semibold uppercase text-emerald-800">{item.code}</span><span className={`rounded-full px-3 py-1 text-xs font-semibold ${item.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'}`}>{item.is_active ? 'Aktif' : 'Nonaktif'}</span></div>
                    <h2 className="mt-6 text-xl font-semibold">{item.name}</h2>
                    <p className="mt-2 text-sm text-slate-500">Kamar {item.room_type === 'private' ? 'pribadi, kapasitas 1' : 'bersama'}</p>
                    <p className="mt-7 text-2xl font-bold tracking-tight">{formatPrice(item.monthly_price)}</p><p className="mt-2 text-xs text-slate-500">Tarif per bulan</p>
                    <p className="mt-6 text-sm text-slate-600">{item.active_rooms_count} kamar aktif dari {item.rooms_count} kamar</p>
                    {!item.is_canonical && <p role="alert" className="mt-4 text-sm text-red-700">Konfigurasi manfaat perlu diperiksa operator.</p>}
                    <Link href={`/admin/service-classes/${item.id}`} className="mt-7 self-start rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Lihat detail</Link>
                </section>
            ))}</div> : <p className="mt-8 rounded-2xl border border-slate-200 bg-white p-8 text-sm text-slate-500">Belum ada kelas layanan. Hubungi operator untuk menyiapkan data kelas resmi.</p>}
        </AppLayout>
    );
}
