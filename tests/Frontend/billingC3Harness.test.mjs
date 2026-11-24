import assert from 'node:assert/strict';
import test from 'node:test';
import { assertCalendarExamples, invoiceStoreBody } from '../Browser/billing-c3-scenarios.mjs';
import { assertC3NoDatabaseEnvironment } from '../Browser/payment-r2-integration-owned.mjs';
import { runC3Process, withinC3Deadline } from '../Browser/billing-c3-process.mjs';

test('C3 date boundaries use the existing WIB calculator', () => assertCalendarExamples());

test('C3 invoice replay and competing store request each confirm their own intent', () => {
    const source = { resident: 'synthetic-resident', month: '2026-10-01' };
    const original = { request_id: 'original-request', token: 'original-confirmation', expires_at: 'fixture-only' };
    const competing = { request_id: 'competing-request', token: 'competing-confirmation', expires_at: 'fixture-only' };
    for (const intent of [original, competing]) {
        assert.deepEqual(invoiceStoreBody(source, intent), {
            elderly_id: source.resident,
            billing_month: source.month,
            request_id: intent.request_id,
            confirmation_token: intent.token,
            confirm: true,
        });
    }
});

test('C3 Node controller and asset builder fail closed for inherited DB credentials', () => {
    const baseline = { APP_ENV: 'testing', DB_CONNECTION: 'silansia_testing', SystemRoot: 'C:\\Windows' };
    assert.doesNotThrow(() => assertC3NoDatabaseEnvironment(baseline));
    for (const key of ['DB_DATABASE', 'db_url', 'PGHOSTADDR', 'pgservice', 'DATABASE_URL', 'SILANSIA_TEST_IDENTITY',
        'SILANSIA_TEST_DB_PASSWORD', 'SILANSIA_05E_STAGE', 'APP_KEY', 'APP_PREVIOUS_KEYS']) {
        assert.throws(() => assertC3NoDatabaseEnvironment({ ...baseline, [key]: '' }), { message: 'c3_browser_database_environment' });
    }
});

test('C3 Node accepts an ordinary subprocess only after exit and rejects late bootstrap', async () => {
    assert.equal(await runC3Process(process.execPath, ['-e', "process.stdout.write('synthetic')"], {}, 5000), 'synthetic');
    await assert.rejects(runC3Process(process.execPath, ['-e', 'setTimeout(() => {}, 200)'], {}, 25),
        { message: 'billing_c3_node_child_deadline_exceeded' });
    await assert.rejects(withinC3Deadline(() => new Promise(() => {}), 25), { message: 'billing_c3_node_deadline_exceeded' });
});
