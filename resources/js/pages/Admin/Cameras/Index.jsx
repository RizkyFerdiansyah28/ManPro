import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import OperationPagination from '../../../components/OperationPagination';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';
import TableFrame from '../../../components/ui/TableFrame';

const types = { webcam: 'Webcam browser', uploaded_video: 'Berkas privat', rtsp: 'Referensi RTSP' };
export default function Index({ cameras, filters }) {
    const form = useForm({ search: filters.search ?? '', status: filters.status ?? '', room_id: filters.room_id ?? '' });
    function search(event) { event.preventDefault(); form.get('/admin/cameras', { preserveState: true, replace: true }); }
    return <ManagementLayout>
        <Head title="Kelola kamera · Silansia" />
        <ManagementPageHeader><div className="ui-management-actions"><div><h1 className="ui-management-title">Kelola kamera</h1><p className="mt-3 text-sm text-ui-text-secondary">Konfigurasi administrasi kamera per kamar. Status aktif bukan status konektivitas.</p></div><Link href="/admin/cameras/create" className="ui-button ui-button--primary">+ Daftarkan kamera</Link></div></ManagementPageHeader>
        <section className="ui-management-list">
            <ManagementForm label="Filter daftar" kind="filters" form={form} onSubmit={search}><div className="grow"><label htmlFor="camera-search" className="ui-field-label">Cari nama kamera atau kode/nama kamar</label><ManagementControl field="search" id="camera-search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} /></div><div><label htmlFor="camera-status" className="ui-field-label">Status administrasi</label><ManagementControl field="status" as="select" id="camera-status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}><option value="">Semua</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></ManagementControl></div><Button processing={form.processing} variant="secondary" type="submit" disabled={form.processing}>{form.processing ? 'Mencari…' : 'Terapkan'}</Button>{form.data.room_id && <Button variant="secondary" type="button" onClick={() => { form.setData('room_id', ''); }}>Hapus filter kamar lalu terapkan</Button>}</ManagementForm>

            {cameras.data.length ? <TableFrame label="Daftar konfigurasi kamera"><table className="ui-management-table"><caption className="sr-only">Daftar konfigurasi kamera</caption><thead><tr><th scope="col">Kamera</th><th scope="col">Kamar / kelas</th><th scope="col">Status</th><th scope="col">Tindakan</th></tr></thead><tbody>{cameras.data.map((camera) => <tr key={camera.id}><td><p className="font-semibold">{camera.name}</p><p className="mt-1 text-xs text-ui-text-secondary">{types[camera.source_type] ?? 'Jenis sumber tidak dikenali'}</p></td><td>{camera.room.code} · {camera.room.name}<p className="mt-1 text-xs text-ui-text-secondary">{camera.room.service_class.name}</p></td><td><StatusBadge tone={camera.is_active ? 'positive' : 'neutral'}>{camera.is_active ? 'Aktif administrasi' : 'Nonaktif'}</StatusBadge></td><td><Link href={`/admin/cameras/${camera.id}`} aria-label={`Lihat kamera ${camera.name}`} className="ui-management-link">Detail</Link></td></tr>)}</tbody></table></TableFrame> : <EmptyState title={<>Belum ada kamera yang sesuai pencarian.</>} />}
            <OperationPagination page={cameras} label="daftar kamera" />
        </section>
    </ManagementLayout>;
}
