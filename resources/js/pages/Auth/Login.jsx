import { Head, useForm } from '@inertiajs/react';
import Brand from '../../components/Brand';
import FormField from '../../components/FormField';

export default function Login() {
    const form = useForm({ email: '', password: '' });
    function submit(event) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }
    return (
        <div className="flex min-h-screen items-center justify-center bg-[#eef3ee] p-5 text-slate-900 sm:p-10">
            <Head title="Masuk · Silansia" />
            <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-white bg-white shadow-xl shadow-emerald-950/5 lg:grid-cols-2">
                <aside className="flex flex-col justify-between bg-emerald-950 px-8 py-10 text-white sm:p-12">
                    <Brand />
                    <div className="py-10 lg:py-20"><p className="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300">Selamat datang</p><h1 className="max-w-sm text-3xl font-semibold leading-tight sm:text-4xl">Akses yang aman,<br />untuk kepedulian setiap hari.</h1><p className="mt-6 max-w-sm text-sm leading-7 text-emerald-100/80">Masuk ke Silansia menggunakan akun yang telah diaktifkan oleh Admin panti.</p></div>
                    <p className="max-w-sm text-xs leading-6 text-emerald-200/70">Jaga kerahasiaan kata sandi Anda. Keluar dari akun setelah menggunakan perangkat bersama.</p>
                </aside>
                <main className="px-8 py-10 sm:p-12 lg:py-20">
                    <h2 className="text-2xl font-bold tracking-tight">Masuk ke akun</h2><p className="mt-3 text-sm text-slate-500">Silakan masukkan email dan kata sandi Anda.</p>
                    <form onSubmit={submit} className="mt-9 space-y-6" aria-label="Form masuk">
                        <FormField id="email" label="Email" type="email" autoComplete="username" autoFocus required maxLength={254} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} />
                        <FormField id="password" label="Kata sandi" type="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                        <button disabled={form.processing} className="w-full rounded-xl bg-emerald-800 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-emerald-900 focus-visible:ring-4 focus-visible:ring-emerald-200 disabled:cursor-wait disabled:opacity-60">{form.processing ? 'Memeriksa akun…' : 'Masuk'}</button>
                    </form>
                    <div className="mt-8 border-t border-slate-100 pt-6 text-sm leading-6 text-slate-500">Perlu bantuan masuk?<br /><span className="font-medium text-slate-700">Hubungi Admin panti untuk bantuan akun.</span></div>
                </main>
            </div>
        </div>
    );
}
