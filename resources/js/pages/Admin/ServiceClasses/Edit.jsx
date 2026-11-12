import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FormField from '../../../components/FormField';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import ServiceBenefits from '../../../components/ServiceBenefits';
import Button from '../../../components/ui/Button';

export default function Edit({ serviceClass: item }) {
    const form = useForm({ name: item.name, monthly_price: item.monthly_price ?? '' });
    function save(event) {
        event.preventDefault();
        form.patch(`/admin/service-classes/${item.id}`);
    }
    return (
        <ManagementLayout>
            <Head title="Edit kelas layanan · Silansia" />
            <ManagementPageHeader><Link href={`/admin/service-classes/${item.id}`} className="ui-management-link">← Detail kelas</Link>
            <h1 className="ui-management-title">Edit kelas layanan</h1></ManagementPageHeader>
            <div className="ui-management-two-column">
                <ManagementForm label="Informasi dan tarif kelas" kind="editor" form={form} onSubmit={save}>
                    <p className="ui-management-note">Kode {item.code} dan jenis kamar {item.room_type === 'private' ? 'pribadi' : 'bersama'} tetap dipertahankan.</p>
                    <FormField id="name" label="Nama kelas" required maxLength={255} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <FormField id="monthly_price" label="Tarif bulanan (Rp)" inputMode="decimal" maxLength={15} value={form.data.monthly_price} onChange={(event) => form.setData('monthly_price', event.target.value)} error={form.errors.monthly_price} hint="Boleh kosong jika belum diatur. Gunakan titik desimal, tanpa pemisah ribuan; maksimal 12 digit sebelum titik dan 2 digit desimal." />
                    {form.errors.service_class_id && <p role="alert" className="ui-management-note ui-management-note--danger">{form.errors.service_class_id}</p>}
                    {!item.is_canonical && <p role="alert" className="ui-management-note ui-management-note--danger">Konfigurasi manfaat perlu diperiksa operator sebelum menyimpan perubahan.</p>}
                    <Button processing={form.processing} variant="primary" type="submit" disabled={form.processing || !item.is_canonical}>{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button>
                </ManagementForm>
                <ServiceBenefits serviceClass={item} />
            </div>
        </ManagementLayout>
    );
}
