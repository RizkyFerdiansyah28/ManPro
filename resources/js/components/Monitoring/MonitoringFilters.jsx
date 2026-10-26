import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const inputClass = 'mt-2 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm focus:ring-2 focus:ring-emerald-200';
export default function MonitoringFilters({ filters, url, mode = 'records', classes = [] }) {
    const ranged = mode !== 'open';
    const detailed = mode === 'records';
    function initial() {
        return {
            ...(ranged ? { range: filters.range ?? '24h', date_from: filters.date_from ?? '', date_to: filters.date_to ?? '' } : {}),
            ...(mode !== 'dashboard' ? { search: filters.search ?? '', class_code: filters.class_code ?? '', camera_id: filters.camera_id ?? '', room_id: filters.room_id ?? '', per_page: filters.per_page ?? 15 } : {}),
            ...(mode === 'open' ? { status: filters.status ?? '' } : {}),
            ...(detailed ? Object.fromEntries(['event_type', 'fall_status', 'gait_category', 'verification', 'identity', 'location'].map((key) => [key, filters[key] ?? ''])) : {}),
        };
    }
    const form = useForm(initial());
    const pageErrors = usePage().props.errors ?? {};
    const [networkError, setNetworkError] = useState('');
    const signature = JSON.stringify(filters);
    useEffect(() => { form.setData(initial()); }, [signature]);
    const fail = () => { setNetworkError('Filter belum dapat diterapkan. Data masih berasal dari pemuatan sebelumnya. Coba kembali.'); return false; };
    function submit(event) {
        event.preventDefault(); setNetworkError('');
        form.get(url, { preserveState: true, preserveScroll: true, replace: true, onNetworkError: fail, onHttpException: fail });
    }
    function select(id, label, choices, change = (value) => form.setData(id, value)) {
        return <label className="block text-xs font-semibold text-slate-600" htmlFor={id}>{label}<select id={id} value={form.data[id]} onChange={(event) => change(event.target.value)} className={inputClass}>{choices.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>;
    }
    const errors = [...new Set(Object.values({ ...pageErrors, ...form.errors }))];
    return <section aria-label="Filter monitoring" className="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
        <form onSubmit={submit} aria-busy={form.processing}>
            <div className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {ranged && select('range', 'Waktu kejadian', [['24h', '24 jam terakhir'], ['7d', '7 hari terakhir'], ['30d', '30 hari terakhir'], ['custom', 'Tanggal khusus · WIB']], (value) => form.setData({ ...form.data, range: value, date_from: '', date_to: '' }))}
                {ranged && form.data.range === 'custom' && <>{['date_from', 'date_to'].map((key, index) => <label key={key} htmlFor={key} className="block text-xs font-semibold text-slate-600">{index ? 'Sampai tanggal (inklusif)' : 'Dari tanggal'}<input id={key} type="date" required value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} className={inputClass} /></label>)}</>}
                {mode !== 'dashboard' && <>
                    <label htmlFor="search" className="block text-xs font-semibold text-slate-600">Kamera atau kamar historis<input id="search" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} className={inputClass} /></label>
                    {select('class_code', 'Kelas pada periode kejadian', [['', 'Semua kelas'], ...classes.map((item) => [item.code, item.name])])}
                    {select('per_page', 'Data per halaman', [[15, '15 data'], [30, '30 data'], [50, '50 data']])}
                </>}
                {detailed && <>
                    {select('event_type', 'Jenis event', [['', 'Semua jenis'], ['fall', 'Fall'], ['gait', 'Gait']], (value) => form.setData({ ...form.data, event_type: value, fall_status: '', gait_category: '', verification: '' }))}
                    {form.data.event_type === 'fall' && <>{select('fall_status', 'Status insiden', [['', 'Semua status'], ['pending', 'Menunggu tindak lanjut'], ['acknowledged', 'Diakui · belum selesai'], ['resolved', 'Selesai'], ['false_alarm', 'Alarm palsu']])}{select('verification', 'Verifikasi tersimpan', [['', 'Semua'], ['verified', 'Tercatat terverifikasi'], ['unverified', 'Belum/tidak terverifikasi']])}</>}
                    {form.data.event_type === 'gait' && select('gait_category', 'Kategori gait', [['', 'Semua kategori'], ['walking_normal', 'Berjalan normal'], ['walking_abnormal', 'Berjalan tidak normal'], ['no_walking_detected', 'Tidak terdeteksi berjalan']])}
                    {select('identity', 'Atribusi identitas', [['', 'Semua'], ['known', 'Identitas diketahui'], ['unknown', 'Identitas belum diketahui']])}
                    {select('location', 'Konteks historis', [['', 'Semua'], ['tracked', 'Periode tercatat'], ['legacy', 'Legacy · tanpa periode'], ['unavailable', 'Konteks tidak konsisten']])}
                </>}
                {mode === 'open' && select('status', 'Insiden terbuka · seluruh waktu', [['', 'Pending dan acknowledged'], ['pending', 'Menunggu tindak lanjut'], ['acknowledged', 'Diakui · belum selesai']])}
            </div>
            {['camera_id', 'room_id'].filter((key) => form.data[key]).map((key) => <p key={key} className="mt-4 break-all text-xs text-slate-600">{key === 'camera_id' ? 'Filter kamera' : 'Filter kamar historis'}: {form.data[key]} <button type="button" onClick={() => form.setData(key, '')} className="ml-2 font-semibold text-emerald-800 underline">Hapus lalu terapkan</button></p>)}
            <div className="mt-5 flex flex-wrap items-center gap-4"><button disabled={form.processing} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white focus-visible:ring-4 focus-visible:ring-emerald-100 disabled:opacity-50">{form.processing ? 'Memuat…' : 'Terapkan filter'}</button><button type="button" disabled={form.processing} onClick={() => router.get(url)} className="text-sm font-semibold text-emerald-800 hover:underline">Reset filter</button><p className="text-xs text-slate-500">{ranged ? 'Berdasarkan waktu kejadian. Tanggal khusus maksimal 90 hari.' : 'Seluruh umur insiden; tidak mengikuti filter waktu dashboard.'}</p></div>
            {errors.length > 0 && <div role="alert" className="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{errors.map((error) => <p key={error}>{error}</p>)}</div>}
            {networkError && <p role="alert" className="mt-4 text-sm text-red-800">{networkError}</p>}
        </form>
    </section>;
}
