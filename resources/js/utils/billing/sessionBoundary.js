import { purgeInvoiceAccess } from './intentStorage.js';

export function installInvoiceSessionBoundary({ router, target, initialAuth }) {
    let previous = initialAuth?.user?.id;
    let channel;
    const purge = (broadcast) => {
        try { purgeInvoiceAccess(target.sessionStorage); } catch { /* Storage denied; no intent may be opened. */ }
        target.dispatchEvent(new target.Event('silansia:billing-access-lost'));
        if (broadcast) { try { channel?.postMessage({ type: 'access_lost' }); } catch { /* Fresh context remains mandatory. */ } }
    };
    try { channel = new target.BroadcastChannel('silansia.invoice.session.v1'); channel.onmessage = (e) => { if (e.data?.type === 'access_lost') purge(false); }; } catch { /* Create blocks persistence if channel support is absent. */ }
    if (initialAuth && (!initialAuth.user || initialAuth.user.role !== 'admin')) purge(true);
    const before = router.on('before', (e) => {
        const visit = e.detail.visit;
        if (String(visit.method).toLowerCase() === 'post' && new URL(visit.url, target.location.origin).pathname === '/logout') purge(true);
    });
    const navigate = router.on('navigate', (e) => {
        const auth = e.detail.page.props.auth;
        if (!auth) return; // Missing shared props on a 503 error is not proof of logout.
        if (!auth.user || auth.user.role !== 'admin' || (previous && auth.user.id !== previous)) purge(true);
        previous = auth.user?.id;
    });
    return () => { before(); navigate(); channel?.close(); };
}
