import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import CameraFields from '../../../components/CameraFields';

export default function Create() {
    const form = useForm({ room_id: '', name: '', source_type: 'webcam', source_reference: '', confirm_create: false });
    function save(event) { event.preventDefault(); form.post('/admin/cameras', { onError: () => form.reset('source_reference'), onSuccess: () => form.reset('source_reference') }); }
    return <AppLayout><Head title="Daftarkan kamera · Silansia" /><Link href="/admin/cameras" className="text-sm font-semibold text-emerald-800 hover:underline">← Daftar kamera</Link><h1 className="mt-5 text-3xl font-bold">Daftarkan kamera</h1><p className="mt-3 text-sm text-slate-500">Kamera baru nonaktif. Pilih kamar aktif yang belum memiliki kamera.</p>
        <form onSubmit={save} className="mt-8 max-w-3xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8"><CameraFields form={form} /><label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" required checked={form.data.confirm_create} onChange={(event) => form.setData('confirm_create', event.target.checked)} className="mt-1.5 accent-emerald-800" /><span>Saya mengonfirmasi pendaftaran kamera nonaktif. Aktivasi dilakukan melalui tindakan terpisah.</span></label><button disabled={form.processing || !form.data.room_id} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Daftarkan kamera'}</button></form>
    </AppLayout>;
}
