import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import IncidentResidentSelector from './IncidentResidentSelector';

const titles = { acknowledge: 'Akui dan mulai penanganan', verify: 'Verifikasi jatuh benar', resolve: 'Selesaikan penanganan', false_alarm: 'Tandai alarm palsu' };
function requestId() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (n) => n.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function ActionForm({ incident, action }) {
    const form = useForm({ request_id: requestId(), expected_version: incident.version, confirm: false, notes: '',
        ...(action === 'verify' ? { identity_mode: 'unknown', elderly_id: '' } : {}) });
    const [failure, setFailure] = useState('');
    function change(field, value) {
        form.setData((previous) => ({ ...previous, [field]: value, ...(field === 'identity_mode' ? { elderly_id: '' } : {}), request_id: requestId() }));
    }
    function submit(event) {
        event.preventDefault();
        setFailure('');
        const fail = () => { setFailure('Respons belum dapat dipastikan. Muat ulang detail untuk memeriksa hasil, atau kirim ulang intent yang sama tanpa mengubah isian.'); return false; };
        form.post(`${incident.detail_url}/${action.replace('_', '-')}`, { preserveScroll: true, onNetworkError: fail, onHttpException: fail });
    }
    return <form onSubmit={submit} className="mt-5 space-y-5"><fieldset disabled={form.processing} className="space-y-5">
        <legend className="font-semibold">{titles[action]}</legend>
        {action === 'verify' && <><label className="block text-sm">Identitas<select value={form.data.identity_mode} onChange={(e) => change('identity_mode', e.target.value)} className="mt-2 block w-full rounded-lg border border-slate-300 p-3"><option value="unknown">Identitas belum diketahui</option><option value="known">Lansia teridentifikasi secara manual</option></select></label>
            {form.data.identity_mode === 'known' && <IncidentResidentSelector incidentId={incident.id} selectedId={form.data.elderly_id} onSelect={(id) => change('elderly_id', id)} disabled={form.processing} />}</>}
        <label className="block text-sm">Catatan penanganan {action === 'acknowledge' ? '(opsional)' : '(wajib)'}<textarea value={form.data.notes} onChange={(e) => change('notes', e.target.value)} required={action !== 'acknowledge'} maxLength={2000} rows={4} className="mt-2 block w-full rounded-xl border border-slate-300 p-3" aria-describedby="incident-notes-help" /></label>
        <p id="incident-notes-help" className="text-xs leading-6 text-slate-500">Maksimal 2.000 karakter. Catat penanganan seperlunya; hindari credential dan informasi pribadi yang tidak diperlukan. Catatan tindakan tidak dapat diedit setelah tersimpan.</p>
        <label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" checked={form.data.confirm} onChange={(e) => change('confirm', e.target.checked)} required className="mt-1" /><span>Saya mengonfirmasi tindakan manual ini. {['resolve', 'false_alarm'].includes(action) && 'Insiden akan ditutup dan tidak dapat dibuka kembali.'}</span></label>
        {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-800">{Object.entries(form.errors).map(([key, error]) => <p key={key}>{error}</p>)}</div>}
        {failure && <p role="alert" className="text-sm text-red-800">{failure}</p>}
        <button type="submit" disabled={form.processing} aria-busy={form.processing} className="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Menyimpan…' : titles[action]}</button>
    </fieldset></form>;
}

export default function IncidentActions({ incident }) {
    const [action, setAction] = useState(incident.allowed_actions[0] || '');
    if (!incident.allowed_actions.length) return <p className="text-sm leading-7 text-slate-600">Tidak ada tindakan yang tersedia untuk akun dan status ini. Histori tetap dapat dibaca.</p>;
    return <section><h2 className="text-lg font-semibold">Tindakan Perawat</h2><p className="mt-2 text-sm leading-7 text-slate-600">Perawat aktif dapat melanjutkan penanganan bersama. Petugas acknowledge pertama tetap tercatat.</p>
        <label className="mt-4 block text-sm">Pilih tindakan<select value={action} onChange={(e) => setAction(e.target.value)} className="mt-2 block w-full rounded-lg border border-slate-300 p-3">{incident.allowed_actions.map((name) => <option key={name} value={name}>{titles[name]}</option>)}</select></label>
        <ActionForm key={`${incident.version}:${action}`} incident={incident} action={action} />
    </section>;
}
