import { useForm } from '@inertiajs/react';
import ManagementActionFeedback from './Management/ManagementActionFeedback';
import Button from './ui/Button';

export default function FacilityStatusAction({ target, kind }) {
    const form = useForm({ confirm_status_change: true });
    function changeStatus() {
        if (form.processing) return;
        const label = target.is_active ? 'Nonaktifkan' : 'Aktifkan';
        if (!window.confirm(`${label} ${target.name}? Riwayat dan dependensi tidak akan diubah secara otomatis.`)) return;
        form.post(`/admin/${kind}/${target.id}/${target.is_active ? 'deactivate' : 'activate'}`);
    }
    return (
        <div className="space-y-4">
            <Button type="button" variant="secondary" processing={form.processing} onClick={changeStatus}>{form.processing ? 'Memperbarui…' : target.is_active ? 'Nonaktifkan' : 'Aktifkan'}</Button>
            <ManagementActionFeedback errors={form.errors} />
        </div>
    );
}
