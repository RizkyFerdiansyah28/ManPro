import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';
import FamilySelector from '../../../components/FamilySelector';

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
        <AppLayout>
            <Head title="Edit lansia · Silansia" />
            <Link href={`/admin/residents/${resident.id}`} className="text-sm font-semibold text-emerald-800 hover:underline">← Kembali ke detail</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Edit data lansia</h1>
            <div className="mt-8 grid items-start gap-8 lg:grid-cols-2">
                <form onSubmit={save} className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <h2 className="text-lg font-semibold">Biodata</h2>
                    <FormField id="name" label="Nama lengkap lansia" required maxLength={255} value={info.data.name} onChange={(event) => info.setData('name', event.target.value)} error={info.errors.name} />
                    <FormField id="birth_date" label="Tanggal lahir" type="date" required value={info.data.birth_date} onChange={(event) => info.setData('birth_date', event.target.value)} error={info.errors.birth_date} hint="Perubahan biodata tidak mengubah keluarga atau status lansia." />
                    <button disabled={info.processing || family.processing} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{info.processing ? 'Menyimpan…' : 'Simpan biodata'}</button>
                </form>
                <form onSubmit={reassign} className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <h2 className="text-lg font-semibold">Ubah keluarga penanggung jawab</h2>
                    <div className="rounded-xl bg-slate-50 p-4 text-sm"><p className="font-semibold">Saat ini: {resident.family.name}</p><p className="mt-1 break-all text-slate-500">{resident.family.email}</p>{!resident.family.is_active && <p className="mt-2 text-amber-800">Akun nonaktif; hubungan tetap dipertahankan sampai diubah secara eksplisit.</p>}</div>
                    <FamilySelector value={family.data.family_id} onChange={(id) => family.setData({ ...family.data, family_id: id, confirm_reassignment: false })} error={family.errors.family_id} disabled={family.processing || info.processing} />
                    <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" checked={family.data.confirm_reassignment} onChange={(event) => family.setData('confirm_reassignment', event.target.checked)} className="mt-1.5 accent-emerald-800" /><span>Saya mengonfirmasi perubahan penanggung jawab dan perpindahan akses biodata lansia ke keluarga yang dipilih.</span></label>
                    {(family.errors.confirm_reassignment || family.errors.current_family_id) && <p role="alert" className="text-sm text-red-700">{family.errors.confirm_reassignment || family.errors.current_family_id}</p>}
                    <button disabled={family.processing || info.processing || !family.data.family_id || !family.data.confirm_reassignment} className="rounded-xl border border-amber-700 px-5 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-50 disabled:opacity-50">{family.processing ? 'Memperbarui…' : 'Ubah keluarga'}</button>
                </form>
            </div>
        </AppLayout>
    );
}
