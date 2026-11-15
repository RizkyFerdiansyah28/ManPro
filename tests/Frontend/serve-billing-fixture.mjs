// Explicit loopback-only synthetic fixture server, no Laravel bootstrap/.env/DB or live fallback.
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import tailwind from '@tailwindcss/vite';
import { actor, ctx, target, preview, issued, detailInvoice, listInvoice, pagination, requestId } from './billingFixtures.mjs';
let mode = 'unknown'; let session = ctx.session_context; let audience = ctx.audience_context;
let stats = { previews: 0, issues: 0, reconciles: 0, contexts: 0, last_request_id: null };
const server = await createServer({ configFile: false, envDir: false, cacheDir: 'node_modules/.vite-billing-fixture', plugins: [react(), tailwind(), { name: 'synthetic-invoice-contract', configureServer(vite) {
    vite.middlewares.use(async (req, res, next) => {
        const url = new URL(req.url, 'http://127.0.0.1:5187');
        const pagePath = url.pathname === '/admin/billing/invoices' || url.pathname === '/admin/billing/invoices/create' || /^\/admin\/billing\/invoices\/[a-f0-9-]{36}$/i.test(url.pathname);
        if (req.method === 'GET' && pagePath && req.headers['x-inertia'] !== 'true') {
            req.url = '/tests/Frontend/billing-browser.html';
            return next(); // Real navigations need HTML; the fixture bootstrap then requests its Inertia page.
        }
        if (!url.pathname.startsWith('/admin/billing/invoices') && !url.pathname.startsWith('/__billing_fixture') && url.pathname !== '/notifications/summary') return next();
        const send = (data, status = 200, inertia = false) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.setHeader('Cache-Control', 'no-store, private'); if (inertia) res.setHeader('X-Inertia', 'true'); res.end(JSON.stringify(data)); };
        // Shared staff layout polls this endpoint; these counts are synthetic only.
        if (url.pathname === '/notifications/summary') {
            if (req.method !== 'GET') return send({ error: 'synthetic_read_only' }, 405);
            return send({ unread_count: 0, pending_count: 0, as_of: new Date().toISOString() });
        }
        const fail = (code, status) => send({ error: { code, message: 'Synthetic fixture', retry_mode: 'review' }, financial_outcome: 'unknown', correlation_id: actor }, status);
        let body = {};
        if (req.method === 'POST') { let raw = ''; for await (const chunk of req) { raw += chunk; if (raw.length > 8192) return fail('invalid_input', 422); } try { body = JSON.parse(raw); } catch { return fail('invalid_input', 422); } }
        if (url.pathname === '/__billing_fixture/control') {
            if (body.mode === 'reset') { mode = 'unknown'; session = ctx.session_context; audience = ctx.audience_context; stats = { previews: 0, issues: 0, reconciles: 0, contexts: 0, last_request_id: null }; }
            else if (body.mode === 'rotate-session') session = 'c'.repeat(64);
            else if (body.mode === 'rotate-audience') audience = 'd'.repeat(64);
            else if (body.mode !== 'stats') mode = body.mode;
            return send({ mode, ...stats });
        }
        const props = { auth: { user: { id: actor, role: 'admin', name: 'Admin fixture', home_url: '/admin' } }, errors: {}, flash: {} };
        const inertia = (name, extra) => send({ component: 'Admin/Billing/Invoices/' + name, props: { ...props, ...extra }, url: url.pathname + url.search, version: 'fixture-v1', clearHistory: false, encryptHistory: false }, 200, true);
        if (url.pathname.endsWith('/create')) { stats.contexts++; if (mode === 'unauthorized') return fail('authentication_required', 401); return inertia('Create', { recovery_context: { ...ctx, session_context: session, audience_context: audience }, frontend_available: false, filters: {} }); }
        if (url.pathname.endsWith('/resident-options')) return send(pagination([{ id: target.elderly_id, name_current: 'Lansia fixture sintetis', is_active: true }]));
        if (req.method === 'GET' && url.pathname === '/admin/billing/invoices') return inertia('Index', { invoices: pagination([listInvoice]), filters: {} });
        if (req.method === 'GET') return inertia('Show', { invoice: detailInvoice });
        if (req.headers['x-xsrf-token'] !== 'synthetic-csrf-only') return fail('session_expired', 419);
        if (url.pathname.endsWith('/preview')) { stats.previews++; return send(['HOLD', 'SKIP', 'REJECT'].includes(mode) ? { kind: 'preview', issued: false, decision: mode, reason: { HOLD: 'null_monthly_rate', SKIP: 'no_chargeable_service', REJECT: 'billing_month_not_ended' }[mode], calculation: null, confirmation: null } : preview); }
        stats.last_request_id = body.request_id;
        if (url.pathname.endsWith('/reconcile')) { stats.reconciles++; if (mode !== 'replay') return fail('outcome_unresolved', 409); return send({ ...issued, replayed: true }); }
        stats.issues++;
        if (mode === 'malformed') return send({ kind: 'invalid' }, 201);
        if (mode !== 'replay') return fail('outcome_unknown', 503);
        return send(issued, 201);
    });
} }], server: { host: '127.0.0.1', port: 5187, strictPort: true }, optimizeDeps: {
    // Browser ESM needs CJS interop, including Inertia's react-dom/client import.
    entries: ['tests/Frontend/billing-browser.html'],
    include: ['react-dom/client'],
} });
await server.listen();
process.stdout.write('SYNTHETIC_BROWSER_FIXTURE http://127.0.0.1:5187/tests/Frontend/billing-browser.html\n');
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => { await server.close(); process.exit(0); });
