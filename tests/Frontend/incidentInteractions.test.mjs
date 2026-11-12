import test from 'node:test';
import assert from 'node:assert/strict';
import { incidentActionsFor, incidentRequestId, initialIncidentActionData, changeIncidentActionData, hasUnsavedIncidentInput, switchIncidentAction, submitIncidentAction, incidentActionUrl, searchIncidentCandidatesOnEnter } from '../../resources/js/utils/incidentForm.js';
import { protectUnsavedIncidentInput } from '../../resources/js/utils/unsavedIncidentInput.js';
import { notificationReadContext, submitNotificationRead } from '../../resources/js/utils/notificationRead.js';
import focusFormErrors from '../../resources/js/utils/focusFormErrors.js';

const id = '12345678-1234-4234-8234-123456789abc';
const firstRequest = '12345678-1234-4234-8234-123456789abd';
const nextRequest = '12345678-1234-4234-8234-123456789abe';
const version = 'a'.repeat(64);
const incident = Object.freeze({ id, status: 'acknowledged', is_verified: false, version, detail_url: '/monitoring/incidents/' + id, allowed_actions: Object.freeze(['verify', 'false_alarm']) });
const initial = () => initialIncidentActionData(incident, 'verify', () => firstRequest);

for (const role of ['admin', 'keluarga', 'unknown']) {
    test('helper tidak menawarkan tindakan Perawat untuk role ' + role, () => {
        assert.deepEqual(incidentActionsFor(incident, role), []);
    });
}
test('availability mengikuti urutan server tanpa membuat aksi dari status', () => {
    assert.deepEqual(incidentActionsFor(incident, 'perawat'), ['verify', 'false_alarm']);
    assert.deepEqual(incidentActionsFor({ ...incident, allowed_actions: [] }, 'perawat'), []);
    assert.deepEqual(incidentActionsFor({ ...incident, allowed_actions: ['future', 'resolve'] }, 'perawat'), ['resolve']);
});

test('request_id tetap UUID v4 yang berbeda, tanpa mengganti hash/version backend', () => {
    const one = incidentRequestId();
    const two = incidentRequestId();
    assert.match(one, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
    assert.notEqual(one, two);
    assert.equal(initial().expected_version, version);
});

for (const action of ['acknowledge', 'verify', 'resolve', 'false_alarm']) {
    test('payload awal ' + action + ' hanya berisi kolom kontrak', () => {
        const data = initialIncidentActionData(incident, action, () => firstRequest);
        assert.deepEqual(data, {
            request_id: firstRequest, expected_version: version, confirm: false, notes: '',
            ...(action === 'verify' ? { identity_mode: 'unknown', elderly_id: '' } : {}),
        });
    });
}

test('perubahan isian merotasi intent; penggantian identity_mode menghapus hanya pilihan lansia', () => {
    const before = Object.freeze({ ...initial(), notes: 'Catatan fixture', confirm: true, identity_mode: 'known', elderly_id: id });
    const edited = changeIncidentActionData(before, 'notes', 'Catatan baru', () => nextRequest);
    assert.equal(edited.request_id, nextRequest);
    assert.equal(edited.elderly_id, id);
    const identity = changeIncidentActionData(before, 'identity_mode', 'unknown', () => nextRequest);
    assert.deepEqual(identity, { ...before, identity_mode: 'unknown', elderly_id: '', request_id: nextRequest });
    assert.equal(before.request_id, firstRequest);
    assert.equal(before.elderly_id, id);
});

test('dirty state mengabaikan UUID dan mendeteksi notes/konfirmasi/identitas', () => {
    assert.equal(hasUnsavedIncidentInput(initial()), false);
    assert.equal(hasUnsavedIncidentInput({ ...initial(), request_id: nextRequest }), false);
    for (const change of [{ notes: ' ' }, { notes: 'Catatan' }, { confirm: true }, { identity_mode: 'known' }, { elderly_id: id }]) assert.equal(hasUnsavedIncidentInput({ ...initial(), ...change }), true);
    const undone = changeIncidentActionData({ ...initial(), notes: 'Catatan' }, 'notes', '', () => nextRequest);
    assert.equal(hasUnsavedIncidentInput(undone), false);
});

test('membatalkan perpindahan action mempertahankan data, UUID, dan action', () => {
    const data = Object.freeze({ ...initial(), notes: 'Catatan belum tersimpan', confirm: true });
    let selected = 'verify';
    let prompts = 0;
    const before = JSON.stringify(data);
    assert.equal(switchIncidentAction({ current: selected, next: 'false_alarm', allowed: incident.allowed_actions, data, processing: false, confirm: (message) => { prompts++; assert.match(message, /belum disimpan/); return false; }, onChange: (action) => { selected = action; } }), false);
    assert.equal(selected, 'verify');
    assert.equal(prompts, 1);
    assert.equal(JSON.stringify(data), before);
});

test('persetujuan perpindahan hanya mengganti action; data action baru tidak membawa identity field', () => {
    const data = Object.freeze({ ...initial(), identity_mode: 'known', elderly_id: id, notes: 'Catatan fixture' });
    let selected = 'verify';
    const changed = switchIncidentAction({ current: selected, next: 'false_alarm', allowed: incident.allowed_actions, data, processing: false, confirm: () => true, onChange: (action) => { selected = action; } });
    assert.equal(changed, true);
    assert.equal(selected, 'false_alarm');
    const next = initialIncidentActionData(incident, selected, () => nextRequest);
    assert.equal(next.notes, '');
    assert.equal(next.confirm, false);
    assert.ok(!Object.hasOwn(next, 'identity_mode'));
    assert.ok(!Object.hasOwn(next, 'elderly_id'));
    assert.equal(data.notes, 'Catatan fixture');
});

test('form bersih berpindah tanpa prompt dan menolak action asing/processing/sama', () => {
    let selected = 'verify';
    let prompts = 0;
    const options = { current: 'verify', allowed: incident.allowed_actions, data: initial(), processing: false, confirm: () => { prompts++; return false; }, onChange: (value) => { selected = value; } };
    assert.equal(switchIncidentAction({ ...options, next: 'false_alarm' }), true);
    for (const change of [{ next: 'verify' }, { next: 'resolve' }, { next: 'false_alarm', processing: true }]) assert.equal(switchIncidentAction({ ...options, ...change }), false);
    assert.equal(prompts, 0);
    assert.equal(selected, 'false_alarm');
});

test('submit insiden menjaga endpoint, POST payload dan menahan duplicate request', () => {
    const data = Object.freeze({ ...initial(), notes: 'Catatan fixture', confirm: true });
    const calls = [];
    const form = { data, processing: false, post: (url, options) => calls.push({ url, options }) };
    const lock = { current: false };
    const options = { form, incident, action: 'verify', lock, onFailure() {} };
    assert.equal(submitIncidentAction(options), true);
    assert.equal(submitIncidentAction(options), false);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, incident.detail_url + '/verify');
    assert.equal(calls[0].options.preserveScroll, true);
    assert.equal(form.data, data);
    assert.equal(incident.status, 'acknowledged');
    calls[0].options.onFinish();
    assert.equal(lock.current, false);
});

test('network error tidak mengirim otomatis, menghapus notes, atau merotasi UUID; retry identik', () => {
    const data = Object.freeze({ ...initial(), notes: 'Catatan fixture', confirm: true });
    const calls = [];
    let failures = 0;
    const options = { form: { data, processing: false, post: (url, callbacks) => calls.push(callbacks) }, incident, action: 'verify', lock: { current: false }, onFailure: () => { failures++; } };
    submitIncidentAction(options);
    assert.equal(calls[0].onNetworkError(), false);
    assert.equal(calls[0].onHttpException(), false);
    assert.equal(failures, 2);
    assert.equal(calls.length, 1);
    assert.equal(options.form.data.request_id, firstRequest);
    assert.equal(options.form.data.notes, 'Catatan fixture');
    calls[0].onFinish();
    submitIncidentAction(options);
    assert.equal(calls.length, 2);
    assert.equal(options.form.data, data);
});

test('callback validasi dan sukses tidak membuat transisi status optimistis', () => {
    let errorCalls = 0;
    let successCalls = 0;
    let callbacks;
    const data = Object.freeze({ ...initial(), notes: 'Tetap ada', confirm: true });
    submitIncidentAction({ form: { data, processing: false, post: (url, options) => { callbacks = options; } }, incident, action: 'verify', lock: { current: false }, onFailure() {}, onError: () => { errorCalls++; }, onSuccess: () => { successCalls++; } });
    assert.equal(errorCalls, 0);
    assert.equal(successCalls, 0);
    callbacks.onError({ notes: 'Tidak valid' });
    assert.equal(errorCalls, 1);
    assert.equal(data.notes, 'Tetap ada');
    callbacks.onSuccess();
    assert.equal(successCalls, 1);
    assert.equal(incident.status, 'acknowledged');
    assert.equal(incident.is_verified, false);
});

test('request yang tidak tersedia/processing ditolak sebelum post dan lock pulih bila post melempar', () => {
    let posts = 0;
    const form = { data: initial(), processing: false, post: () => { posts++; } };
    const options = { form, incident, action: 'resolve', lock: { current: false }, onFailure() {} };
    assert.equal(submitIncidentAction(options), false);
    form.processing = true;
    assert.equal(submitIncidentAction({ ...options, action: 'verify' }), false);
    form.processing = false;
    const lock = { current: false };
    assert.throws(() => submitIncidentAction({ ...options, action: 'verify', lock, form: { ...form, post: () => { throw new Error('fixture'); } } }), /fixture/);
    assert.equal(lock.current, false);
    assert.equal(posts, 0);
    assert.equal(incidentActionUrl(incident, 'false_alarm'), incident.detail_url + '/false-alarm');
});

function guardFixture({ dirty = true, allow = false } = {}) {
    const windowTarget = new EventTarget();
    windowTarget.location = { origin: 'https://silansia.example.test' };
    const events = new EventTarget();
    const state = { dirty, data: { ...initial(), notes: dirty ? 'Catatan fixture' : '' }, url: incident.detail_url + '/verify' };
    const calls = { prompts: 0, removed: 0 };
    const router = { on: (name, listener) => { events.addEventListener(name, listener); return () => { events.removeEventListener(name, listener); calls.removed++; }; } };
    const cleanup = protectUnsavedIncidentInput({ router, windowTarget, current: () => state, confirm: () => { calls.prompts++; return allow; } });
    const before = (visit) => {
        const event = new Event('before', { cancelable: true });
        event.detail = { visit };
        events.dispatchEvent(event);
        return event;
    };
    return { state, windowTarget, calls, before, cleanup };
}

test('navigasi GET dibatalkan saat konfirmasi ditolak, catatan tetap di memori form', () => {
    const fixture = guardFixture();
    const before = JSON.stringify(fixture.state);
    const event = fixture.before({ method: 'get', url: '/monitoring/incidents', data: {} });
    assert.equal(event.defaultPrevented, true);
    assert.equal(fixture.calls.prompts, 1);
    assert.equal(JSON.stringify(fixture.state), before);
    fixture.cleanup();
});

test('kunjungan bersih dan konfirmasi disetujui tetap diteruskan', () => {
    for (const options of [{ dirty: false }, { dirty: true, allow: true }]) {
        const fixture = guardFixture(options);
        const event = fixture.before({ method: 'get', url: incident.detail_url, data: {} });
        assert.equal(event.defaultPrevented, false);
        assert.equal(fixture.calls.prompts, options.dirty ? 1 : 0);
        fixture.cleanup();
    }
});

test('POST intent saat ini melewati guard; logout, UUID/version berbeda dan origin lain tetap diperingatkan', () => {
    const fixture = guardFixture();
    const own = { method: 'post', url: new URL(fixture.state.url, fixture.windowTarget.location.origin), data: fixture.state.data };
    assert.equal(fixture.before(own).defaultPrevented, false);
    assert.equal(fixture.calls.prompts, 0);
    for (const change of [
        { url: '/logout' }, { data: { ...own.data, request_id: nextRequest } }, { data: { ...own.data, expected_version: 'b'.repeat(64) } },
        { url: 'https://other.example.test' + fixture.state.url }, { method: 'get' },
    ]) assert.equal(fixture.before({ ...own, ...change }).defaultPrevented, true);
    assert.equal(fixture.calls.prompts, 5);
    fixture.cleanup();
});

test('beforeunload hanya memperingatkan input kotor; cleanup melepas seluruh listener', () => {
    const fixture = guardFixture();
    const event = new Event('beforeunload', { cancelable: true });
    fixture.windowTarget.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    fixture.state.dirty = false;
    const clean = new Event('beforeunload', { cancelable: true });
    fixture.windowTarget.dispatchEvent(clean);
    assert.equal(clean.defaultPrevented, false);
    fixture.cleanup();
    fixture.state.dirty = true;
    const after = new Event('beforeunload', { cancelable: true });
    fixture.windowTarget.dispatchEvent(after);
    assert.equal(after.defaultPrevented, false);
    assert.equal(fixture.before({ method: 'get', url: '/notifications', data: {} }).defaultPrevented, false);
    assert.equal(fixture.calls.removed, 1);
});

test('focus error memilih field bernama pertama yang tersedia dan aktif', () => {
    const focused = [];
    const fields = { notes: { focus: () => focused.push('notes') }, confirm: { focus: () => focused.push('confirm') } };
    const form = { elements: { namedItem: (name) => fields[name] } };
    assert.equal(focusFormErrors(form, { focus: () => focused.push('summary') }, { notes: 'Catatan wajib', confirm: 'Konfirmasikan' }), true);
    assert.deepEqual(focused, ['notes']);
});

test('focus error tersembunyi/disabled jatuh ke summary; tidak mengambil fokus tanpa error', () => {
    let focus = 0;
    const form = { elements: { namedItem: () => ({ disabled: true, focus: () => { throw new Error('Tidak boleh fokus'); } }) } };
    assert.equal(focusFormErrors(form, { focus: () => { focus++; } }, { expected_version: 'Versi berubah' }), true);
    assert.equal(focus, 1);
    assert.equal(focusFormErrors(form, { focus: () => { focus++; } }, {}), false);
    assert.equal(focus, 1);
});

test('Enter di pencarian kandidat memuat pilihan tanpa submit form insiden', () => {
    let prevented = 0;
    let loads = 0;
    const event = { key: 'Enter', preventDefault: () => { prevented++; } };
    assert.equal(searchIncidentCandidatesOnEnter(event, () => { loads++; }, false), true);
    assert.equal(searchIncidentCandidatesOnEnter(event, () => { loads++; }, true), true);
    assert.equal(searchIncidentCandidatesOnEnter({ ...event, key: 'a' }, () => { loads++; }, false), false);
    assert.equal(searchIncidentCandidatesOnEnter({ ...event, isComposing: true }, () => { loads++; }, false), false);
    assert.equal(prevented, 2);
    assert.equal(loads, 1);
});

test('konteks baca notifikasi tidak menyertakan recipient/filter asing', () => {
    assert.deepEqual(notificationReadContext({ state: 'unread', per_page: 30, notifiable_id: 'other', arbitrary: 'ignored' }, { current_page: 2 }), { state: 'unread', per_page: 30, page: 2 });
});

for (const recordId of [id, null]) {
    test('mark-read ' + (recordId ?? 'seluruh') + ' tetap POST tunggal dengan konteks existing', () => {
        const data = Object.freeze({ state: 'unread', per_page: 30, page: 2, ...(recordId === null ? { confirm: true } : {}) });
        const calls = [];
        const lock = { current: false };
        let successes = 0;
        const form = { data, processing: false, post: (url, options) => calls.push({ url, options }) };
        const input = { form, recordId, lock, onFailure() {}, onSuccess: () => { successes++; } };
        assert.equal(submitNotificationRead(input), true);
        assert.equal(submitNotificationRead(input), false);
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, recordId === null ? '/notifications/read-all' : '/notifications/' + id + '/read');
        assert.equal(calls[0].options.preserveScroll, true);
        assert.equal(successes, 0);
        calls[0].options.onSuccess();
        assert.equal(successes, 1);
        assert.equal(form.data, data);
        calls[0].options.onFinish();
        assert.equal(lock.current, false);
    });
}

test('gagal baca notifikasi tidak memanggil success atau mengubah data lokal', () => {
    let callbacks;
    let failures = 0;
    let successes = 0;
    const data = Object.freeze({ state: 'all', per_page: 15, page: 1 });
    submitNotificationRead({ form: { data, post: (url, options) => { callbacks = options; } }, recordId: id, lock: { current: false }, onFailure: () => { failures++; }, onSuccess: () => { successes++; } });
    assert.equal(callbacks.onNetworkError(), false);
    assert.equal(callbacks.onHttpException(), false);
    assert.equal(failures, 2);
    assert.equal(successes, 0);
    assert.deepEqual(data, { state: 'all', per_page: 15, page: 1 });
});
