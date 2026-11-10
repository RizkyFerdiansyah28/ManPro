import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import OperationPagination from '../../../components/OperationPagination';
import IncidentActions from '../../../components/Incidents/IncidentActions';
import formatMonitoringTime from '../../../utils/formatMonitoringTime';
import { incidentStatuses } from './Index';

const actions = { acknowledge: 'Acknowledge', verify: 'Verifikasi jatuh benar', resolve: 'Penyelesaian', false_alarm: 'Alarm palsu' };
export default function Show({ incident, actions: journal }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    function reload() {
        setError('');
        const fail = () => { setError('Pembaruan gagal. Data belum diperbarui; periksa koneksi lalu coba kembali.'); return false; };
        router.reload({ preserveState: false, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Detail insiden · Silansia" />
        <header className="mb-8"><Link href="/monitoring/incidents" className="text-sm font-semibold text-emerald-800">← Seluruh insiden</Link><div className="mt-4 flex flex-wrap justify-between gap-4"><h1 className="text-3xl font-bold">Detail insiden</h1><button type="button" onClick={reload} disabled={loading} className="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50">{loading ? 'Memuat…' : 'Muat ulang detail'}</button></div><p className="mt-3 text-sm text-slate-600">{incidentStatuses[incident.status]} · {incident.is_verified ? 'Jatuh benar terverifikasi manusia' : 'Belum/tidak terverifikasi'}</p></header>
        {error && <p role="alert" className="mb-5 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}</p>}
        <div className="grid gap-6 lg:grid-cols-2"><section className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="mb-5 text-lg font-semibold">Kejadian dan penanganan</h2><dl className="space-y-4 text-sm">{[
            ['Kejadian AI', formatMonitoringTime(incident.occurred_at)], ['Diterima gateway', formatMonitoringTime(incident.received_at)],
            ['Kamera', incident.camera_name], ['Kamar historis', incident.context.room_code ? `${incident.context.room_code} · ${incident.context.room_name}` : 'Tidak diketahui'],
            ['Kelas pada periode', incident.context.class_name || 'Tidak diketahui'], ['Identitas terverifikasi', incident.identity_name || 'Identitas belum diketahui'],
            ['Petugas acknowledge pertama', incident.responder_name || 'Belum ada'], ['Acknowledge', formatMonitoringTime(incident.acknowledged_at)],
            ['Petugas verifikasi', incident.verifier_name || 'Belum ada'], ['Verifikasi', formatMonitoringTime(incident.verified_at)], ['Penutupan', formatMonitoringTime(incident.resolved_at)],
        ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value}</dd></div>)}</dl><p className="mt-5 text-xs leading-6 text-slate-500">Lokasi memakai periode operasi saat kejadian. Label nama/kode berasal dari master saat ini. Lokasi legacy tidak ditebak dari posisi kamera sekarang; identitas belum diketahui tetap sah.</p>
            {incident.legacy_notes && <div className="mt-5 border-t border-slate-100 pt-4"><h3 className="text-sm font-semibold">Catatan legacy tersimpan</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-7">{incident.legacy_notes}</p></div>}
        </section><div className="rounded-2xl border border-slate-200 bg-white p-6"><IncidentActions key={incident.version || incident.status} incident={incident} /></div></div>
        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-lg font-semibold">Jurnal tindakan</h2><p className="mt-2 text-xs leading-6 text-slate-500">Urutan tindakan tersimpan. Histori sebelum jurnal tersedia tidak dibuat ulang.</p>
            {!journal.data.length && <p role="status" className="py-8 text-sm text-slate-600">Belum ada tindakan pada halaman jurnal ini.</p>}
            <ol className="mt-5 divide-y divide-slate-100">{journal.data.map((entry) => <li key={entry.id} className="py-5"><p className="text-sm font-semibold">{entry.sequence}. {actions[entry.action]} · {entry.actor_name}</p><time dateTime={entry.acted_at} className="mt-2 block text-xs text-slate-500">{formatMonitoringTime(entry.acted_at)}</time><p className="mt-2 text-xs text-slate-500">{incidentStatuses[entry.from_status]} → {incidentStatuses[entry.to_status]}</p>{entry.action === 'verify' && <p className="mt-2 text-sm">{entry.identity_name || 'Identitas belum diketahui'}</p>}{entry.notes && <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-7">{entry.notes}</p>}</li>)}</ol>
            <OperationPagination page={journal} label="jurnal tindakan" />
        </section>
    </AppLayout>;
}
