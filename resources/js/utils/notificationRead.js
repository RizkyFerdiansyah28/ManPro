export function notificationReadContext(filters, page) {
    return { state: filters.state, per_page: filters.per_page, page: page.current_page };
}

export function submitNotificationRead({ form, recordId = null, lock, onFailure, onSuccess }) {
    if (lock.current || form.processing) return false;
    lock.current = true;
    const fail = () => { onFailure(); return false; };
    try {
        form.post(recordId === null ? '/notifications/read-all' : '/notifications/' + recordId + '/read', {
            preserveScroll: true, onSuccess, onNetworkError: fail, onHttpException: fail,
            onFinish: () => { lock.current = false; },
        });
    } catch (error) {
        lock.current = false;
        throw error;
    }
    return true;
}
