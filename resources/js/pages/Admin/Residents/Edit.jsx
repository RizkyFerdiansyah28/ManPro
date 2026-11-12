import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FamilySelector from '../../../components/FamilySelector';
import FormField from '../../../components/FormField';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function Edit({ resident }) {
    const info = useForm({ name: resident.name, birth_date: resident.birth_date });
    const family = useForm({ family_id: '', current_family_id: resident.family.id, confirm_reassignment: false });
    const save = (event) => { event.preventDefault(); info.patch(`/admin/residents/${resident.id}`); };
    function reassign(event) {
        event.preventDefault();
        if (!window.confirm(`Ubah keluarga penanggung jawab ${resident.name}? Keluarga sebelumnya akan kehilangan akses ke biodata lansia ini.`)) return;
        family.post(`/admin/residents/${resident.id}/family`);
    }

    return (
        <ManagementLayout>
            <Head title="Edit lansia · Silansia" />
            <ManagementPageHeader><Link href={`/admin/residents/${resident.id}`} className="ui-management-link">← Kembali ke detail</Link>
            <h1 className="ui-management-title">Edit data lansia</h1></ManagementPageHeader>
            <div className="ui-management-two-column">
                <ManagementForm label="Biodata lansia" kind="editor" form={info} onSubmit={save}>
                    <h2 className="ui-management-section-title">Biodata</h2>
                    <FormField id="name" label="Nama lengkap lansia" required maxLength={255} value={info.data.name} onChange={(event) => info.setData('name', event.target.value)} error={info.errors.name} />
                    <FormField id="birth_date" label="Tanggal lahir" type="date" required value={info.data.birth_date} onChange={(event) => info.setData('birth_date', event.target.value)} error={info.errors.birth_date} hint="Perubahan biodata tidak mengubah keluarga atau status lansia." />
                    <Button processing={info.processing} variant="primary" type="submit" disabled={info.processing || family.processing}>{info.processing ? 'Menyimpan…' : 'Simpan biodata'}</Button>
                </ManagementForm>
                <ManagementForm label="Ubah keluarga penanggung jawab" kind="editor" form={family} onSubmit={reassign}>
                    <h2 className="ui-management-section-title">Ubah keluarga penanggung jawab</h2>
                    <div className="ui-management-note"><p className="font-semibold">Saat ini: {resident.family.name}</p><p className="mt-1 break-all text-ui-text-secondary">{resident.family.email}</p>{!resident.family.is_active && <p className="mt-2 text-amber-800">Akun nonaktif; hubungan tetap dipertahankan sampai diubah secara eksplisit.</p>}</div>
                    <FamilySelector value={family.data.family_id} onChange={(id) => family.setData({ ...family.data, family_id: id, confirm_reassignment: false })} error={family.errors.family_id} disabled={family.processing || info.processing} />
                    <label className="ui-management-choice"><ManagementControl field="confirm_reassignment" type="checkbox" checked={family.data.confirm_reassignment} onChange={(event) => family.setData('confirm_reassignment', event.target.checked)} /><span>Saya mengonfirmasi perubahan penanggung jawab dan perpindahan akses biodata lansia ke keluarga yang dipilih.</span></label>
                    {(family.errors.confirm_reassignment || family.errors.current_family_id) && <p role="alert" className="ui-management-note ui-management-note--danger">{family.errors.confirm_reassignment || family.errors.current_family_id}</p>}
                    <Button processing={family.processing} variant="secondary" type="submit" disabled={family.processing || info.processing || !family.data.family_id || !family.data.confirm_reassignment}>{family.processing ? 'Memperbarui…' : 'Ubah keluarga'}</Button>
                </ManagementForm>
            </div>
        </ManagementLayout>
    );
}
