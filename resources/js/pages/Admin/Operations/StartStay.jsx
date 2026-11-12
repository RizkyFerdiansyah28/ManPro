import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import OperationPagination from '../../../components/OperationPagination';
import Button from '../../../components/ui/Button';

export default function StartStay({ resident, operations, rooms, filters }) {
    const filter = useForm({ search: filters.search });
    const form = useForm({ room_id: '', enrollment_id: operations.enrollment?.id ?? '', confirm_start: false });
    const blocked = !resident.is_active || !operations.enrollment || Boolean(operations.stay);
    function search(event) { event.preventDefault(); filter.get(`/admin/residents/${resident.id}/stays/create`); }
    function save(event) { event.preventDefault(); form.post(`/admin/residents/${resident.id}/stays`); }
    return <ManagementLayout>
        <Head title="Mulai masa tinggal · Silansia" />
        <ManagementPageHeader><Link href={`/admin/residents/${resident.id}/operations`} className="ui-management-link">← Masa tinggal dan layanan</Link>
        <h1 className="ui-management-title">Mulai masa tinggal</h1><p className="mt-3 text-ui-neutral">{resident.name} · {operations.enrollment?.service_class.name ?? 'Belum ada enrollment aktif'}</p></ManagementPageHeader>
        {blocked && <p role="alert" className="ui-management-note ui-management-note--warning">Lansia harus aktif, memiliki enrollment aktif, dan belum mempunyai masa tinggal aktif.</p>}
        <ManagementForm label="Cari kamar sesuai enrollment" kind="filters" form={filter} onSubmit={search}><div className="grow"><label htmlFor="search" className="ui-field-label">Cari kode / nama kamar</label><ManagementControl field="search" id="search" type="search" maxLength={100} value={filter.data.search} onChange={(event) => filter.setData('search', event.target.value)} /></div><Button processing={filter.processing} variant="secondary" type="submit" disabled={filter.processing}>{filter.processing ? 'Mencari…' : 'Cari'}</Button></ManagementForm>

        <ManagementForm label="Mulai masa tinggal" kind="editor" form={form} onSubmit={save}>
            <fieldset className="ui-management-selector" disabled={blocked || form.processing}><legend className="ui-management-legend">Kamar aktif sesuai kelas enrollment <span className="ui-field-required" aria-hidden="true">Wajib</span></legend>
                {rooms.data.length ? <div className="space-y-3">{rooms.data.map((room) => <label key={room.id} className="ui-management-choice"><ManagementControl field="room_id" type="radio" name="room_id" required value={room.id} checked={form.data.room_id === room.id} disabled={room.available === 0 || !room.eligible_capacity} onChange={() => form.setData('room_id', room.id)} /><span><strong>{room.code} · {room.name}</strong><span className="mt-2 block text-ui-text-secondary">Penghuni aktif {room.occupancy} / {room.capacity} · Tersedia {room.available}{!room.eligible_capacity ? ' · Kapasitas perlu diperiksa operator' : ''}</span></span></label>)}</div> : <p className="text-sm text-ui-text-secondary">Belum ada kamar aktif yang sesuai. Periksa kelas, status kamar, atau pencarian.</p>}
            </fieldset>
            <OperationPagination page={rooms} label="pilihan kamar" />
            <p className="text-xs leading-6 text-ui-text-secondary">Ketersediaan diperiksa ulang saat disimpan. Penghuni tidak dipindahkan dari kamar lain secara otomatis. Waktu mulai menggunakan waktu server.</p>
            <label className="ui-management-choice"><ManagementControl field="confirm_start" type="checkbox" required checked={form.data.confirm_start} onChange={(event) => form.setData('confirm_start', event.target.checked)} /><span>Saya mengonfirmasi penempatan pada kamar yang dipilih.</span></label>

            <Button processing={form.processing} variant="primary" type="submit" disabled={blocked || form.processing || !form.data.room_id}>{form.processing ? 'Menyimpan…' : 'Mulai masa tinggal'}</Button>
        </ManagementForm>
    </ManagementLayout>;
}
