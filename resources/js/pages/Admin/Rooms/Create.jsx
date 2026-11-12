import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import RoomFields from '../../../components/RoomFields';
import Button from '../../../components/ui/Button';

export default function Create({ classes }) {
    const form = useForm({ code: '', name: '', service_class_id: '', capacity: '' });
    function save(event) { event.preventDefault(); form.post('/admin/rooms'); }
    return (
        <ManagementLayout>
            <Head title="Tambah kamar · Silansia" />
            <ManagementPageHeader><Link href="/admin/rooms" className="ui-management-link">← Daftar kamar</Link>
            <h1 className="ui-management-title">Tambah kamar</h1>
            <p className="mt-3 text-sm text-ui-text-secondary">Kamar baru menggunakan kelas aktif dan disimpan dalam status aktif.</p></ManagementPageHeader>
            <ManagementForm label="Tambah kamar" kind="editor" form={form} onSubmit={save}>
                {!classes.length && <p role="alert" className="ui-management-note ui-management-note--warning">Belum ada kelas aktif. Aktifkan kelas layanan sebelum menambah kamar.</p>}
                <RoomFields form={form} classes={classes} />
                <Button processing={form.processing} variant="primary" type="submit" disabled={form.processing || !classes.length}>{form.processing ? 'Menyimpan…' : 'Simpan kamar'}</Button>
            </ManagementForm>
        </ManagementLayout>
    );
}
