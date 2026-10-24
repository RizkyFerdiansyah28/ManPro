import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import formatPrice from '../../../utils/formatPrice';

export default function CreateEnrollment({ resident, operations, classes }) {
    const form = useForm({ service_class_id: '', confirm_start: false });
    const selected = classes.find((item) => item.id === form.data.service_class_id);
    const blocked = !resident.is_active || Boolean(operations.enrollment);
    function save(event) { event.preventDefault(); form.post(`/admin/residents/${resident.id}/enrollments`); }
    return <AppLayout>
        <Head title="Buat enrollment · Silansia" />
        <Link href={`/admin/residents/${resident.id}/operations`} className="text-sm font-semibold text-emerald-800 hover:underline">← Masa tinggal dan layanan</Link>
        <h1 className="mt-5 text-3xl font-bold">Buat enrollment</h1><p className="mt-3 text-slate-600">{resident.name}</p>
        <form onSubmit={save} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            {blocked && <p role="alert" className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Lansia nonaktif atau sudah mempunyai enrollment aktif. Tindakan baru tidak tersedia.</p>}
            <div><label htmlFor="service_class_id" className="mb-2 block text-sm font-semibold">Kelas layanan</label>
                <select id="service_class_id" required disabled={blocked || form.processing} value={form.data.service_class_id} onChange={(event) => form.setData('service_class_id', event.target.value)} aria-invalid={Boolean(form.errors.service_class_id)} className="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                    <option value="">Pilih kelas layanan</option>{classes.map((item) => <option key={item.id} value={item.id} disabled={!item.is_active || !item.is_canonical || item.monthly_price === null}>{item.name} · {formatPrice(item.monthly_price)}{!item.is_active ? ' (nonaktif)' : !item.is_canonical ? ' (konfigurasi perlu diperiksa)' : ''}</option>)}
                </select>
            </div>
            <p className="text-sm leading-7 text-slate-600">Snapshot tarif: {selected ? formatPrice(selected.monthly_price) : 'Pilih kelas terlebih dahulu'}. Nilai disalin dari tarif kelas terkini saat disimpan. Tarif kosong ditolak; tidak ada invoice atau pembayaran yang dibuat.</p>
            {operations.stay && <p className="text-sm text-amber-800">Kelas harus sesuai dengan kamar aktif: {operations.stay.room.service_class.name}.</p>}
            <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" required checked={form.data.confirm_start} onChange={(event) => form.setData('confirm_start', event.target.checked)} className="mt-1 accent-emerald-800" /><span>Saya mengonfirmasi pembuatan enrollment dengan tarif kelas saat disimpan.</span></label>
            {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700">{Object.values(form.errors).join(' ')}</p>}
            <button disabled={blocked || form.processing} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Buat enrollment'}</button>
        </form>
    </AppLayout>;
}
