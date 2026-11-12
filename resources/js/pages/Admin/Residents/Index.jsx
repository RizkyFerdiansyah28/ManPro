import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';
import TableFrame from '../../../components/ui/TableFrame';

export default function Index({ residents, filters }) {
    const base = '/admin/residents';
    const form = useForm({ search: filters.search ?? '', status: filters.status ?? '' });
    const submit = (event) => { event.preventDefault(); form.get(base, { preserveState: true, replace: true }); };

    return (
        <ManagementLayout>
            <Head title="Kelola lansia · Silansia" />
            <ManagementPageHeader><div className="ui-management-actions">
                <div>
                    <h1 className="ui-management-title">Kelola lansia</h1>
                    <p className="mt-3 text-sm text-ui-text-secondary">Kelola biodata dan penanggung jawab keluarga.</p>
                </div>
                <Link href={`${base}/create`} className="ui-button ui-button--primary">+ Tambah lansia</Link>
            </div></ManagementPageHeader>
            <section className="ui-management-list">
                <ManagementForm label="Filter daftar" kind="filters" form={form} onSubmit={submit}>
                    <div><label htmlFor="resident-search" className="ui-field-label">Cari nama lansia</label>
                        <ManagementControl field="search" id="resident-search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} />
                    </div>
                    <div><label htmlFor="resident-status" className="ui-field-label">Status</label>
                        <ManagementControl field="status" as="select" id="resident-status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                            <option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option>
                        </ManagementControl>
                    </div>
                    <Button processing={form.processing} variant="secondary" type="submit" disabled={form.processing}>{form.processing ? 'Mencari…' : 'Terapkan'}</Button>

                </ManagementForm>
                {residents.data.length ? <TableFrame label="Daftar lansia">
                    <table className="ui-management-table"><caption className="sr-only">Daftar lansia</caption>
                        <thead><tr><th scope="col">Nama</th><th scope="col">Usia</th><th scope="col">Keluarga</th><th scope="col">Status</th><th scope="col">Tindakan</th></tr></thead>
                        <tbody>{residents.data.map((resident) => <tr key={resident.id}>
                            <td>{resident.name}</td><td>{resident.age === null ? 'Belum tersedia' : `${resident.age} tahun`}</td>
                            <td><span>{resident.family.name}</span>{!resident.family.is_active && <span className="mt-1 block text-xs text-amber-800">Akun nonaktif</span>}</td>
                            <td><StatusBadge tone={resident.is_active ? 'positive' : 'neutral'}>{resident.is_active ? 'Aktif' : 'Nonaktif'}</StatusBadge></td>
                            <td><Link href={`${base}/${resident.id}`} aria-label={`Lihat ${resident.name}`} className="ui-management-link">Detail</Link></td>
                        </tr>)}</tbody>
                    </table>
                </TableFrame> : <EmptyState title={<>{'Belum ada lansia yang sesuai'}</>} description={<>{'Ubah filter atau tambahkan data lansia baru.'}</>} />}
                <div className="ui-management-pagination">
                    <p>{residents.total} lansia · Halaman {residents.current_page} dari {residents.last_page}</p>
                    <nav aria-label="Halaman daftar lansia" className="flex gap-4">
                        {residents.prev_page_url ? <Link href={residents.prev_page_url} preserveState className="ui-management-link">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}
                        {residents.next_page_url ? <Link href={residents.next_page_url} preserveState className="ui-management-link">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}
                    </nav>
                </div>
            </section>
        </ManagementLayout>
    );
}
