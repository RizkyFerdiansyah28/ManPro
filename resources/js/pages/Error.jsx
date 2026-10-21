import { Head, Link } from '@inertiajs/react';
import Brand from '../components/Brand';

const messages = { 403: ['Akses dibatasi', 'Akun Anda tidak memiliki izin untuk membuka halaman ini.'], 404: ['Halaman tidak ditemukan', 'Alamat yang Anda buka tidak tersedia.'], 419: ['Sesi telah berakhir', 'Muat ulang halaman atau masuk kembali untuk melanjutkan.'], 429: ['Terlalu banyak permintaan', 'Tunggu sejenak sebelum mencoba kembali.'], 500: ['Layanan sedang bermasalah', 'Silakan coba kembali nanti.'], 503: ['Layanan sedang dirawat', 'Silakan coba kembali beberapa saat lagi.'] };
export default function Error({ status }) {
    const [title, description] = messages[status] ?? messages[500];
    return <main className="flex min-h-screen items-center justify-center bg-[#f5f7f5] px-5 text-slate-900"><Head title={`${title} · Silansia`} /><div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8"><Brand /><p className="mt-12 text-sm font-semibold text-emerald-700">{status}</p><h1 className="mt-3 text-2xl font-bold">{title}</h1><p className="mt-4 text-sm leading-7 text-slate-500">{description}</p><Link href="/" className="mt-8 inline-flex rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Kembali ke beranda</Link></div></main>;
}
