import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import CameraFields from '../../../components/CameraFields';

export default function Edit({ camera }) {
    const form = useForm({ room_id: camera.room.id, current_room_id: camera.room.id, name: camera.name, source_type: camera.source_type,
        source_reference: '', source_action: 'keep', version: camera.version, confirm_reassignment: false });
    function save(event) {
        event.preventDefault();
        if (form.data.room_id !== camera.room.id && !window.confirm('Pindahkan kamera ke kamar yang dipilih? Kamera harus nonaktif dan belum mempunyai deteksi.')) return;
        form.patch(`/admin/cameras/${camera.id}`, { onError: () => form.reset('source_reference'), onSuccess: () => form.reset('source_reference') });
    }
    return <AppLayout><Head title="Edit kamera · Silansia" /><Link href={`/admin/cameras/${camera.id}`} className="text-sm font-semibold text-emerald-800 hover:underline">← Detail kamera</Link><h1 className="mt-5 text-3xl font-bold">Edit konfigurasi kamera</h1><p className="mt-3 text-sm text-slate-500">Nilai sumber tersimpan tidak dimuat kembali. Gunakan tindakan ganti atau kosongkan secara eksplisit.</p>
        <form onSubmit={save} className="mt-8 max-w-3xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8"><CameraFields form={form} camera={camera} />{form.errors.version && <button type="button" onClick={() => window.location.reload()} className="text-sm font-semibold text-emerald-800 underline">Muat ulang konfigurasi terbaru</button>}<button disabled={form.processing} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</button></form>
    </AppLayout>;
}
