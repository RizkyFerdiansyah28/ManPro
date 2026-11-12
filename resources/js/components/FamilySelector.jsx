import { useEffect, useRef, useState } from 'react';
import { ManagementControl } from './Management/ManagementForm';
import Button from './ui/Button';

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
        <fieldset disabled={disabled} className="ui-management-selector" aria-describedby="family-selector-help family-selector-error" aria-invalid={Boolean(error)}>
            <legend className="ui-management-legend">Akun keluarga penanggung jawab <span className="ui-field-required" aria-hidden="true">Wajib</span></legend>
            <p id="family-selector-help" className="text-xs leading-6 text-ui-text-secondary">Hanya akun keluarga aktif yang dapat dipilih. Cari nama atau email; hasil dibatasi 15 akun per halaman.</p>
            <div className="ui-management-selector-search">
                <label htmlFor="family-search" className="sr-only">Cari keluarga</label>
                <ManagementControl id="family-search" type="search" maxLength={100} value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); load(); } }}
                    placeholder="Nama atau email keluarga" />
                <Button variant="secondary" type="button" onClick={() => load()} disabled={loading}>Cari</Button>
            </div>
            {selected?.id === value && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Dipilih: {selected.name} ({selected.email})</p>}
            <div aria-busy={loading} className="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
                {loading ? <p role="status" className="p-3 text-sm text-ui-text-secondary">Memuat keluarga…</p> : result?.data.length ? result.data.map((family) => (
                    <label key={family.id} className="ui-management-choice">
                        <ManagementControl field="family_id" type="radio" name="family-option" value={family.id} checked={value === family.id}
                            onChange={() => { setSelected(family); onChange(family.id); }} />
                        <span className="min-w-0"><span className="block font-semibold">{family.name}</span><span className="block break-all text-xs text-ui-text-secondary">{family.email}</span></span>
                    </label>
                )) : <p className="p-3 text-sm text-ui-text-secondary">Tidak ada keluarga aktif yang sesuai. Admin dapat menyiapkan akun melalui Kelola akun.</p>}
            </div>
            {result && <div className="ui-management-actions">
                <span>Halaman {result.current_page} dari {result.last_page}</span>
                <div className="ui-management-actions">
                    <Button variant="secondary" type="button" disabled={loading || !result.prev_page_url} onClick={() => load(result.current_page - 1, resultQuery)}>Sebelumnya</Button>
                    <Button variant="secondary" type="button" disabled={loading || !result.next_page_url} onClick={() => load(result.current_page + 1, resultQuery)}>Berikutnya</Button>
                </div>
            </div>}
            {failure && <p role="alert" className="ui-management-note ui-management-note--danger">{failure}</p>}
            <p id="family-selector-error" role={error ? 'alert' : undefined} className="ui-field-error">{error}</p>
        </fieldset>
    );
}
