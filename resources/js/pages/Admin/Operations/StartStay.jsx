import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import OperationPagination from '../../../components/OperationPagination';

export default function StartStay({ resident, operations, rooms, filters }) {
    const filter = useForm({ search: filters.search });
    const form = useForm({ room_id: '', enrollment_id: operations.enrollment?.id ?? '', confirm_start: false });
    const blocked = !resident.is_active || !operations.enrollment || Boolean(operations.stay);
    function search(event) { event.preventDefault(); filter.get(`/admin/residents/${resident.id}/stays/create`); }
    function save(event) { event.preventDefault(); form.post(`/admin/residents/${resident.id}/stays`); }
    return <AppLayout>
        <Head title="Mulai masa tinggal · Silansia" />
        <Link href={`/admin/residents/${resident.id}/operations`} className="text-sm font-semibold text-emerald-800 hover:underline">← Masa tinggal dan layanan</Link>
        <h1 className="mt-5 text-3xl font-bold">Mulai masa tinggal</h1><p className="mt-3 text-slate-600">{resident.name} · {operations.enrollment?.service_class.name ?? 'Belum ada enrollment aktif'}</p>
        {blocked && <p role="alert" className="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Lansia harus aktif, memiliki enrollment aktif, dan belum mempunyai masa tinggal aktif.</p>}
        <form onSubmit={search} className="mt-8 flex max-w-2xl flex-wrap items-end gap-4"><div className="grow"><label htmlFor="search" className="mb-2 block text-sm font-semibold">Cari kode / nama kamar</label><input id="search" type="search" maxLength={100} value={filter.data.search} onChange={(event) => filter.setData('search', event.target.value)} className="w-full rounded-xl border border-slate-300 p-3 text-sm" /></div><button disabled={filter.processing} className="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">{filter.processing ? 'Mencari…' : 'Cari'}</button></form>
        {Object.values(filter.errors).length > 0 && <p role="alert" className="mt-3 text-sm text-red-700">{Object.values(filter.errors).join(' ')}</p>}
        <form onSubmit={save} className="mt-6 max-w-3xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <fieldset disabled={blocked || form.processing}><legend className="mb-4 font-semibold">Kamar aktif sesuai kelas enrollment</legend>
                {rooms.data.length ? <div className="space-y-3">{rooms.data.map((room) => <label key={room.id} className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm"><input type="radio" name="room_id" required value={room.id} checked={form.data.room_id === room.id} disabled={room.available === 0 || !room.eligible_capacity} onChange={() => form.setData('room_id', room.id)} className="mt-1 accent-emerald-800" /><span><strong>{room.code} · {room.name}</strong><span className="mt-2 block text-slate-500">Penghuni aktif {room.occupancy} / {room.capacity} · Tersedia {room.available}{!room.eligible_capacity ? ' · Kapasitas perlu diperiksa operator' : ''}</span></span></label>)}</div> : <p className="text-sm text-slate-500">Belum ada kamar aktif yang sesuai. Periksa kelas, status kamar, atau pencarian.</p>}
            </fieldset>
            <OperationPagination page={rooms} label="pilihan kamar" />
            <p className="text-xs leading-6 text-slate-500">Ketersediaan diperiksa ulang saat disimpan. Penghuni tidak dipindahkan dari kamar lain secara otomatis. Waktu mulai menggunakan waktu server.</p>
            <label className="flex items-start gap-3 text-sm"><input type="checkbox" required checked={form.data.confirm_start} onChange={(event) => form.setData('confirm_start', event.target.checked)} className="mt-1 accent-emerald-800" /><span>Saya mengonfirmasi penempatan pada kamar yang dipilih.</span></label>
            {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700">{Object.values(form.errors).join(' ')}</p>}
            <button disabled={blocked || form.processing || !form.data.room_id} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Mulai masa tinggal'}</button>
        </form>
    </AppLayout>;
}
