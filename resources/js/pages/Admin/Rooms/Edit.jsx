import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import RoomFields from '../../../components/RoomFields';
import Button from '../../../components/ui/Button';

export default function Edit({ room, classes }) {
    const form = useForm({ code: room.code, name: room.name, service_class_id: room.service_class.id,
        current_service_class_id: room.service_class.id, capacity: String(room.capacity), confirm_class_change: false });
    function save(event) {
        event.preventDefault();
        if (form.data.service_class_id !== room.service_class.id && !window.confirm(`Ubah kelas kamar ${room.code}? Pastikan pilihan kelas dan kapasitas sudah sesuai.`)) return;
        form.patch(`/admin/rooms/${room.id}`);
    }
    return (
        <ManagementLayout>
            <Head title="Edit kamar · Silansia" />
            <ManagementPageHeader><Link href={`/admin/rooms/${room.id}`} className="ui-management-link">← Detail kamar</Link>
            <h1 className="ui-management-title">Edit kamar</h1>
            <p className="mt-3 text-sm text-ui-text-secondary">Perubahan informasi kamar mempertahankan status dan seluruh riwayat.</p></ManagementPageHeader>
            <ManagementForm label="Edit kamar" kind="editor" form={form} onSubmit={save}>
                <RoomFields form={form} classes={classes} room={room} />
                <Button processing={form.processing} variant="primary" type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button>
            </ManagementForm>
        </ManagementLayout>
    );
}
