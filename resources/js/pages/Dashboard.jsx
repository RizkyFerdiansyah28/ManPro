import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';

export default function Dashboard({ roleLabel }) {
    const { user } = usePage().props.auth;
    return (
        <AppLayout>
            <Head title={`Beranda ${roleLabel} · Silansia`} />
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-800">Beranda {roleLabel}</p>
            <h1 className="mt-3 text-3xl font-bold tracking-tight">Selamat datang, {user.name}.</h1>
            <p className="mt-3 text-sm text-slate-500">Anda telah masuk sebagai {roleLabel.toLowerCase()}.</p>
            <section className="mt-9 grid gap-8 rounded-2xl border border-slate-200 bg-white p-7 sm:p-9 md:grid-cols-2">
                <div><span className="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">Akun aktif</span><h2 className="mt-5 text-xl font-semibold">Akun Anda</h2><dl className="mt-5 space-y-4 text-sm"><div><dt className="text-slate-500">Nama</dt><dd className="mt-1 font-medium">{user.name}</dd></div><div><dt className="text-slate-500">Email</dt><dd className="mt-1 break-all font-medium">{user.email}</dd></div></dl></div>
                <div className="flex flex-col justify-center rounded-xl bg-[#f5f7f5] p-6">
                    {user.role === 'admin' ? <><h2 className="text-lg font-semibold">Kelola akses pengguna</h2><p className="mt-3 text-sm leading-7 text-slate-600">Buat akun perawat dan keluarga, atur status akses, atau bantu pengguna memperbarui kata sandi.</p><Link href="/admin/accounts" className="mt-6 inline-flex self-start rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Buka kelola akun →</Link></> : <><h2 className="text-lg font-semibold">Sesi Anda siap</h2><p className="mt-3 text-sm leading-7 text-slate-600">Pastikan nama dan email di akun ini sesuai. Jika membutuhkan bantuan akses, hubungi Admin panti.</p></>}
                </div>
            </section>
        </AppLayout>
    );
}
