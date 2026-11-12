import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import CameraFields from '../../../components/CameraFields';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function Create() {
    const form = useForm({ room_id: '', name: '', source_type: 'webcam', source_reference: '', confirm_create: false });
    function save(event) { event.preventDefault(); form.post('/admin/cameras', { onError: () => form.reset('source_reference'), onSuccess: () => form.reset('source_reference') }); }
    return <ManagementLayout><Head title="Daftarkan kamera · Silansia" /><ManagementPageHeader><Link href="/admin/cameras" className="ui-management-link">← Daftar kamera</Link><h1 className="ui-management-title">Daftarkan kamera</h1><p className="mt-3 text-sm text-ui-text-secondary">Kamera baru nonaktif. Pilih kamar aktif yang belum memiliki kamera.</p></ManagementPageHeader>
        <ManagementForm label="Daftarkan kamera" kind="editor" form={form} onSubmit={save}><CameraFields form={form} /><label className="ui-management-choice"><ManagementControl field="confirm_create" type="checkbox" required checked={form.data.confirm_create} onChange={(event) => form.setData('confirm_create', event.target.checked)} /><span>Saya mengonfirmasi pendaftaran kamera nonaktif. Aktivasi dilakukan melalui tindakan terpisah.</span></label><Button processing={form.processing} variant="primary" type="submit" disabled={form.processing || !form.data.room_id}>{form.processing ? 'Menyimpan…' : 'Daftarkan kamera'}</Button></ManagementForm>
    </ManagementLayout>;
}
