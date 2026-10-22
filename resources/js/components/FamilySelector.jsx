import { useEffect, useRef, useState } from 'react';

export default function FamilySelector({ value, onChange, error, disabled = false }) {
    const [search, setSearch] = useState('');
    const [result, setResult] = useState(null);
    const [resultQuery, setResultQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState('');
    const [selected, setSelected] = useState(null);
    const controller = useRef(null);

    async function load(page = 1, query = search) {
        controller.current?.abort();
        const request = new AbortController();
        controller.current = request;
        setLoading(true);
        setFailure('');
        try {
            const response = await fetch(`/admin/family-options?${new URLSearchParams({ search: query, page })}`, {
                credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal,
            });
            if (response.redirected || response.status === 401) {
                window.location.assign('/login');
                return;
            }
            if (!response.ok) throw new Error('Pencarian tidak dapat dimuat.');
            const data = await response.json();
            if (!request.signal.aborted) {
                setResult(data);
                setResultQuery(query);
            }
        } catch (exception) {
            if (exception.name !== 'AbortError') setFailure('Pencarian keluarga gagal. Coba kembali atau muat ulang halaman.');
        } finally {
            if (!request.signal.aborted) setLoading(false);
        }
    }

    useEffect(() => {
        load(1, '');
        return () => controller.current?.abort();
    }, []);

    return (
        <fieldset disabled={disabled} className="space-y-3" aria-describedby="family-selector-help family-selector-error">
            <legend className="mb-2 text-sm font-semibold text-slate-800">Akun keluarga penanggung jawab</legend>
            <p id="family-selector-help" className="text-xs leading-6 text-slate-500">Hanya akun keluarga aktif yang dapat dipilih. Cari nama atau email; hasil dibatasi 15 akun per halaman.</p>
            <div className="flex gap-2">
                <label htmlFor="family-search" className="sr-only">Cari keluarga</label>
                <input id="family-search" type="search" maxLength={100} value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); load(); } }}
                    placeholder="Nama atau email keluarga" className="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm" />
                <button type="button" onClick={() => load()} disabled={loading} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-50">Cari</button>
            </div>
            {selected?.id === value && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Dipilih: {selected.name} ({selected.email})</p>}
            <div aria-busy={loading} className="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
                {loading ? <p role="status" className="p-3 text-sm text-slate-500">Memuat keluarga…</p> : result?.data.length ? result.data.map((family) => (
                    <label key={family.id} className={`flex cursor-pointer items-start gap-3 rounded-lg p-3 text-sm ${value === family.id ? 'bg-emerald-50' : 'hover:bg-slate-50'}`}>
                        <input type="radio" name="family-option" value={family.id} checked={value === family.id}
                            onChange={() => { setSelected(family); onChange(family.id); }} className="mt-1 accent-emerald-800" />
                        <span className="min-w-0"><span className="block font-semibold">{family.name}</span><span className="block break-all text-xs text-slate-500">{family.email}</span></span>
                    </label>
                )) : <p className="p-3 text-sm text-slate-500">Tidak ada keluarga aktif yang sesuai. Admin dapat menyiapkan akun melalui Kelola akun.</p>}
            </div>
            {result && <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                <span>Halaman {result.current_page} dari {result.last_page}</span>
                <div className="flex gap-3">
                    <button type="button" disabled={loading || !result.prev_page_url} onClick={() => load(result.current_page - 1, resultQuery)} className="rounded px-2 py-1 font-semibold text-emerald-800 disabled:text-slate-300">Sebelumnya</button>
                    <button type="button" disabled={loading || !result.next_page_url} onClick={() => load(result.current_page + 1, resultQuery)} className="rounded px-2 py-1 font-semibold text-emerald-800 disabled:text-slate-300">Berikutnya</button>
                </div>
            </div>}
            {failure && <p role="alert" className="text-sm text-red-700">{failure}</p>}
            <p id="family-selector-error" role={error ? 'alert' : undefined} className="text-sm text-red-700">{error}</p>
        </fieldset>
    );
}
