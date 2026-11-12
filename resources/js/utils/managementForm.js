export const discardManagementInputMessage = 'Ada perubahan yang belum disimpan. Tinggalkan halaman dan buang perubahan tersebut?';

function availableControl(control) {
    return control && !control.disabled && !control.matches?.(':disabled')
        && control.type !== 'hidden' && typeof control.focus === 'function';
}

export function managementErrorTarget(form, field) {
    const named = form?.elements.namedItem(field);
    const candidates = named?.length !== undefined && !named?.focus ? Array.from(named) : [named];
    return candidates.find(availableControl)
        ?? Array.from(form?.querySelectorAll('[data-management-field]') ?? [])
            .find((control) => control.dataset.managementField === field && availableControl(control));
}

export function focusManagementErrors(form, summary, errors) {
    if (!Object.keys(errors).length) return false;
    const target = Object.keys(errors).map((field) => managementErrorTarget(form, field)).find(Boolean) ?? summary;
    target?.focus();
    return Boolean(target);
}

// A synchronous latch covers repeat dispatch before React renders processing.
// Release even when an existing confirmation is cancelled or the handler throws.
export function submitManagementForm({ event, processing, latch, submitting, submit }) {
    event.preventDefault();
    if (processing || latch.current) return false;
    latch.current = true;
    submitting.current = true;
    try {
        submit(event);
        return true;
    } finally {
        submitting.current = false;
        queueMicrotask(() => { latch.current = false; });
    }
}

// Only mounted editor forms opt in. No history rewriting or field persistence.
export function protectManagementInput({ router, windowTarget, current, confirm }) {
    const removeBefore = router.on('before', (event) => {
        const state = current();
        if (!state.dirty || state.submitting) return;
        if (!confirm(discardManagementInputMessage)) event.preventDefault();
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
