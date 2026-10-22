import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResidentDetails from '../../../components/ResidentDetails';

export default function Show({ resident }) {
    const status = useForm({ confirm_status_change: true });
    function changeStatus() {
        if (!window.confirm(`${resident.is_active ? 'Nonaktifkan' : 'Aktifkan'} lansia ${resident.name}? Data dan riwayat tetap disimpan.`)) return;
        status.post(`/admin/residents/${resident.id}/${resident.is_active ? 'deactivate' : 'activate'}`);
    }
    return (
        <AppLayout>
            <Head title="Detail lansia · Silansia" />
            <Link href="/admin/residents" className="text-sm font-semibold text-emerald-800 hover:underline">← Daftar lansia</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Detail lansia</h1>
            <ResidentDetails resident={resident} />
            <div className="mt-6 flex flex-wrap items-center gap-5">
                <Link href={`/admin/residents/${resident.id}/edit`} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Edit biodata / keluarga</Link>
                <button type="button" disabled={status.processing} onClick={changeStatus} className="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50">{status.processing ? 'Memperbarui…' : resident.is_active ? 'Nonaktifkan lansia' : 'Aktifkan lansia'}</button>
            </div>
            <p className="mt-5 text-sm leading-6 text-slate-500">Penonaktifan ditolak bila masih ada masa tinggal atau enrollment aktif. Halaman ini tidak menutup relasi atau mengubah riwayat secara otomatis.</p>
            {Object.values(status.errors).length > 0 && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{Object.values(status.errors).join(' ')}</p>}
        </AppLayout>
    );
}
