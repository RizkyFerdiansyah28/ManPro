import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import RoomFields from '../../../components/RoomFields';

export default function Create({ classes }) {
    const form = useForm({ code: '', name: '', service_class_id: '', capacity: '' });
    function save(event) { event.preventDefault(); form.post('/admin/rooms'); }
    return (
        <AppLayout>
            <Head title="Tambah kamar · Silansia" />
            <Link href="/admin/rooms" className="text-sm font-semibold text-emerald-800 hover:underline">← Daftar kamar</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Tambah kamar</h1>
            <p className="mt-3 text-sm text-slate-500">Kamar baru menggunakan kelas aktif dan disimpan dalam status aktif.</p>
            <form onSubmit={save} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                {!classes.length && <p role="alert" className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Belum ada kelas aktif. Aktifkan kelas layanan sebelum menambah kamar.</p>}
                <RoomFields form={form} classes={classes} />
                <button disabled={form.processing || !classes.length} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Simpan kamar'}</button>
            </form>
        </AppLayout>
    );
}
