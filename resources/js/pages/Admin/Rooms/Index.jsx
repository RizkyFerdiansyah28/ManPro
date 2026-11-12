import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';
import TableFrame from '../../../components/ui/TableFrame';

export default function Index({ rooms, filters, classes }) {
    const form = useForm({ search: filters.search ?? '', service_class_id: filters.service_class_id ?? '', status: filters.status ?? '' });
    function filter(event) {
        event.preventDefault();
        form.get('/admin/rooms', { preserveState: true, replace: true });
    }
    return (
        <ManagementLayout>
            <Head title="Kelola kamar · Silansia" />
            <ManagementPageHeader><div className="ui-management-actions"><div><h1 className="ui-management-title">Kelola kamar</h1><p className="mt-3 text-sm text-ui-text-secondary">Kelola identitas kamar, kelas layanan, kapasitas, dan status.</p></div><Link href="/admin/rooms/create" className="ui-button ui-button--primary">+ Tambah kamar</Link></div></ManagementPageHeader>
            <section className="ui-management-list">
                <ManagementForm label="Filter daftar" kind="filters" form={form} onSubmit={filter}>
                    <div><label htmlFor="room-search" className="ui-field-label">Cari kode / nama</label><ManagementControl field="search" id="room-search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} /></div>
                    <div><label htmlFor="room-class" className="ui-field-label">Kelas layanan</label><ManagementControl field="service_class_id" as="select" id="room-class" value={form.data.service_class_id} onChange={(event) => form.setData('service_class_id', event.target.value)}><option value="">Semua kelas</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}{!item.is_active ? ' (nonaktif)' : ''}</option>)}</ManagementControl></div>
                    <div><label htmlFor="room-status" className="ui-field-label">Status</label><ManagementControl field="status" as="select" id="room-status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}><option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></ManagementControl></div>
                    <Button processing={form.processing} variant="secondary" type="submit" disabled={form.processing}>{form.processing ? 'Mencari…' : 'Terapkan'}</Button>

                </ManagementForm>
                {rooms.data.length ? <TableFrame label="Daftar kamar dan okupansi aktif"><table className="ui-management-table"><caption className="sr-only">Daftar kamar dan okupansi aktif</caption>
                    <thead><tr><th scope="col">Kamar</th><th scope="col">Kelas</th><th scope="col">Penghuni / kapasitas</th><th scope="col">Status</th><th scope="col">Tindakan</th></tr></thead>
                    <tbody>{rooms.data.map((room) => <tr key={room.id}><td><p className="font-semibold">{room.code}</p><p className="mt-1 text-xs text-ui-text-secondary">{room.name}</p></td><td>{room.service_class.name}<span className="mt-1 block text-xs text-ui-text-secondary">{room.service_class.room_type === 'private' ? 'Pribadi' : 'Bersama'}</span></td><td>{room.active_occupancy} / {room.capacity}</td><td><StatusBadge tone={room.is_active ? 'positive' : 'neutral'}>{room.is_active ? 'Aktif' : 'Nonaktif'}</StatusBadge></td><td><Link href={`/admin/rooms/${room.id}`} aria-label={`Lihat kamar ${room.code}`} className="ui-management-link">Detail</Link></td></tr>)}</tbody>
                </table></TableFrame> : <EmptyState title={<>Belum ada kamar yang sesuai</>} description={<>Ubah pencarian/filter, atau tambahkan kamar baru.</>} />}
                <div className="ui-management-pagination"><p>{rooms.total} kamar · Halaman {rooms.current_page} dari {rooms.last_page}</p><nav aria-label="Halaman daftar kamar" className="flex gap-4">{rooms.prev_page_url ? <Link href={rooms.prev_page_url} preserveState className="ui-management-link">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}{rooms.next_page_url ? <Link href={rooms.next_page_url} preserveState className="ui-management-link">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}</nav></div>
            </section>
        </ManagementLayout>
    );
}
