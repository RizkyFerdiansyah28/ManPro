import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import OperationPagination from '../../../components/OperationPagination';
import { FamilyServiceStatus } from '../../../components/Family/FamilyServiceSummary';
import FamilyRefresh from '../../../components/Family/FamilyRefresh';

export default function Index({ residents, filters, as_of, errors }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function submit(event) {
        event.preventDefault();
        setError('');
        const fail = () => { setError('Daftar belum dapat dimuat. Coba kembali.'); return false; };
        router.get('/keluarga/residents', { search, status, per_page: perPage }, { preserveState: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Lansia keluarga · Silansia" /><h1 className="text-3xl font-bold">Lansia keluarga Anda</h1>
        <p className="mt-3 text-sm text-slate-500">Data lansia yang saat ini terhubung dengan akun Anda tersedia untuk dibaca.</p>
        <FamilyRefresh asOf={as_of} />
        {(error || Object.values(errors || {}).length > 0) && <div role="alert" className="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}{Object.values(errors || {}).map((v, i) => <p key={i}>{v}</p>)}</div>}
        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
            <form onSubmit={submit} className="flex flex-wrap items-end gap-4 border-b border-slate-200 pb-5">
                <label className="text-sm">Cari nama lansia<input type="search" maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} className="mt-2 block w-full rounded-lg border border-slate-300 p-2" /></label>
                <label className="text-sm">Status lansia<select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2"><option value="">Semua</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></label>
                <label className="text-sm">Per halaman<select value={perPage} onChange={(e) => setPerPage(e.target.value)} className="mt-2 block rounded-lg border border-slate-300 p-2">{[15, 30, 50].map((n) => <option key={n}>{n}</option>)}</select></label>
                <button disabled={loading} className="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{loading ? 'Memuat…' : 'Terapkan'}</button>
            </form>
            {residents.data.length ? <ul className="divide-y divide-slate-100">{residents.data.map((r) => <li key={r.id} className="flex flex-wrap items-start justify-between gap-4 py-5"><div><h2 className="break-words font-semibold">{r.name}</h2><p className="mt-2 text-xs text-slate-500">{r.age} tahun · {r.is_active ? 'Lansia aktif' : 'Lansia nonaktif'}</p><p className="mt-2 text-xs"><FamilyServiceStatus service={r.service} /></p></div><Link href={'/keluarga/residents/' + r.id} aria-label={'Lihat ' + r.name} className="rounded px-2 py-1 text-sm font-semibold text-emerald-800 hover:underline focus-visible:ring-2 focus-visible:ring-emerald-600">Detail →</Link></li>)}</ul> : <p role="status" className="py-12 text-sm leading-7 text-slate-500">Belum ada lansia yang dapat ditampilkan. Ubah filter atau hubungi Admin panti bila hubungan keluarga perlu diperbarui.</p>}
            <OperationPagination page={residents} label="lansia keluarga" />
        </section>
    </AppLayout>;
}
