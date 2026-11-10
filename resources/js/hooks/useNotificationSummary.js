import { useEffect, useRef, useState } from 'react';
import { createNotificationPoller } from '../utils/notificationPoller';

const initial = { data: null, loading: false, stale: true, expired: false, error: '' };
export default function useNotificationSummary(actorId) {
    const [state, setState] = useState(initial);
    const current = useRef(null);
    useEffect(() => {
        setState({ ...initial, actorId });
        const poller = createNotificationPoller({ onState: (next) => setState({ ...next, actorId }) });
        current.current = poller;
        const visibility = () => poller.visibilityChanged();
        const refresh = () => poller.refresh();
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('online', visibility);
        window.addEventListener('offline', visibility);
        window.addEventListener('focus', refresh);
        window.addEventListener('silansia:notifications-changed', refresh);
        poller.start();
        return () => {
            poller.stop();
            current.current = null;
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('online', visibility);
            window.removeEventListener('offline', visibility);
            window.removeEventListener('focus', refresh);
            window.removeEventListener('silansia:notifications-changed', refresh);
        };
    }, [actorId]);
    return { ...(state.actorId === actorId ? state : initial), refresh: () => current.current?.refresh() };
}
