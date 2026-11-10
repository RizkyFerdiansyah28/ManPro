import { useEffect, useRef, useState } from 'react';

export default function IncidentResidentSelector({ incidentId, selectedId, onSelect, disabled }) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const controller = useRef(null);
    useEffect(() => () => controller.current?.abort(), []);
    async function load(number = 1) {
        controller.current?.abort();
        const request = new AbortController();
        controller.current = request;
        setLoading(true);
        setError('');
        onSelect('');
        setResult(null);
        try {
            const query = new URLSearchParams({ search, page: String(number) });
            const response = await fetch(`/monitoring/incidents/${incidentId}/resident-options?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error('unavailable');
            const data = await response.json();
            if (controller.current !== request) return;
            setResult(data);
            setPage(number);
        } catch (failure) {
            if (failure.name !== 'AbortError' && controller.current === request) setError('Pilihan tidak dapat dimuat. Muat ulang detail jika status insiden berubah.');
        } finally {
            if (controller.current === request) setLoading(false);
        }
    }
    return <fieldset disabled={disabled} className="rounded-xl border border-slate-200 p-4"><legend className="px-1 text-sm font-semibold">Kandidat dari histori kamar</legend>
        <p className="mb-3 text-xs leading-6 text-slate-500">Pilih hanya setelah identifikasi manual. Tidak ada kandidat yang dipilih otomatis. Identitas boleh tetap belum diketahui.</p>
        <div className="flex flex-wrap gap-2"><label className="text-sm">Cari nama<input maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} className="ml-2 rounded-lg border border-slate-300 p-2" /></label><button type="button" onClick={() => load()} disabled={loading} className="rounded-lg border border-slate-300 px-4 py-2 text-sm">{loading ? 'Memuat…' : 'Cari / muat kandidat'}</button></div>
        {error && <p role="alert" className="mt-3 text-sm text-red-800">{error}</p>}
        {result && <><label className="mt-4 block text-sm">Lansia teridentifikasi<select value={selectedId} onChange={(e) => onSelect(e.target.value)} className="mt-2 block w-full rounded-lg border border-slate-300 p-2"><option value="">Pilih secara manual</option>{result.data.map((resident) => <option key={resident.id} value={resident.id}>{resident.name}</option>)}</select></label>
            {!result.data.length && <p role="status" className="mt-3 text-xs text-slate-500">Tidak ada kandidat yang cocok. Gunakan identitas belum diketahui bila bukti tidak cukup.</p>}
            <div className="mt-3 flex gap-4 text-xs"><button type="button" disabled={page <= 1 || loading} onClick={() => load(page - 1)} className="disabled:opacity-40">Sebelumnya</button><span>Halaman {page}</span><button type="button" disabled={!result.has_more || loading} onClick={() => load(page + 1)} className="disabled:opacity-40">Berikutnya</button></div></>}
    </fieldset>;
}
