import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import ManagementActionFeedback from '../../../components/Management/ManagementActionFeedback';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import OperationSummary from '../../../components/OperationSummary';
import ResidentDetails from '../../../components/ResidentDetails';
import Button from '../../../components/ui/Button';

export default function Show({ resident, operations }) {
    const status = useForm({ confirm_status_change: true });
    function changeStatus() {
        if (status.processing) return;
        if (!window.confirm(`${resident.is_active ? 'Nonaktifkan' : 'Aktifkan'} lansia ${resident.name}? Data dan riwayat tetap disimpan.`)) return;
        status.post(`/admin/residents/${resident.id}/${resident.is_active ? 'deactivate' : 'activate'}`);
    }
    return (
        <ManagementLayout>
            <Head title="Detail lansia · Silansia" />
            <ManagementPageHeader><Link href="/admin/residents" className="ui-management-link">← Daftar lansia</Link>
            <h1 className="ui-management-title">Detail lansia</h1></ManagementPageHeader>
            <ResidentDetails resident={resident} />
            <OperationSummary resident={resident} operations={operations} />
            <Link href={`/admin/residents/${resident.id}/operations`} className="ui-management-link">Lihat riwayat masa tinggal dan enrollment →</Link>
            <div className="ui-management-actions">
                <Link href={`/admin/residents/${resident.id}/edit`} className="ui-button ui-button--primary">Edit biodata / keluarga</Link>
                <Button processing={status.processing} variant="secondary" type="button" disabled={status.processing} onClick={changeStatus}>{status.processing ? 'Memperbarui…' : resident.is_active ? 'Nonaktifkan lansia' : 'Aktifkan lansia'}</Button>
            </div>
            <p className="mt-5 text-sm leading-6 text-ui-text-secondary">Penonaktifan ditolak bila masih ada masa tinggal atau enrollment aktif. Halaman ini tidak menutup relasi atau mengubah riwayat secara otomatis.</p>
            <ManagementActionFeedback errors={status.errors} />
        </ManagementLayout>
    );
}
