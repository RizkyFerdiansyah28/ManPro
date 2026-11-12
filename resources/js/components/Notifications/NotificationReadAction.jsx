import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Button from '../ui/Button';
import formatMonitoringTime from '../../utils/formatMonitoringTime';
import { submitNotificationRead } from '../../utils/notificationRead';

export function notificationReadChanged() { window.dispatchEvent(new Event('silansia:notifications-changed')); }

export default function NotificationReadAction({ record, context }) {
    const form = useForm(context);
    const [error, setError] = useState('');
    const errorRef = useRef(null);
    const lock = useRef(false);
    const validation = Object.values(form.errors);
    const signature = JSON.stringify(validation);
    useEffect(() => { if (error || validation.length) errorRef.current?.focus(); }, [error, signature]);
    if (record.read_at) return <p className="ui-notification-read-time">Dibaca <time dateTime={record.read_at}>{formatMonitoringTime(record.read_at)}</time></p>;
    function submit() {
        if (form.processing || lock.current) return;
        setError('');
        submitNotificationRead({
            form, recordId: record.id, lock, onSuccess: notificationReadChanged,
            onFailure: () => setError('Status baca belum dapat dipastikan. Muat ulang daftar atau coba kembali.'),
        });
    }
    return <div className="ui-notification-read-action">
        <Button variant="secondary" onClick={submit} processing={form.processing}>{form.processing ? 'Menyimpan…' : 'Tandai dibaca'}</Button>
        {(error || validation.length > 0) && <div ref={errorRef} role="alert" tabIndex={-1} className="ui-incident-error">{error && <p>{error}</p>}{validation.map((message, index) => <p key={index}>{message}</p>)}</div>}
    </div>;
}
