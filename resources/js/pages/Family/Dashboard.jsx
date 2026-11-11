import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import { FamilyServiceStatus } from '../../components/Family/FamilyServiceSummary';
import FamilyRefresh from '../../components/Family/FamilyRefresh';

export default function Dashboard({ summary, residents, as_of }) {
    const { auth } = usePage().props;
    const cards = [['residents', 'Lansia terkait'], ['active_residents', 'Lansia berstatus aktif'], ['staying', 'Masa tinggal tercatat'], ['enrolled', 'Enrollment tercatat'], ['needs_review', 'Layanan perlu pemeriksaan']];
    return <AppLayout><Head title="Beranda Keluarga · Silansia" />
        <p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">Beranda Keluarga</p>
        <h1 className="mt-3 text-3xl font-bold">Selamat datang, {auth.user.name}.</h1>
        <p className="mt-3 max-w-3xl text-sm leading-7 text-slate-600">Informasi lansia yang saat ini terhubung dengan akun Anda. Data layanan dibaca saat halaman dimuat; ini bukan pemantauan kondisi secara langsung.</p>
        <FamilyRefresh asOf={as_of} />
        <section aria-label="Ringkasan lansia Anda" className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{cards.map(([key, title]) => <div key={key} className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-sm text-slate-600">{title}</h2><p className="mt-3 text-3xl font-semibold">{summary[key]}</p></div>)}</section>
        {summary.needs_review > 0 && <p className="mt-5 rounded-xl bg-amber-50 p-5 text-sm leading-7 text-amber-950">Sebagian informasi layanan perlu diperiksa Admin. Jumlah masa tinggal/enrollment di atas hanya mencakup informasi yang dapat dipastikan.</p>}
        <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6">
            <div className="flex flex-wrap justify-between gap-4"><h2 className="text-lg font-semibold">Lansia keluarga Anda</h2><Link href="/keluarga/residents" className="text-sm font-semibold text-emerald-800 hover:underline">Lihat daftar lengkap →</Link></div>
            {residents.length ? <ul className="mt-4 divide-y divide-slate-100">{residents.map((r) => <li key={r.id} className="flex flex-wrap justify-between gap-4 py-4"><div><p className="break-words font-semibold">{r.name}</p><p className="mt-2 text-xs"><FamilyServiceStatus service={r.service} /></p></div><Link href={'/keluarga/residents/' + r.id} className="text-sm font-semibold text-emerald-800 hover:underline" aria-label={'Lihat ' + r.name}>Detail →</Link></li>)}</ul> : <p className="py-8 text-sm text-slate-500">Belum ada lansia yang terhubung dengan akun Anda. Hubungi Admin panti bila hubungan keluarga perlu diperbarui.</p>}
            {summary.residents > residents.length && <p className="mt-4 text-xs text-slate-500">Menampilkan {residents.length} dari {summary.residents} lansia. Buka daftar lengkap untuk melihat lainnya.</p>}
        </section>
        <p className="mt-6 text-xs leading-6 text-slate-500">Histori insiden, laporan gait, notifikasi keluarga, pembayaran, dan live CCTV belum tersedia di portal ini.</p>
    </AppLayout>;
}

