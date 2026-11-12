import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import IncidentResidentSelector from './IncidentResidentSelector';
import Button from '../ui/Button';
import StatusBadge from '../ui/StatusBadge';
import focusFormErrors from '../../utils/focusFormErrors';
import { incidentActionsFor, incidentActionTitles, incidentActionDescriptions, initialIncidentActionData, changeIncidentActionData, hasUnsavedIncidentInput, switchIncidentAction, submitIncidentAction, incidentActionUrl } from '../../utils/incidentForm';
import { protectUnsavedIncidentInput } from '../../utils/unsavedIncidentInput';

function ActionForm({ incident, action, allowed, onActionChange, includePageErrors }) {
    const form = useForm(() => initialIncidentActionData(incident, action));
    const pageErrors = usePage().props.errors ?? {};
    const errors = { ...(includePageErrors ? pageErrors : {}), ...form.errors };
    const id = useId();
    const formRef = useRef(null);
    const summaryRef = useRef(null);
    const failureRef = useRef(null);
    const lock = useRef(false);
    const focusPending = useRef(false);
    const saved = useRef(false);
    const [failure, setFailure] = useState('');
    const dirty = !saved.current && hasUnsavedIncidentInput(form.data);
    const current = useRef(null);
    current.current = { dirty, data: form.data, url: incidentActionUrl(incident, action) };
    const errorSignature = JSON.stringify(errors);

    useEffect(() => protectUnsavedIncidentInput({
        router, windowTarget: window, current: () => current.current, confirm: (message) => window.confirm(message),
    }), []);
    useEffect(() => {
        if (focusPending.current && !form.processing && Object.keys(errors).length) {
            focusFormErrors(formRef.current, summaryRef.current, errors);
            focusPending.current = false;
        }
    }, [errorSignature, form.processing]);
    useEffect(() => { if (failure) failureRef.current?.focus(); }, [failure]);

    function change(field, value) {
        saved.current = false;
        form.setData((previous) => changeIncidentActionData(previous, field, value));
    }
    function submit(event) {
        event.preventDefault();
        if (form.processing || lock.current) return;
        setFailure('');
        submitIncidentAction({
            form, incident, action, lock,
            onFailure: () => setFailure('Hasil penyimpanan belum dapat dipastikan. Muat ulang detail untuk memeriksa hasil, atau coba kirim ulang tanpa mengubah isian.'),
            onError: () => { focusPending.current = true; },
            onSuccess: () => { saved.current = true; current.current.dirty = false; },
        });
    }
    function changeAction(next) {
        switchIncidentAction({
            current: action, next, allowed, data: form.data, processing: form.processing || lock.current,
            confirm: (message) => window.confirm(message), onChange: onActionChange,
        });
    }
    return (
        <form ref={formRef} onSubmit={submit} aria-busy={form.processing} className="ui-incident-action-form">
            <fieldset disabled={form.processing}>
                <legend className="ui-incident-action-form__legend">Tindakan berikutnya</legend>
                <div className="ui-field-group">
                    <label htmlFor={id + '-action'} className="ui-field-label">Pilih tindakan</label>
                    <select id={id + '-action'} name="action-choice" value={action} onChange={(event) => changeAction(event.target.value)} className="ui-field-control">
                        {allowed.map((name) => <option key={name} value={name}>{incidentActionTitles[name]}</option>)}
                    </select>
                </div>
                <div className="ui-incident-action-description"><h3>{incidentActionTitles[action]}</h3><p>{incidentActionDescriptions[action]}</p></div>
                {action === 'verify' && <>
                    <div className="ui-field-group">
                        <label htmlFor={id + '-identity'} className="ui-field-label">Identitas</label>
                        <select id={id + '-identity'} name="identity_mode" value={form.data.identity_mode} onChange={(event) => change('identity_mode', event.target.value)} aria-invalid={Boolean(errors.identity_mode)} aria-describedby={errors.identity_mode ? id + '-identity-error' : undefined} className={'ui-field-control ' + (errors.identity_mode ? 'ui-field-control--invalid' : '')}>
                            <option value="unknown">Identitas belum diketahui</option><option value="known">Lansia teridentifikasi secara manual</option>
                        </select>
                        {errors.identity_mode && <p id={id + '-identity-error'} className="ui-field-error">{errors.identity_mode}</p>}
                    </div>
                    {form.data.identity_mode === 'known' && <IncidentResidentSelector incidentId={incident.id} selectedId={form.data.elderly_id} onSelect={(value) => change('elderly_id', value)} disabled={form.processing} inputId={id + '-resident'} error={errors.elderly_id} />}
                </>}
                <div className="ui-field-group">
                    <label htmlFor={id + '-notes'} className="ui-field-label">Catatan penanganan {action === 'acknowledge' ? '(opsional)' : '(wajib)'}</label>
                    <textarea id={id + '-notes'} name="notes" value={form.data.notes} onChange={(event) => change('notes', event.target.value)} required={action !== 'acknowledge'} maxLength={2000} rows={5} aria-invalid={Boolean(errors.notes)} aria-describedby={[id + '-notes-help', errors.notes ? id + '-notes-error' : ''].filter(Boolean).join(' ')} className={'ui-field-control ui-incident-notes-input ' + (errors.notes ? 'ui-field-control--invalid' : '')} />
                    <p id={id + '-notes-help'} className="ui-field-hint">Maksimal 2.000 karakter. Catat penanganan seperlunya; hindari credential dan informasi pribadi yang tidak diperlukan. Catatan tindakan tidak dapat diedit setelah tersimpan.</p>
                    {errors.notes && <p id={id + '-notes-error'} className="ui-field-error">{errors.notes}</p>}
                </div>
                <div>
                    <label htmlFor={id + '-confirm'} className="ui-incident-confirm">
                        <input id={id + '-confirm'} name="confirm" type="checkbox" checked={form.data.confirm} onChange={(event) => change('confirm', event.target.checked)} required aria-invalid={Boolean(errors.confirm)} aria-describedby={errors.confirm ? id + '-confirm-error' : undefined} />
                        <span>Saya mengonfirmasi tindakan manual ini. {['resolve', 'false_alarm'].includes(action) && 'Insiden akan ditutup dan tidak dapat dibuka kembali.'}</span>
                    </label>
                    {errors.confirm && <p id={id + '-confirm-error'} className="ui-field-error">{errors.confirm}</p>}
                </div>
                {dirty && <p className="ui-incident-unsaved" role="status">Perubahan belum disimpan. Periksa catatan sebelum berpindah. Tombol Kembali/Maju browser dapat membuang isian.</p>}
                {Object.keys(errors).length > 0 && <div ref={summaryRef} role="alert" tabIndex={-1} className="ui-incident-error"><p className="ui-incident-error__title">Tindakan belum dapat disimpan.</p>{Object.entries(errors).map(([key, error]) => <p key={key}>{error}</p>)}</div>}
                {failure && <p ref={failureRef} role="alert" tabIndex={-1} className="ui-incident-error">{failure}</p>}
                <Button type="submit" variant="secondary" processing={form.processing} className="ui-incident-submit">{form.processing ? 'Menyimpan…' : incidentActionTitles[action]}</Button>
            </fieldset>
        </form>
    );
}

export default function IncidentActions({ incident }) {
    const { auth, errors: pageErrors = {} } = usePage().props;
    const allowed = incidentActionsFor(incident, auth.user.role);
    const [action, setAction] = useState(allowed[0] || '');
    const [includePageErrors, setIncludePageErrors] = useState(true);
    const summaryRef = useRef(null);
    const errorSignature = JSON.stringify(pageErrors);
    useEffect(() => { if (!allowed.length && Object.keys(pageErrors).length) summaryRef.current?.focus(); }, [errorSignature, allowed.length]);
    const unavailableErrors = Object.keys(pageErrors).length > 0 && <div ref={summaryRef} role="alert" tabIndex={-1} className="ui-incident-error">{Object.entries(pageErrors).map(([key, message]) => <p key={key}>{message}</p>)}</div>;
    if (auth.user.role === 'admin') return <div className="ui-incident-readonly"><StatusBadge tone="neutral">Pengawasan Admin · baca saja</StatusBadge><p>Tindakan penanganan dan verifikasi dilakukan oleh Perawat. Riwayat insiden tetap dapat dibaca.</p>{unavailableErrors}</div>;
    if (!allowed.length) return <div className="ui-incident-readonly"><p>Tidak ada tindakan yang tersedia untuk akun dan status ini. Histori tetap dapat dibaca.</p>{['resolved', 'false_alarm'].includes(incident.status) && <p>Insiden sudah ditutup dan tidak dapat dibuka atau diedit kembali.</p>}{unavailableErrors}</div>;
    const active = allowed.includes(action) ? action : allowed[0];
    return <div>
        <p className="ui-monitoring-copy">Perawat aktif dapat melanjutkan penanganan bersama. Petugas acknowledge pertama tetap tercatat.</p>
        <ActionForm key={auth.user.id + ':' + incident.id + ':' + incident.version + ':' + active} incident={incident} action={active} allowed={allowed} includePageErrors={includePageErrors} onActionChange={(next) => { setAction(next); setIncludePageErrors(false); }} />
    </div>;
}
