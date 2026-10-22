import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';
import FamilySelector from '../../../components/FamilySelector';

export default function Create() {
    const form = useForm({ name: '', birth_date: '', family_id: '' });
    const submit = (event) => { event.preventDefault(); form.post('/admin/residents'); };

    return (
        <AppLayout>
            <Head title="Tambah lansia · Silansia" />
            <Link href="/admin/residents" className="text-sm font-semibold text-emerald-800 hover:underline">← Kembali ke daftar lansia</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Tambah lansia</h1>
            <p className="mt-3 text-sm text-slate-500">Isi biodata dan pilih satu akun keluarga penanggung jawab.</p>
            <form onSubmit={submit} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <FormField id="name" label="Nama lengkap lansia" required maxLength={255} autoComplete="off" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                <FormField id="birth_date" label="Tanggal lahir" type="date" required value={form.data.birth_date} onChange={(event) => form.setData('birth_date', event.target.value)} error={form.errors.birth_date} hint="Usia dihitung otomatis dari tanggal lahir." />
                <FamilySelector value={form.data.family_id} onChange={(id) => form.setData('family_id', id)} error={form.errors.family_id} disabled={form.processing} />
                <p className="text-sm text-slate-500">Lansia baru disimpan dalam status aktif. Penempatan kamar dan layanan belum diatur melalui halaman ini.</p>
                <div className="flex items-center gap-5"><button disabled={form.processing || !form.data.family_id} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Simpan lansia'}</button><Link href="/admin/residents" className="text-sm font-semibold text-slate-600">Batal</Link></div>
            </form>
        </AppLayout>
    );
}
