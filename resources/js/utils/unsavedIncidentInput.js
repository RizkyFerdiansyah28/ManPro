import { discardIncidentInputMessage } from './incidentForm.js';

function ownIncidentSubmit(visit, current, origin) {
    if (visit.method !== 'post' || visit.data?.request_id !== current.data.request_id || visit.data?.expected_version !== current.data.expected_version) return false;
    const target = new URL(visit.url, origin);
    const expected = new URL(current.url, origin);
    return target.href === expected.href;
}

// Scoped to the mounted action form. No history rewriting or note persistence.
export function protectUnsavedIncidentInput({ router, windowTarget, current, confirm }) {
    const removeBefore = router.on('before', (event) => {
        const state = current();
        if (!state.dirty || ownIncidentSubmit(event.detail.visit, state, windowTarget.location.origin)) return;
        if (!confirm(discardIncidentInputMessage)) event.preventDefault();
    });
    const beforeUnload = (event) => {
        if (!current().dirty) return;
        event.preventDefault();
        event.returnValue = '';
    };
    windowTarget.addEventListener('beforeunload', beforeUnload);
    return () => {
        removeBefore();
        windowTarget.removeEventListener('beforeunload', beforeUnload);
    };
}
