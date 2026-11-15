import { useEffect, useId, useRef, useState } from 'react';
import Button from '../ui/Button';

export default function ResidentOptions({ api, value, onChange, disabled }) {
    const id = useId();
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState({ search: '', page: 1, per_page: 15 });
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);
    const seq = useRef(0);
    useEffect(() => {
        if (disabled) return;
        const controller = new AbortController(); const current = ++seq.current;
        setLoading(true); setError(null);
        api.options(filter, controller.signal).then((data) => { if (current === seq.current && !controller.signal.aborted) setResult(data); })
            .catch(() => { if (!controller.signal.aborted && current === seq.current) setError('Daftar lansia belum dapat dimuat. Verifikasi konteks lalu coba pencarian lagi.'); })
            .finally(() => { if (current === seq.current && !controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [api, filter, disabled]);
    return <fieldset disabled={disabled} className="ui-management-selector" aria-busy={loading}>
        <legend className="ui-management-legend">Pilih lansia</legend><div className="ui-management-selector-search"><label htmlFor={id} className="ui-field-label">Cari nama lansia (termasuk nonaktif)</label><input id={id} type="search" maxLength={100} className="ui-field-control" value={search} onChange={(e) => setSearch(e.target.value)} /><Button variant="secondary" onClick={() => setFilter({ ...filter, search, page: 1 })}>Cari lansia</Button></div>
        {loading && <p role="status">Memuat pilihan lansia…</p>}{error && <p role="alert">{error}</p>}
        {!loading && !error && result?.data.length === 0 && <p>Tidak ada lansia yang sesuai.</p>}
        {result?.data.map((r) => <label key={r.id} className="ui-management-choice"><input type="radio" name="invoice-resident" className="ui-management-choice-control" checked={value === r.id} onChange={() => onChange(r.id)} /><span>{r.name_current}{!r.is_active && ' (nonaktif)'}<small className="block break-all">{r.id}</small></span></label>)}
        {value && <p className="ui-field-hint">UUID pilihan: {value}</p>}
        {result && <div className="ui-management-actions"><Button variant="secondary" disabled={filter.page <= 1 || loading} onClick={() => setFilter({ ...filter, page: filter.page - 1 })}>Sebelumnya</Button><span>Halaman {result.current_page} dari {result.last_page}</span><Button variant="secondary" disabled={!result.next_page_url || loading} onClick={() => setFilter({ ...filter, page: filter.page + 1 })}>Berikutnya</Button></div>}
    </fieldset>;
}
