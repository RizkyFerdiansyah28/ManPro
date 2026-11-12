import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import CameraFields from '../../../components/CameraFields';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function Edit({ camera }) {
    const form = useForm({ room_id: camera.room.id, current_room_id: camera.room.id, name: camera.name, source_type: camera.source_type,
        source_reference: '', source_action: 'keep', version: camera.version, confirm_reassignment: false });
    function save(event) {
        event.preventDefault();
        if (form.data.room_id !== camera.room.id && !window.confirm('Pindahkan kamera ke kamar yang dipilih? Kamera harus nonaktif dan belum mempunyai deteksi.')) return;
        form.patch(`/admin/cameras/${camera.id}`, { onError: () => form.reset('source_reference'), onSuccess: () => form.reset('source_reference') });
    }
    return <ManagementLayout><Head title="Edit kamera · Silansia" /><ManagementPageHeader><Link href={`/admin/cameras/${camera.id}`} className="ui-management-link">← Detail kamera</Link><h1 className="ui-management-title">Edit konfigurasi kamera</h1><p className="mt-3 text-sm text-ui-text-secondary">Nilai sumber tersimpan tidak dimuat kembali. Gunakan tindakan ganti atau kosongkan secara eksplisit.</p></ManagementPageHeader>
        <ManagementForm label="Edit konfigurasi kamera" kind="editor" form={form} onSubmit={save}><CameraFields form={form} camera={camera} />{form.errors.version && <Button variant="secondary" type="button" onClick={() => window.location.reload()}>Muat ulang konfigurasi terbaru</Button>}<Button processing={form.processing} variant="primary" type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button></ManagementForm>
    </ManagementLayout>;
}
