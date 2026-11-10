import { Link } from '@inertiajs/react';
import useNotificationSummary from '../../hooks/useNotificationSummary';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

export default function NotificationBell({ actorId }) {
    const summary = useNotificationSummary(actorId);
    return <section aria-label="Ringkasan notifikasi staf" className="mx-auto max-w-6xl px-5 pb-4 text-sm sm:px-8">
        <div className="flex flex-wrap items-center gap-4">
            <Link href="/notifications" className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 font-semibold text-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500">
                <span aria-hidden="true">🔔</span> Notifikasi saya <span className="rounded-full bg-emerald-50 px-2 py-0.5">{summary.data?.unread_count ?? '…'}<span className="sr-only"> belum dibaca</span></span>
            </Link>
            <p role="status" className="text-xs text-slate-600">{summary.data ? `${summary.data.pending_count} insiden pending belum diakui${summary.stale ? ' · data sebelumnya' : ''}` : 'Jumlah pending belum tersedia.'}</p>
            <Link href="/monitoring/open-incidents?status=pending" className="text-xs font-semibold text-amber-900 hover:underline">Buka antrean pending →</Link>
            <button type="button" onClick={summary.refresh} disabled={summary.loading || summary.expired} className="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 underline disabled:opacity-50">{summary.loading ? 'Memperbarui…' : 'Muat ulang ringkasan'}</button>
        </div>
        <p className="mt-2 text-xs text-slate-500">{summary.data ? `Diperbarui ${formatMonitoringTime(summary.data.as_of)}. ` : ''}Pembaruan 15 detik saat tab aktif dan online; tidak mengubah form penanganan.</p>
        {summary.error && <p role="status" className="mt-2 text-xs text-red-800">{summary.error} {summary.expired && <Link href="/login" className="font-semibold underline">Masuk kembali</Link>}</p>}
    </section>;
}
