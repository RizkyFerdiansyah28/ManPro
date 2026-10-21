import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';

export default function ResetPassword({ account }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    function submit(event) {
        event.preventDefault();
        form.post(`/admin/accounts/${account.id}/password`, { onFinish: () => form.reset() });
    }
    return (
        <AppLayout><Head title="Reset kata sandi · Silansia" /><Link href="/admin/accounts" className="text-sm font-medium text-emerald-800 hover:underline">← Kembali ke daftar akun</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Reset kata sandi</h1><p className="mt-3 text-sm text-slate-500">Perbarui akses akun pengguna secara aman.</p>
            <form onSubmit={submit} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <div className="rounded-xl bg-slate-50 p-4"><p className="font-semibold">{account.name}</p><p className="mt-1 break-all text-sm text-slate-500">{account.email}</p></div>
                <FormField id="current_password" label="Kata sandi Admin Anda" type="password" autoComplete="current-password" required value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} error={form.errors.current_password} hint="Konfirmasi identitas Anda sebelum mengubah akses pengguna." />
                <FormField id="password" label="Kata sandi baru pengguna" type="password" autoComplete="new-password" required maxLength={72} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} hint="Minimal 12 karakter, huruf besar/kecil dan angka; maksimal 72 byte." />
                <FormField id="password_confirmation" label="Konfirmasi kata sandi baru" type="password" autoComplete="new-password" required maxLength={72} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={form.errors.password_confirmation} />
                <p className="rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-900">Sesi akun ini akan dicabut. Pengguna perlu masuk kembali dengan kata sandi baru. Status aktif akun tidak berubah.</p>
                <div className="flex flex-wrap items-center gap-4"><button disabled={form.processing} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 focus-visible:ring-4 focus-visible:ring-emerald-100 disabled:opacity-50">{form.processing ? 'Memperbarui…' : 'Perbarui kata sandi'}</button><Link href="/admin/accounts" className="text-sm font-semibold text-slate-600 hover:underline">Batal</Link></div>
            </form>
        </AppLayout>
    );
}
