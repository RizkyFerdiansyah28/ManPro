import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FamilySelector from '../../../components/FamilySelector';
import FormField from '../../../components/FormField';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function Create() {
    const form = useForm({ name: '', birth_date: '', family_id: '' });
    const submit = (event) => { event.preventDefault(); form.post('/admin/residents'); };

    return (
        <ManagementLayout>
            <Head title="Tambah lansia · Silansia" />
            <ManagementPageHeader><Link href="/admin/residents" className="ui-management-link">← Kembali ke daftar lansia</Link>
            <h1 className="ui-management-title">Tambah lansia</h1>
            <p className="mt-3 text-sm text-ui-text-secondary">Isi biodata dan pilih satu akun keluarga penanggung jawab.</p></ManagementPageHeader>
            <ManagementForm label="Tambah lansia" kind="editor" form={form} onSubmit={submit}>
                <FormField id="name" label="Nama lengkap lansia" required maxLength={255} autoComplete="off" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                <FormField id="birth_date" label="Tanggal lahir" type="date" required value={form.data.birth_date} onChange={(event) => form.setData('birth_date', event.target.value)} error={form.errors.birth_date} hint="Usia dihitung otomatis dari tanggal lahir." />
                <FamilySelector value={form.data.family_id} onChange={(id) => form.setData('family_id', id)} error={form.errors.family_id} disabled={form.processing} />
                <p className="text-sm text-ui-text-secondary">Lansia baru disimpan dalam status aktif. Penempatan kamar dan layanan belum diatur melalui halaman ini.</p>
                <div className="ui-management-actions"><Button processing={form.processing} variant="primary" type="submit" disabled={form.processing || !form.data.family_id}>{form.processing ? 'Menyimpan…' : 'Simpan lansia'}</Button><Link href="/admin/residents" className="ui-management-link">Batal</Link></div>
            </ManagementForm>
        </ManagementLayout>
    );
}
