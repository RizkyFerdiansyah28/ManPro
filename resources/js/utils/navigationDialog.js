export const desktopNavigationQuery = '(min-width: 80rem)';

// Native modal dialog supplies background inertness and the keyboard focus boundary.
export function openNavigationDialog({ dialog, trigger, fallback, media, onClose }) {
    if (media.matches || typeof dialog.showModal !== 'function') {
        onClose();
        return () => {};
    }
    const body = dialog.ownerDocument.body;
    const previousOverflow = body.style.overflow;
    let disposed = false;
    let requested = false;
    let backdropStart = false;
    const requestClose = () => {
        if (!disposed && !requested) { requested = true; onClose(); }
    };
    const cancel = (event) => { event.preventDefault(); requestClose(); };
    const pointerDown = (event) => { backdropStart = event.target === dialog; };
    const click = (event) => { if (backdropStart && event.target === dialog) requestClose(); backdropStart = false; };
    const breakpoint = (event) => { if (event.matches) requestClose(); };
    dialog.addEventListener('cancel', cancel);
    dialog.addEventListener('close', requestClose);
    dialog.addEventListener('pointerdown', pointerDown);
    dialog.addEventListener('click', click);
    media.addEventListener('change', breakpoint);
    body.style.overflow = 'hidden';
    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        dialog.removeEventListener('cancel', cancel);
        dialog.removeEventListener('close', requestClose);
        dialog.removeEventListener('pointerdown', pointerDown);
        dialog.removeEventListener('click', click);
        media.removeEventListener('change', breakpoint);
        if (dialog.open) dialog.close();
        body.style.overflow = previousOverflow;
        const target = trigger?.isConnected && trigger.getClientRects().length ? trigger : fallback;
        if (target?.isConnected) target.focus({ preventScroll: true });
    };
    try {
        dialog.showModal();
        dialog.querySelector('[data-drawer-close]')?.focus();
    } catch {
        cleanup();
        onClose();
    }
    return cleanup;
}
