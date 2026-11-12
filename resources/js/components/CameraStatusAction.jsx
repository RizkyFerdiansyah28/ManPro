import { useForm } from '@inertiajs/react';
import ManagementActionFeedback from './Management/ManagementActionFeedback';
import Button from './ui/Button';

export default function CameraStatusAction({ camera }) {
    const form = useForm({ confirm_status_change: true });
    function update() {
        if (form.processing) return;
        if (!window.confirm(`${camera.is_active ? 'Nonaktifkan' : 'Aktifkan'} status administrasi ${camera.name}? Asosiasi kamar dan riwayat tetap disimpan. Ini bukan uji koneksi kamera.`)) return;
        form.transform((data) => ({ ...data, version: camera.version }));
        form.post(`/admin/cameras/${camera.id}/${camera.is_active ? 'deactivate' : 'activate'}`);
    }
    return <div className="space-y-3"><Button type="button" variant="secondary" onClick={update} processing={form.processing}>{form.processing ? 'Memperbarui…' : camera.is_active ? 'Nonaktifkan kamera' : 'Aktifkan kamera'}</Button><ManagementActionFeedback errors={form.errors} /></div>;
}
