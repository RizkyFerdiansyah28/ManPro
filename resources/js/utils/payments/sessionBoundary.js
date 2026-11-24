export const paymentHoldKey = opaqueKey => 'silansia.payment.hold.v1:' + opaqueKey;

export function watchPaymentHold(target, storage, opaqueKey, observe) {
    const key = paymentHoldKey(opaqueKey);
    const changed = event => {
        if (event.storageArea === storage && (event.key === key || event.key === null)) observe();
    };
    target.addEventListener('storage', changed);
    return () => target.removeEventListener('storage', changed);
}

export function installPaymentSessionBoundary({ router, target, initialAuth }) {
    let actor = initialAuth?.user?.id, role = initialAuth?.user?.role;
    let channel;
    const purge = broadcast => {
        target.dispatchEvent(new target.Event('silansia:payment-access-lost'));
        if (broadcast) { try { channel?.postMessage('access_lost'); } catch { /* Server still verifies session. */ } }
    };
    try { channel = new target.BroadcastChannel('silansia.payment.session.v1'); channel.onmessage = e => { if (e.data === 'access_lost') purge(false); }; } catch { /* Fresh server context remains mandatory. */ }
    const before = router.on('before', e => { if (String(e.detail.visit.method).toLowerCase() === 'post' && new URL(e.detail.visit.url, target.location.origin).pathname === '/logout') purge(true); });
    const navigate = router.on('navigate', e => {
        const auth = e.detail.page.props.auth;
        if (auth === undefined) return;
        const next = auth?.user;
        if (actor && (actor !== next?.id || role !== next?.role)) purge(true);
        actor = next?.id;
        role = next?.role;
    });
    const restored = event => { if (event.persisted) purge(false); };
    target.addEventListener?.('pageshow', restored);
    return () => {
        before(); navigate(); channel?.close();
        target.removeEventListener?.('pageshow', restored);
    };
}
