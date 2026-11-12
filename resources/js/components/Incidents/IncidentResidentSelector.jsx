import { useEffect, useId, useRef, useState } from 'react';
import Button from '../ui/Button';
import FormField from '../FormField';
import { searchIncidentCandidatesOnEnter } from '../../utils/incidentForm';

export default function IncidentResidentSelector({ incidentId, selectedId, onSelect, disabled, inputId, error: fieldError }) {
    const id = useId();
    const selectId = inputId ?? id + '-resident';
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
            const response = await fetch('/monitoring/incidents/' + incidentId + '/resident-options?' + query, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error('unavailable');
            const data = await response.json();
            if (!Array.isArray(data.data)) throw new Error('unavailable');
            if (controller.current !== request) return;
            setResult(data);
            setPage(number);
        } catch (failure) {
            if (failure.name !== 'AbortError' && controller.current === request) setError('Pilihan tidak dapat dimuat. Muat ulang detail jika status insiden berubah.');
        } finally {
            if (controller.current === request) setLoading(false);
        }
    }
    return <fieldset disabled={disabled} className="ui-incident-resident-selector">
        <legend>Kandidat dari histori kamar</legend>
        <p className="ui-monitoring-copy">Pilih hanya setelah identifikasi manual. Tidak ada kandidat yang dipilih otomatis. Identitas boleh tetap belum diketahui.</p>
        <div className="ui-incident-resident-search">
            <FormField id={id + '-search'} name="candidate-search" label="Cari nama" maxLength={100} value={search} onChange={(event) => setSearch(event.target.value)} autoComplete="off" onKeyDown={(event) => searchIncidentCandidatesOnEnter(event, () => load(), loading || disabled)} />
            <Button variant="secondary" onClick={() => load()} processing={loading}>{loading ? 'Memuat…' : 'Cari / muat kandidat'}</Button>
        </div>
        {error && <p role="alert" className="ui-incident-error">{error}</p>}
        {result && <>
            <div className="ui-field-group">
                <label htmlFor={selectId} className="ui-field-label">Lansia teridentifikasi</label>
                <select id={selectId} name="elderly_id" value={selectedId} onChange={(event) => onSelect(event.target.value)} aria-invalid={Boolean(fieldError)} aria-describedby={fieldError ? selectId + '-error' : undefined} className={'ui-field-control ' + (fieldError ? 'ui-field-control--invalid' : '')}>
                    <option value="">Pilih secara manual</option>{result.data.map((resident) => <option key={resident.id} value={resident.id}>{resident.name}</option>)}
                </select>
            </div>
            {!result.data.length && <p role="status" className="ui-monitoring-copy">Tidak ada kandidat yang cocok. Gunakan identitas belum diketahui bila bukti tidak cukup.</p>}
            <div className="ui-incident-resident-pages">
                <Button variant="secondary" disabled={page <= 1 || loading} onClick={() => load(page - 1)}>Sebelumnya</Button><span>Halaman {page}</span>
                <Button variant="secondary" disabled={!result.has_more || loading} onClick={() => load(page + 1)}>Berikutnya</Button>
            </div>
        </>}
        {fieldError && <p id={selectId + '-error'} className="ui-field-error">{fieldError}</p>}
    </fieldset>;
}
