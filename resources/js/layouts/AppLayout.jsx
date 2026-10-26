import { Link, useForm, usePage } from '@inertiajs/react';
import Brand from '../components/Brand';

const roles = { admin: 'Admin', perawat: 'Perawat', keluarga: 'Keluarga' };

export default function AppLayout({ children }) {
    const { auth, flash } = usePage().props;
    const user = auth.user;
    const logout = useForm({});

    return (
        <div className="min-h-screen bg-[#f5f7f5] text-slate-900">
            <a href="#main-content" className="sr-only z-50 rounded-lg bg-white p-3 focus:not-sr-only focus:absolute">Langsung ke konten</a>
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-5 px-5 py-5 sm:px-8">
                    <Brand href={user.home_url} />
                    <nav aria-label="Navigasi utama" className="flex flex-wrap items-center gap-x-5 gap-y-3 text-sm font-medium">
                        <Link href={user.home_url} className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Beranda</Link>
                        {['admin', 'perawat'].includes(user.role) && <><Link href="/monitoring/records" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Rekaman</Link><Link href="/monitoring/open-incidents" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Insiden terbuka</Link></>}
                        {user.role === 'admin' && <Link href="/admin/accounts" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Kelola akun</Link>}
                        {user.role === 'admin' && <Link href="/admin/residents" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Kelola lansia</Link>}
                        {user.role === 'admin' && <Link href="/admin/service-classes" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Kelas layanan</Link>}
                        {user.role === 'admin' && <Link href="/admin/rooms" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Kamar</Link>}
                        {user.role === 'admin' && <Link href="/admin/cameras" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Kamera</Link>}
                        {user.role === 'keluarga' && <Link href="/keluarga/residents" className="rounded text-slate-600 hover:text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">Lansia keluarga</Link>}
                    </nav>
                    <div className="flex items-center gap-4">
                        <div className="text-right"><p className="max-w-48 truncate text-sm font-semibold">{user.name}</p><p className="text-xs text-slate-500">{roles[user.role]}</p></div>
                        <button type="button" onClick={() => logout.post('/logout')} disabled={logout.processing} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold transition hover:bg-slate-50 focus-visible:ring-4 focus-visible:ring-emerald-100 disabled:opacity-50">{logout.processing ? 'Keluar…' : 'Keluar'}</button>
                    </div>
                </div>
            </header>
            <main id="main-content" className="mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-12">
                {flash?.status && <div role="status" className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-900">{flash.status}</div>}
                {children}
            </main>
            <footer className="mx-auto max-w-6xl px-5 pb-8 text-xs text-slate-500 sm:px-8">Silansia · Akses layanan panti lansia</footer>
        </div>
    );
}
