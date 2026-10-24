import { useForm } from '@inertiajs/react';

export default function CloseOperationAction({ residentId, record, kind, disabled = false }) {
    const form = useForm({ confirm_end: true });
    const isStay = kind === 'stays';
    function close() {
        if (!window.confirm(isStay ? 'Tutup masa tinggal ini? Enrollment tetap aktif dan riwayat dipertahankan.' : 'Tutup enrollment ini? Snapshot tarif dan riwayat tetap disimpan.')) return;
        form.post(`/admin/residents/${residentId}/${kind}/${record.id}/close`);
    }
    return <div className="mt-5 space-y-3">
        <button type="button" disabled={disabled || form.processing} onClick={close} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50">{form.processing ? 'Menutup…' : isStay ? 'Tutup masa tinggal' : 'Tutup enrollment'}</button>
        {disabled && <p className="text-xs text-slate-500">Tutup masa tinggal terlebih dahulu.</p>}
        {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700">{Object.values(form.errors).join(' ')}</p>}
    </div>;
}
