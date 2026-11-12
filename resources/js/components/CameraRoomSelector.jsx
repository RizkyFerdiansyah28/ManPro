import { useEffect, useState } from 'react';
import { ManagementControl } from './Management/ManagementForm';
import Button from './ui/Button';

export default function CameraRoomSelector({ value, onChange, current = null, locked = false, error }) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        if (locked) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true); setFailed(false);
            try {
                const query = new URLSearchParams({ search, page: String(page) });
                const response = await fetch(`/admin/camera-room-options?${query}`, { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('Pilihan tidak tersedia');
                const data = await response.json();
                if (!controller.signal.aborted) setResult(data);
            } catch (failure) { if (failure.name !== 'AbortError') setFailed(true); }
            finally { if (!controller.signal.aborted) setLoading(false); }
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [search, page, locked]);
    return <fieldset className="ui-management-selector" disabled={locked} aria-describedby="camera-room-help camera-room-error" aria-invalid={Boolean(error)}>
        <legend className="ui-management-legend">Kamar</legend>
        {current && <label className="ui-management-choice"><ManagementControl field="room_id" type="radio" name="room_id" checked={value === current.id} onChange={() => onChange(current.id)} /><span>Pertahankan {current.code} · {current.name}{!current.is_active ? ' (nonaktif)' : ''}</span></label>}
        <p id="camera-room-help" className="ui-field-hint">Pilih satu kamar. Kamera yang aktif atau memiliki deteksi mempertahankan kamar tersimpan.</p>
        {locked ? <p className="text-xs leading-6 text-ui-text-secondary">Kamar dipertahankan karena kamera aktif atau sudah mempunyai riwayat deteksi.</p> : <>
            <label htmlFor="camera-room-search" className="ui-field-label">Cari kamar aktif tanpa kamera</label>
            <ManagementControl id="camera-room-search" type="search" maxLength={100} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault(); }} />
            {loading && <p role="status" className="text-sm text-ui-text-secondary">Memuat kamar…</p>}
            {failed && <p role="alert" className="ui-management-note ui-management-note--danger">Pilihan kamar tidak tersedia. Muat ulang halaman untuk mencoba kembali.</p>}
            {!loading && result?.data.length === 0 && <p className="text-sm text-ui-text-secondary">Tidak ada kamar aktif tanpa kamera yang sesuai.</p>}
            <div className="space-y-2">{result?.data.map((room) => <label key={room.id} className="ui-management-choice"><ManagementControl field="room_id" type="radio" name="room_id" checked={value === room.id} onChange={() => onChange(room.id)} /><span>{room.code} · {room.name}<span className="mt-1 block text-xs text-ui-text-secondary">{room.class_name}</span></span></label>)}</div>
            {result && <nav aria-label="Halaman pilihan kamar" className="flex justify-between gap-4 text-xs text-ui-text-secondary"><Button variant="secondary" type="button" disabled={!result.prev_page_url || loading} onClick={() => setPage(page - 1)}>Sebelumnya</Button><span>{result.current_page} / {result.last_page}</span><Button variant="secondary" type="button" disabled={!result.next_page_url || loading} onClick={() => setPage(page + 1)}>Berikutnya</Button></nav>}
        </>}
        <p id="camera-room-error" role={error ? 'alert' : undefined} className="ui-field-error">{error}</p>
    </fieldset>;
}
