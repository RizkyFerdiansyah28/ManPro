import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import RoomFields from '../../../components/RoomFields';

export default function Edit({ room, classes }) {
    const form = useForm({ code: room.code, name: room.name, service_class_id: room.service_class.id,
        current_service_class_id: room.service_class.id, capacity: String(room.capacity), confirm_class_change: false });
    function save(event) {
        event.preventDefault();
        if (form.data.service_class_id !== room.service_class.id && !window.confirm(`Ubah kelas kamar ${room.code}? Pastikan pilihan kelas dan kapasitas sudah sesuai.`)) return;
        form.patch(`/admin/rooms/${room.id}`);
    }
    return (
        <AppLayout>
            <Head title="Edit kamar · Silansia" />
            <Link href={`/admin/rooms/${room.id}`} className="text-sm font-semibold text-emerald-800 hover:underline">← Detail kamar</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Edit kamar</h1>
            <p className="mt-3 text-sm text-slate-500">Perubahan informasi kamar mempertahankan status dan seluruh riwayat.</p>
            <form onSubmit={save} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <RoomFields form={form} classes={classes} room={room} />
                <button disabled={form.processing} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</button>
            </form>
        </AppLayout>
    );
}
