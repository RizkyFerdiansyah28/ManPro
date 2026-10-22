import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';
import ServiceBenefits from '../../../components/ServiceBenefits';

export default function Edit({ serviceClass: item }) {
    const form = useForm({ name: item.name, monthly_price: item.monthly_price ?? '' });
    function save(event) {
        event.preventDefault();
        form.patch(`/admin/service-classes/${item.id}`);
    }
    return (
        <AppLayout>
            <Head title="Edit kelas layanan · Silansia" />
            <Link href={`/admin/service-classes/${item.id}`} className="text-sm font-semibold text-emerald-800 hover:underline">← Detail kelas</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Edit kelas layanan</h1>
            <div className="mt-8 grid items-start gap-8 lg:grid-cols-2">
                <form onSubmit={save} className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Kode {item.code} dan jenis kamar {item.room_type === 'private' ? 'pribadi' : 'bersama'} tetap dipertahankan.</p>
                    <FormField id="name" label="Nama kelas" required maxLength={255} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <FormField id="monthly_price" label="Tarif bulanan (Rp)" inputMode="decimal" maxLength={15} value={form.data.monthly_price} onChange={(event) => form.setData('monthly_price', event.target.value)} error={form.errors.monthly_price} hint="Boleh kosong jika belum diatur. Gunakan titik desimal, tanpa pemisah ribuan; maksimal 12 digit sebelum titik dan 2 digit desimal." />
                    {form.errors.service_class_id && <p role="alert" className="text-sm text-red-700">{form.errors.service_class_id}</p>}
                    {!item.is_canonical && <p role="alert" className="text-sm text-red-700">Konfigurasi manfaat perlu diperiksa operator sebelum menyimpan perubahan.</p>}
                    <button disabled={form.processing || !item.is_canonical} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Simpan perubahan'}</button>
                </form>
                <ServiceBenefits serviceClass={item} />
            </div>
        </AppLayout>
    );
}
