import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';
import TableFrame from '../../../components/ui/TableFrame';

const labels = { admin: 'Admin', perawat: 'Perawat', keluarga: 'Keluarga' };

export default function Index({ accounts, filters }) {
    const [pending, setPending] = useState(null);
    const form = useForm({ search: filters.search ?? '', role: filters.role ?? '', status: filters.status ?? '' });
    function filter(event) {
        event.preventDefault();
        form.get('/admin/accounts', { preserveState: true, replace: true });
    }
    function changeStatus(account) {
        if (pending !== null) return;
        const action = account.is_active ? 'deactivate' : 'activate';
        if (!window.confirm(`${account.is_active ? 'Nonaktifkan' : 'Aktifkan'} akun ${account.name}?${account.is_active ? ' Akses sesi akun akan dicabut.' : ''}`)) return;
        router.post(`/admin/accounts/${account.id}/${action}`, {}, { preserveScroll: true, onStart: () => setPending(account.id), onFinish: () => setPending(null) });
    }
    return (
        <ManagementLayout><Head title="Kelola akun · Silansia" />
            <ManagementPageHeader><div className="ui-management-actions"><div><h1 className="ui-management-title">Kelola akun</h1><p className="mt-3 text-sm text-ui-text-secondary">Atur akses perawat dan keluarga di satu tempat.</p></div><Link href="/admin/accounts/create" className="ui-button ui-button--primary">+ Buat akun</Link></div></ManagementPageHeader>
            <section className="ui-management-list">
                <ManagementForm label="Filter daftar" kind="filters" form={form} onSubmit={filter}>
                    <div><label htmlFor="search" className="ui-field-label">Cari akun</label><ManagementControl field="search" id="search" type="search" placeholder="Nama atau email" maxLength={100} value={form.data.search} onChange={(e) => form.setData('search', e.target.value)} /></div>
                    <div><label htmlFor="role-filter" className="ui-field-label">Peran</label><ManagementControl field="role" as="select" id="role-filter" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}><option value="">Semua peran</option><option value="admin">Admin</option><option value="perawat">Perawat</option><option value="keluarga">Keluarga</option></ManagementControl></div>
                    <div><label htmlFor="status-filter" className="ui-field-label">Status</label><ManagementControl field="status" as="select" id="status-filter" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></ManagementControl></div>
                    <Button processing={form.processing} variant="secondary" type="submit" disabled={form.processing}>{form.processing ? 'Mencari…' : 'Terapkan'}</Button>

                </ManagementForm>
                {accounts.data.length ? <TableFrame label="Daftar akun pengguna dan pengaturan akses"><table className="ui-management-table"><caption className="sr-only">Daftar akun pengguna dan pengaturan akses</caption><thead><tr><th scope="col">Pengguna</th><th scope="col">Peran</th><th scope="col">Status</th><th scope="col">Tindakan</th></tr></thead><tbody>{accounts.data.map((account) => <tr key={account.id}><td><p className="font-semibold text-ui-text">{account.name}</p><p className="mt-1 text-xs text-ui-text-secondary">{account.email}</p></td><td>{labels[account.role]}</td><td><StatusBadge tone={account.is_active ? 'positive' : 'neutral'}>{account.is_active ? 'Aktif' : 'Nonaktif'}</StatusBadge></td><td><div className="flex justify-end gap-4">{account.can.updateStatus && <Button processing={pending === account.id} variant="secondary" type="button" disabled={pending !== null} onClick={() => changeStatus(account)} aria-label={`${account.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${account.name}`}>{pending === account.id ? 'Memproses…' : account.is_active ? 'Nonaktifkan' : 'Aktifkan'}</Button>}{account.can.resetPassword && <Link href={`/admin/accounts/${account.id}/password`} aria-label={`Reset kata sandi ${account.name}`} className="ui-management-link">Reset sandi</Link>}{!account.can.updateStatus && <span className="text-xs text-ui-neutral">Akun dilindungi</span>}</div></td></tr>)}</tbody></table></TableFrame> : <EmptyState title={<>Belum ada akun yang sesuai</>} description={<>Ubah pencarian atau filter, atau buat akun baru.</>} />}
                <div className="ui-management-pagination"><p>{accounts.total} akun · Halaman {accounts.current_page} dari {accounts.last_page}</p><nav aria-label="Halaman daftar akun" className="flex gap-3">{accounts.prev_page_url ? <Link href={accounts.prev_page_url} preserveState className="ui-management-link">← Sebelumnya</Link> : <span aria-disabled="true" className="rounded-lg border border-slate-100 px-3 py-2 text-ui-neutral">← Sebelumnya</span>}{accounts.next_page_url ? <Link href={accounts.next_page_url} preserveState className="ui-management-link">Berikutnya →</Link> : <span aria-disabled="true" className="rounded-lg border border-slate-100 px-3 py-2 text-ui-neutral">Berikutnya →</span>}</nav></div>
            </section>
        </ManagementLayout>
    );
}
