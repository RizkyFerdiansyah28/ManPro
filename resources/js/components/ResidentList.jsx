import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';

export default function ResidentList({ residents, filters, admin = false }) {
    const base = admin ? '/admin/residents' : '/keluarga/residents';
    const form = useForm({ search: filters.search ?? '', status: filters.status ?? '' });
    const submit = (event) => { event.preventDefault(); form.get(base, { preserveState: true, replace: true }); };

    return (
        <AppLayout>
            <Head title={`${admin ? 'Kelola lansia' : 'Lansia keluarga'} · Silansia`} />
            <div className="flex flex-wrap items-end justify-between gap-5">
                <div><p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">{admin ? 'Administrasi' : 'Keluarga'}</p>
                    <h1 className="mt-3 text-3xl font-bold tracking-tight">{admin ? 'Kelola lansia' : 'Lansia keluarga Anda'}</h1>
                    <p className="mt-3 text-sm text-slate-500">{admin ? 'Kelola biodata dan penanggung jawab keluarga.' : 'Daftar lansia yang terhubung dengan akun Anda. Data tersedia untuk dibaca.'}</p>
                </div>
                {admin && <Link href={`${base}/create`} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">+ Tambah lansia</Link>}
            </div>
            <section className="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <form onSubmit={submit} className="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-[2fr_1fr_auto]">
                    <div><label htmlFor="resident-search" className="mb-2 block text-sm font-semibold">Cari nama lansia</label>
                        <input id="resident-search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} className="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" />
                    </div>
                    <div><label htmlFor="resident-status" className="mb-2 block text-sm font-semibold">Status</label>
                        <select id="resident-status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option>
                        </select>
                    </div>
                    <button disabled={form.processing} className="self-end rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold disabled:opacity-50">{form.processing ? 'Mencari…' : 'Terapkan'}</button>
                    {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-red-700 sm:col-span-3">{Object.values(form.errors).join(' ')}</p>}
                </form>
                {residents.data.length ? <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm"><caption className="sr-only">Daftar lansia</caption>
                        <thead className="bg-slate-50 text-xs text-slate-500"><tr><th scope="col" className="px-6 py-4">Nama</th><th scope="col" className="px-6 py-4">Usia</th>{admin && <th scope="col" className="px-6 py-4">Keluarga</th>}<th scope="col" className="px-6 py-4">Status</th><th scope="col" className="px-6 py-4">Tindakan</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{residents.data.map((resident) => <tr key={resident.id}>
                            <td className="px-6 py-5 font-semibold">{resident.name}</td><td className="whitespace-nowrap px-6 py-5">{resident.age} tahun</td>
                            {admin && <td className="px-6 py-5"><span>{resident.family.name}</span>{!resident.family.is_active && <span className="mt-1 block text-xs text-amber-800">Akun nonaktif</span>}</td>}
                            <td className="px-6 py-5"><span className={`rounded-full px-3 py-1 text-xs font-semibold ${resident.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}>{resident.is_active ? 'Aktif' : 'Nonaktif'}</span></td>
                            <td className="px-6 py-5"><Link href={`${base}/${resident.id}`} aria-label={`Lihat ${resident.name}`} className="rounded font-semibold text-emerald-800 hover:underline focus-visible:ring-2 focus-visible:ring-emerald-600">Detail</Link></td>
                        </tr>)}</tbody>
                    </table>
                </div> : <div className="px-6 py-16 text-center"><h2 className="font-semibold">{admin ? 'Belum ada lansia yang sesuai' : 'Belum ada lansia yang dapat ditampilkan'}</h2><p className="mt-3 text-sm text-slate-500">{admin ? 'Ubah filter atau tambahkan data lansia baru.' : 'Ubah filter pencarian. Jika belum ada hubungan keluarga, hubungi Admin panti.'}</p></div>}
                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-6 py-4 text-xs text-slate-500">
                    <p>{residents.total} lansia · Halaman {residents.current_page} dari {residents.last_page}</p>
                    <nav aria-label="Halaman daftar lansia" className="flex gap-4">
                        {residents.prev_page_url ? <Link href={residents.prev_page_url} preserveState className="rounded px-2 py-1 font-semibold text-emerald-800">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}
                        {residents.next_page_url ? <Link href={residents.next_page_url} preserveState className="rounded px-2 py-1 font-semibold text-emerald-800">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}
                    </nav>
                </div>
            </section>
        </AppLayout>
    );
}
