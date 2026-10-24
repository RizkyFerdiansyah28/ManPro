import { useForm } from '@inertiajs/react';

export default function CameraStatusAction({ camera }) {
    const form = useForm({ confirm_status_change: true });
    function update() {
        if (!window.confirm(`${camera.is_active ? 'Nonaktifkan' : 'Aktifkan'} status administrasi ${camera.name}? Asosiasi kamar dan riwayat tetap disimpan. Ini bukan uji koneksi kamera.`)) return;
        form.transform((data) => ({ ...data, version: camera.version }));
        form.post(`/admin/cameras/${camera.id}/${camera.is_active ? 'deactivate' : 'activate'}`);
    }
    return <div className="space-y-3"><button type="button" onClick={update} disabled={form.processing} className="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold disabled:opacity-50">{form.processing ? 'Memperbarui…' : camera.is_active ? 'Nonaktifkan kamera' : 'Aktifkan kamera'}</button>{Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700">{Object.values(form.errors).join(' ')}</p>}</div>;
}
