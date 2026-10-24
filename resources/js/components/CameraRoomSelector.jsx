import { useEffect, useState } from 'react';

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
    return <fieldset className="space-y-4" disabled={locked}>
        <legend className="mb-2 text-sm font-semibold">Kamar</legend>
        {current && <label className="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm"><input type="radio" name="room_id" checked={value === current.id} onChange={() => onChange(current.id)} className="accent-emerald-800" /><span>Pertahankan {current.code} · {current.name}{!current.is_active ? ' (nonaktif)' : ''}</span></label>}
        {locked ? <p className="text-xs leading-6 text-slate-500">Kamar dipertahankan karena kamera aktif atau sudah mempunyai riwayat deteksi.</p> : <>
            <label htmlFor="camera-room-search" className="block text-xs font-semibold">Cari kamar aktif tanpa kamera</label>
            <input id="camera-room-search" type="search" maxLength={100} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} className="w-full rounded-xl border border-slate-300 p-3 text-sm" />
            {loading && <p role="status" className="text-sm text-slate-500">Memuat kamar…</p>}
            {failed && <p role="alert" className="text-sm text-red-700">Pilihan kamar tidak tersedia. Muat ulang halaman untuk mencoba kembali.</p>}
            {!loading && result?.data.length === 0 && <p className="text-sm text-slate-500">Tidak ada kamar aktif tanpa kamera yang sesuai.</p>}
            <div className="space-y-2">{result?.data.map((room) => <label key={room.id} className="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm"><input type="radio" name="room_id" checked={value === room.id} onChange={() => onChange(room.id)} className="mt-1 accent-emerald-800" /><span>{room.code} · {room.name}<span className="mt-1 block text-xs text-slate-500">{room.class_name}</span></span></label>)}</div>
            {result && <nav aria-label="Halaman pilihan kamar" className="flex justify-between gap-4 text-xs text-slate-500"><button type="button" disabled={!result.prev_page_url || loading} onClick={() => setPage(page - 1)} className="font-semibold disabled:opacity-40">Sebelumnya</button><span>{result.current_page} / {result.last_page}</span><button type="button" disabled={!result.next_page_url || loading} onClick={() => setPage(page + 1)} className="font-semibold disabled:opacity-40">Berikutnya</button></nav>}
        </>}
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    </fieldset>;
}
