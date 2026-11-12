import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';
import formatPrice from '../../../utils/formatPrice';

export default function CreateEnrollment({ resident, operations, classes }) {
    const form = useForm({ service_class_id: '', confirm_start: false });
    const selected = classes.find((item) => item.id === form.data.service_class_id);
    const blocked = !resident.is_active || Boolean(operations.enrollment);
    function save(event) { event.preventDefault(); form.post(`/admin/residents/${resident.id}/enrollments`); }
    return <ManagementLayout>
        <Head title="Buat enrollment · Silansia" />
        <ManagementPageHeader><Link href={`/admin/residents/${resident.id}/operations`} className="ui-management-link">← Masa tinggal dan layanan</Link>
        <h1 className="ui-management-title">Buat enrollment</h1><p className="mt-3 text-ui-neutral">{resident.name}</p></ManagementPageHeader>
        <ManagementForm label="Buat enrollment" kind="editor" form={form} onSubmit={save}>
            {blocked && <p role="alert" className="ui-management-note ui-management-note--warning">Lansia nonaktif atau sudah mempunyai enrollment aktif. Tindakan baru tidak tersedia.</p>}
            <div><label htmlFor="service_class_id" className="ui-field-label">Kelas layanan <span className="ui-field-required" aria-hidden="true">Wajib</span></label>
                <ManagementControl field="service_class_id" as="select" id="service_class_id" required disabled={blocked || form.processing} value={form.data.service_class_id} onChange={(event) => form.setData('service_class_id', event.target.value)} aria-invalid={Boolean(form.errors.service_class_id)}>
                    <option value="">Pilih kelas layanan</option>{classes.map((item) => <option key={item.id} value={item.id} disabled={!item.is_active || !item.is_canonical || item.monthly_price === null}>{item.name} · {formatPrice(item.monthly_price)}{!item.is_active ? ' (nonaktif)' : !item.is_canonical ? ' (konfigurasi perlu diperiksa)' : ''}</option>)}
                </ManagementControl>
            </div>
            <p className="text-sm leading-7 text-ui-neutral">Snapshot tarif: {selected ? formatPrice(selected.monthly_price) : 'Pilih kelas terlebih dahulu'}. Nilai disalin dari tarif kelas terkini saat disimpan. Tarif kosong ditolak; tidak ada invoice atau pembayaran yang dibuat.</p>
            {operations.stay && <p className="text-sm text-amber-800">Kelas harus sesuai dengan kamar aktif: {operations.stay.room.service_class.name}.</p>}
            <label className="ui-management-choice"><ManagementControl field="confirm_start" type="checkbox" required checked={form.data.confirm_start} onChange={(event) => form.setData('confirm_start', event.target.checked)} /><span>Saya mengonfirmasi pembuatan enrollment dengan tarif kelas saat disimpan.</span></label>

            <Button processing={form.processing} variant="primary" type="submit" disabled={blocked || form.processing}>{form.processing ? 'Menyimpan…' : 'Buat enrollment'}</Button>
        </ManagementForm>
    </ManagementLayout>;
}
