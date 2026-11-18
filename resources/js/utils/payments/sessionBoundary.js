export function installPaymentSessionBoundary({ router, target, initialAuth }) {
    let actor = initialAuth?.user?.id;
    let channel;
    const purge = broadcast => {
        target.dispatchEvent(new target.Event('silansia:payment-access-lost'));
        if (broadcast) { try { channel?.postMessage('access_lost'); } catch { /* Server still verifies session. */ } }
    };
    try { channel = new target.BroadcastChannel('silansia.payment.session.v1'); channel.onmessage = e => { if (e.data === 'access_lost') purge(false); }; } catch { /* Fresh server context remains mandatory. */ }
    const before = router.on('before', e => { if (String(e.detail.visit.method).toLowerCase() === 'post' && new URL(e.detail.visit.url, target.location.origin).pathname === '/logout') purge(true); });
    const navigate = router.on('navigate', e => {
        const auth = e.detail.page.props.auth;
        if (!auth) return;
        if (!auth.user || !['admin','keluarga'].includes(auth.user.role) || (actor && actor !== auth.user.id)) purge(true);
        actor = auth.user?.id;
    });
    return () => { before(); navigate(); channel?.close(); };
}
