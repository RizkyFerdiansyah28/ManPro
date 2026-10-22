import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';

export default function Index({ rooms, filters, classes }) {
    const form = useForm({ search: filters.search ?? '', service_class_id: filters.service_class_id ?? '', status: filters.status ?? '' });
    function filter(event) {
        event.preventDefault();
        form.get('/admin/rooms', { preserveState: true, replace: true });
    }
    return (
        <AppLayout>
            <Head title="Kelola kamar · Silansia" />
            <div className="flex flex-wrap items-end justify-between gap-5"><div><p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">Administrasi</p><h1 className="mt-3 text-3xl font-bold tracking-tight">Kelola kamar</h1><p className="mt-3 text-sm text-slate-500">Kelola identitas kamar, kelas layanan, kapasitas, dan status.</p></div><Link href="/admin/rooms/create" className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">+ Tambah kamar</Link></div>
            <section className="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <form onSubmit={filter} className="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]">
                    <div><label htmlFor="room-search" className="mb-2 block text-sm font-semibold">Cari kode / nama</label><input id="room-search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} className="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" /></div>
                    <div><label htmlFor="room-class" className="mb-2 block text-sm font-semibold">Kelas layanan</label><select id="room-class" value={form.data.service_class_id} onChange={(event) => form.setData('service_class_id', event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Semua kelas</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}{!item.is_active ? ' (nonaktif)' : ''}</option>)}</select></div>
                    <div><label htmlFor="room-status" className="mb-2 block text-sm font-semibold">Status</label><select id="room-status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
                    <button disabled={form.processing} className="self-end rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold disabled:opacity-50">{form.processing ? 'Mencari…' : 'Terapkan'}</button>
                    {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700 sm:col-span-2 lg:col-span-4">{Object.values(form.errors).join(' ')}</p>}
                </form>
                {rooms.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Daftar kamar dan okupansi aktif</caption>
                    <thead className="bg-slate-50 text-xs text-slate-500"><tr><th scope="col" className="px-6 py-4">Kamar</th><th scope="col" className="px-6 py-4">Kelas</th><th scope="col" className="px-6 py-4">Penghuni / kapasitas</th><th scope="col" className="px-6 py-4">Status</th><th scope="col" className="px-6 py-4">Tindakan</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{rooms.data.map((room) => <tr key={room.id}><td className="px-6 py-5"><p className="font-semibold">{room.code}</p><p className="mt-1 text-xs text-slate-500">{room.name}</p></td><td className="px-6 py-5">{room.service_class.name}<span className="mt-1 block text-xs text-slate-500">{room.service_class.room_type === 'private' ? 'Pribadi' : 'Bersama'}</span></td><td className="px-6 py-5">{room.active_occupancy} / {room.capacity}</td><td className="px-6 py-5"><span className={`rounded-full px-3 py-1 text-xs font-semibold ${room.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'}`}>{room.is_active ? 'Aktif' : 'Nonaktif'}</span></td><td className="px-6 py-5"><Link href={`/admin/rooms/${room.id}`} aria-label={`Lihat kamar ${room.code}`} className="rounded font-semibold text-emerald-800 hover:underline focus-visible:ring-2 focus-visible:ring-emerald-600">Detail</Link></td></tr>)}</tbody>
                </table></div> : <div className="px-6 py-16 text-center"><h2 className="font-semibold">Belum ada kamar yang sesuai</h2><p className="mt-3 text-sm text-slate-500">Ubah pencarian/filter, atau tambahkan kamar baru.</p></div>}
                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-6 py-4 text-xs text-slate-500"><p>{rooms.total} kamar · Halaman {rooms.current_page} dari {rooms.last_page}</p><nav aria-label="Halaman daftar kamar" className="flex gap-4">{rooms.prev_page_url ? <Link href={rooms.prev_page_url} preserveState className="rounded px-2 py-1 font-semibold text-emerald-800">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}{rooms.next_page_url ? <Link href={rooms.next_page_url} preserveState className="rounded px-2 py-1 font-semibold text-emerald-800">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}</nav></div>
            </section>
        </AppLayout>
    );
}
