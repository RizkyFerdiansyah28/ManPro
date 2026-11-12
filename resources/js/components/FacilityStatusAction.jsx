import { useForm } from '@inertiajs/react';
import Button from './ui/Button';

export default function FacilityStatusAction({ target, kind }) {
    const form = useForm({ confirm_status_change: true });
    function changeStatus() {
        const label = target.is_active ? 'Nonaktifkan' : 'Aktifkan';
        if (!window.confirm(`${label} ${target.name}? Riwayat dan dependensi tidak akan diubah secara otomatis.`)) return;
        form.post(`/admin/${kind}/${target.id}/${target.is_active ? 'deactivate' : 'activate'}`);
    }
    return (
        <div className="space-y-4">
            <Button type="button" variant="secondary" processing={form.processing} onClick={changeStatus}>{form.processing ? 'Memperbarui…' : target.is_active ? 'Nonaktifkan' : 'Aktifkan'}</Button>
            {Object.values(form.errors).length > 0 && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{Object.values(form.errors).join(' ')}</p>}
        </div>
    );
}
