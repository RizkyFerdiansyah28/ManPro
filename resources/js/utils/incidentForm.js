export const incidentActionTitles = {
    acknowledge: 'Akui dan mulai penanganan',
    verify: 'Verifikasi jatuh benar',
    resolve: 'Selesaikan penanganan',
    false_alarm: 'Tandai alarm palsu',
};

export const incidentActionDescriptions = {
    acknowledge: 'Mencatat petugas acknowledge pertama dan memulai penanganan. Tindakan ini belum memverifikasi dugaan jatuh.',
    verify: 'Mencatat verifikasi manual dan identitas bila diketahui. Insiden tetap terbuka sampai penanganan diselesaikan.',
    resolve: 'Menutup penanganan insiden yang sudah terverifikasi. Insiden tidak dapat dibuka atau diedit kembali.',
    false_alarm: 'Menutup insiden sebagai alarm palsu sebelum verifikasi. Insiden tidak dapat dibuka atau diedit kembali.',
};

export function incidentActionsFor(incident, role) {
    return role === 'perawat' ? incident.allowed_actions.filter((action) => Object.hasOwn(incidentActionTitles, action)) : [];
}

export function incidentRequestId() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (n) => n.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
}

export function initialIncidentActionData(incident, action, requestId = incidentRequestId) {
    return {
        request_id: requestId(), expected_version: incident.version, confirm: false, notes: '',
        ...(action === 'verify' ? { identity_mode: 'unknown', elderly_id: '' } : {}),
    };
}

export function changeIncidentActionData(previous, field, value, requestId = incidentRequestId) {
    return { ...previous, [field]: value, ...(field === 'identity_mode' ? { elderly_id: '' } : {}), request_id: requestId() };
}

export function hasUnsavedIncidentInput(data) {
    return data.notes !== '' || data.confirm || (data.identity_mode !== undefined && data.identity_mode !== 'unknown') || Boolean(data.elderly_id);
}

export const discardIncidentInputMessage = 'Catatan atau pilihan tindakan belum disimpan. Jika dilanjutkan, isian tersebut akan dibuang. Tetap lanjutkan?';

export function switchIncidentAction({ current, next, allowed, data, processing, confirm, onChange }) {
    if (processing || next === current || !allowed.includes(next)) return false;
    if (hasUnsavedIncidentInput(data) && !confirm(discardIncidentInputMessage)) return false;
    onChange(next);
    return true;
}

export function incidentActionUrl(incident, action) {
    return incident.detail_url + '/' + action.replace('_', '-');
}

export function searchIncidentCandidatesOnEnter(event, load, blocked) {
    if (event.key !== 'Enter' || event.isComposing) return false;
    event.preventDefault();
    if (!blocked) load();
    return true;
}

export function submitIncidentAction({ form, incident, action, lock, onFailure, onError, onSuccess, onFinish }) {
    if (lock.current || form.processing || !incident.allowed_actions.includes(action) || !Object.hasOwn(incidentActionTitles, action)) return false;
    lock.current = true;
    const fail = () => { onFailure(); return false; };
    try {
        form.post(incidentActionUrl(incident, action), {
            preserveScroll: true, onNetworkError: fail, onHttpException: fail,
            onError, onSuccess,
            onFinish: () => { lock.current = false; onFinish?.(); },
        });
    } catch (error) {
        lock.current = false;
        throw error;
    }
    return true;
}
