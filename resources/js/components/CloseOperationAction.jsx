import { useForm } from '@inertiajs/react';
import ManagementActionFeedback from './Management/ManagementActionFeedback';
import Button from './ui/Button';

export default function CloseOperationAction({ residentId, record, kind, disabled = false }) {
    const form = useForm({ confirm_end: true });
    const isStay = kind === 'stays';
    function close() {
        if (disabled || form.processing) return;
        if (!window.confirm(isStay ? 'Tutup masa tinggal ini? Enrollment tetap aktif dan riwayat dipertahankan.' : 'Tutup enrollment ini? Snapshot tarif dan riwayat tetap disimpan.')) return;
        form.post(`/admin/residents/${residentId}/${kind}/${record.id}/close`);
    }
    return <div className="mt-5 space-y-3">
        <Button type="button" variant="secondary" disabled={disabled} processing={form.processing} onClick={close}>{form.processing ? 'Menutup…' : isStay ? 'Tutup masa tinggal' : 'Tutup enrollment'}</Button>
        {disabled && <p className="text-xs text-slate-500">Tutup masa tinggal terlebih dahulu.</p>}
        <ManagementActionFeedback errors={form.errors} />
    </div>;
}
