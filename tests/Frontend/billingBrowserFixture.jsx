// Test-only browser fixture. Never imported by the application entrypoint.
import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import '../../resources/css/app.css';
import ManagementLayout from '../../resources/js/components/Management/ManagementLayout';
import { InvoiceCreateContent } from '../../resources/js/pages/Admin/Billing/Invoices/Create';
import Index from '../../resources/js/pages/Admin/Billing/Invoices/Index';
import Show from '../../resources/js/pages/Admin/Billing/Invoices/Show';
import { createInvoiceApi } from '../../resources/js/utils/billing/api';
import { installInvoiceSessionBoundary } from '../../resources/js/utils/billing/sessionBoundary';
import { actor } from './billingFixtures.mjs';

const api = createInvoiceApi({ doc: { cookie: 'XSRF-TOKEN=synthetic-csrf-only' }, version: () => 'fixture-v1', timeout: 3000 });
function FixtureCreate(props) {
    let storage = null;
    try { storage = window.sessionStorage; } catch { /* Match the application's fail-closed storage access. */ }
    return <ManagementLayout><h1 className="ui-management-title">Terbitkan invoice · fixture sintetis</h1><InvoiceCreateContent actor={props.auth.user.id} api={api} storage={storage} /></ManagementLayout>;
}
function Controls() {
    const [stats, setStats] = React.useState(null);
    async function control(mode) { const r = await fetch('/__billing_fixture/control', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode }) }); setStats(await r.json()); if (mode === 'reset') { sessionStorage.clear(); location.href = '/tests/Frontend/billing-browser.html'; } }
    return <aside style={{ padding: '1rem', borderBottom: '2px solid #047857', background: '#fff' }} aria-label="Kontrol fixture sintetis"><strong>Hanya fixture browser — tanpa Laravel/database</strong><div style={{ display: 'flex', flexWrap: 'wrap', gap: '.5rem', marginTop: '.75rem' }}>{['reset', 'unknown', 'unresolved', 'replay', 'HOLD', 'SKIP', 'REJECT', 'rotate-session', 'rotate-audience', 'unauthorized', 'malformed', 'stats', 'logout-signal', 'corrupt-storage'].map((mode) => <button className="ui-button ui-button--secondary" key={mode} onClick={() => {
        if (mode === 'logout-signal') { const c = new BroadcastChannel('silansia.invoice.session.v1'); c.postMessage({ type: 'access_lost' }); c.close(); }
        else if (mode === 'corrupt-storage') { const k = Object.keys(sessionStorage).find((k) => k.startsWith('silansia.invoice.intent.v1:') && !k.endsWith('hold')); if (k) sessionStorage.setItem(k, '{broken'); }
        else void control(mode);
    }}>{mode}</button>)}</div>{stats && <pre aria-label="Statistik fixture">{JSON.stringify(stats, null, 2)}</pre>}</aside>;
}
createRoot(document.getElementById('fixture-controls')).render(<Controls />);
const auth = { user: { id: actor, role: 'admin', name: 'Admin fixture', home_url: '/admin' } };
let initialPage = { component: 'Admin/Billing/Invoices/Create', props: { auth, errors: {}, flash: {} }, url: location.pathname + location.search, version: 'fixture-v1' };
if (location.pathname.startsWith('/admin/billing/invoices')) {
    const response = await fetch(location.pathname + location.search, { cache: 'no-store', headers: { 'X-Inertia': 'true', Accept: 'application/json' } });
    if (!response.ok) throw new Error('Synthetic fixture bootstrap unavailable');
    initialPage = await response.json();
}
createInertiaApp({ page: initialPage,
    resolve: (name) => ({ 'Admin/Billing/Invoices/Create': FixtureCreate, 'Admin/Billing/Invoices/Index': Index, 'Admin/Billing/Invoices/Show': Show }[name]),
    setup({ el, App, props }) { installInvoiceSessionBoundary({ router, target: window, initialAuth: initialPage.props.auth }); createRoot(el).render(<App {...props} />); },
});
