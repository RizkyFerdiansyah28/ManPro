import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';

export default function Create() {
    const [type, setType] = useState('perawat');
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    function submit(event) {
        event.preventDefault();
        form.post(type === 'perawat' ? '/admin/accounts/perawat' : '/admin/accounts/keluarga', { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <AppLayout><Head title="Buat akun · Silansia" /><Link href="/admin/accounts" className="text-sm font-medium text-emerald-800 hover:underline">← Kembali ke daftar akun</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Buat akun baru</h1><p className="mt-3 text-sm text-slate-500">Berikan akses kepada perawat atau keluarga penghuni.</p>
            <form onSubmit={submit} className="mt-8 max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <fieldset><legend className="mb-3 text-sm font-semibold">Jenis akun</legend><div className="grid grid-cols-2 gap-3">{[['perawat', 'Perawat'], ['keluarga', 'Keluarga']].map(([value, label]) => <label key={value} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-4 text-sm ${type === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-300'}`}><input type="radio" name="account-type" checked={type === value} onChange={() => setType(value)} className="accent-emerald-800" />{label}</label>)}</div></fieldset>
                <FormField id="name" label="Nama lengkap" required autoComplete="name" maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
                <FormField id="email" label="Email" type="email" required autoComplete="off" maxLength={254} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
                <FormField id="password" label="Kata sandi awal" type="password" required autoComplete="new-password" maxLength={72} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} hint="Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka. Maksimal 72 byte." />
                <FormField id="password_confirmation" label="Konfirmasi kata sandi" type="password" required autoComplete="new-password" maxLength={72} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={form.errors.password_confirmation} />
                <p className="rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-900">Akun dibuat nonaktif. Setelah kredensial disampaikan secara aman, aktifkan akun melalui daftar akun.</p>
                <div className="flex flex-wrap items-center gap-4"><button disabled={form.processing} className="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-900 focus-visible:ring-4 focus-visible:ring-emerald-100 disabled:opacity-50">{form.processing ? 'Menyimpan…' : 'Buat akun'}</button><Link href="/admin/accounts" className="text-sm font-semibold text-slate-600 hover:underline">Batal</Link></div>
            </form>
        </AppLayout>
    );
}
