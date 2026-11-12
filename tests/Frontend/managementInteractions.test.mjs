import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { parseAst } from 'rolldown/parseAst';
import { focusManagementErrors, managementErrorTarget, protectManagementInput, submitManagementForm } from '../../resources/js/utils/managementForm.js';

const id = '12345678-1234-4234-8234-123456789abc';
const second = '12345678-1234-4234-8234-123456789abd';
const resident = { id, name: 'Lansia fixture', birth_date: '1950-01-02', family: { id: second } };
const camera = { id, room: { id: second }, name: 'Kamera fixture', source_type: 'rtsp', version: 'a'.repeat(64), is_active: false };
const room = { id, code: 'UJI', service_class: { id: second }, name: 'Kamar fixture', capacity: 2 };
const event = () => ({ prevented: 0, preventDefault() { this.prevented++; } });

function walk(node, visit) {
    if (!node || typeof node !== 'object') return;
    if (node.type) visit(node);
    for (const value of Object.values(node)) {
        if (Array.isArray(value)) value.forEach((child) => walk(child, visit));
        else if (value && typeof value === 'object') walk(value, visit);
    }
}
function source(path) {
    const code = fs.readFileSync(new URL('../../resources/js/' + path + '.jsx', import.meta.url), 'utf8');
    return { code, ast: parseAst(code, { lang: 'jsx' }, path + '.jsx') };
}
// Execute the actual existing handler with only its collaborators replaced.
// No browser, database, request, or copied handler implementation is involved.
function handler(path, name, bindings) {
    const { code, ast } = source(path);
    const page = ast.body.find((node) => node.type === 'ExportDefaultDeclaration').declaration;
    let found;
    for (const statement of page.body.body) {
        if (statement.type === 'FunctionDeclaration' && statement.id.name === name) found = statement;
        for (const declaration of statement.declarations ?? []) {
            if (declaration.id.name === name) found = declaration.init;
        }
    }
    assert.ok(found, path + ':' + name);
    return Function(...Object.keys(bindings), 'return (' + code.slice(found.start, found.end) + ')')(...Object.values(bindings));
}
function initial(path, bindings = {}) {
    const { code, ast } = source(path);
    const forms = {};
    walk(ast, (node) => {
        if (node.type !== 'VariableDeclarator' || node.init?.callee?.name !== 'useForm') return;
        const argument = node.init.arguments[0];
        forms[node.id.name] = Function(...Object.keys(bindings), 'return (' + code.slice(argument.start, argument.end) + ')')(...Object.values(bindings));
    });
    return forms;
}
function mockForm(data = {}) {
    return {
        data: structuredClone(data), processing: false, requests: [], resets: [],
        post(url, options) { this.requests.push({ method: 'post', url, data: structuredClone(this.data), options }); },
        patch(url, options) { this.requests.push({ method: 'patch', url, data: structuredClone(this.data), options }); },
        get(url, options) { this.requests.push({ method: 'get', url, data: structuredClone(this.data), options }); },
        reset(...fields) { this.resets.push(fields); }, transform(callback) { this.data = callback(this.data); },
    };
}

for (const [path, bindings, expected] of [
    ['Accounts/Create', {}, { form: { name: '', email: '', password: '', password_confirmation: '' } }],
    ['Accounts/ResetPassword', {}, { form: { current_password: '', password: '', password_confirmation: '' } }],
    ['Residents/Create', {}, { form: { name: '', birth_date: '', family_id: '' } }],
    ['Residents/Edit', { resident }, { info: { name: resident.name, birth_date: resident.birth_date }, family: { family_id: '', current_family_id: second, confirm_reassignment: false } }],
    ['Residents/Show', {}, { status: { confirm_status_change: true } }],
    ['ServiceClasses/Edit', { item: { name: 'VIP fixture', monthly_price: null } }, { form: { name: 'VIP fixture', monthly_price: '' } }],
    ['Rooms/Create', {}, { form: { code: '', name: '', service_class_id: '', capacity: '' } }],
    ['Rooms/Edit', { room }, { form: { code: 'UJI', name: room.name, service_class_id: second, current_service_class_id: second, capacity: '2', confirm_class_change: false } }],
    ['Cameras/Create', {}, { form: { room_id: '', name: '', source_type: 'webcam', source_reference: '', confirm_create: false } }],
    ['Cameras/Edit', { camera }, { form: { room_id: second, current_room_id: second, name: camera.name, source_type: 'rtsp', source_reference: '', source_action: 'keep', version: camera.version, confirm_reassignment: false } }],
    ['Operations/CreateEnrollment', {}, { form: { service_class_id: '', confirm_start: false } }],
    ['Operations/StartStay', { filters: { search: 'UJI' }, operations: { enrollment: { id: second } } }, { filter: { search: 'UJI' }, form: { room_id: '', enrollment_id: second, confirm_start: false } }],
]) {
    test('payload awal tetap: ' + path, () => assert.deepEqual(initial('pages/Admin/' + path, bindings), expected));
}

for (const [path, values] of [
    ['Accounts/Index', { search: 'dua kata', role: 'keluarga', status: 'inactive' }],
    ['Residents/Index', { search: 'dua kata', status: 'active' }],
    ['Rooms/Index', { search: 'dua kata', service_class_id: second, status: 'inactive' }],
    ['Cameras/Index', { search: 'dua kata', room_id: second, status: 'active' }],
]) {
    test('filter mempertahankan seluruh parameter dan opsi: ' + path, () => {
        const form = mockForm(initial('pages/Admin/' + path, { filters: values }).form);
        assert.deepEqual(form.data, values);
        const area = path.split('/')[0].replace(/[A-Z]/g, (letter, index) => (index ? '-' : '') + letter.toLowerCase());
        const name = area === 'residents' ? 'submit' : area === 'cameras' ? 'search' : 'filter';
        handler('pages/Admin/' + path, name, { form, base: '/admin/residents' })(event());
        assert.deepEqual(form.requests, [{ method: 'get', url: '/admin/' + area, data: values, options: { preserveState: true, replace: true } }]);
    });
}

for (const type of ['perawat', 'keluarga']) {
    test('pembuatan akun tetap endpoint role ' + type + ' dan mengosongkan hanya kata sandi pada finish', () => {
        const form = mockForm({ name: 'Fixture', email: 'fixture@example.test', password: 'Aa123456', password_confirmation: 'Aa123456' });
        handler('pages/Admin/Accounts/Create', 'submit', { form, type })(event());
        assert.equal(form.requests.length, 1);
        assert.equal(form.requests[0].url, '/admin/accounts/' + type);
        assert.equal(form.requests[0].data.password, 'Aa123456');
        assert.equal(form.requests[0].data.role, undefined);
        assert.deepEqual(form.resets, []);
        form.requests[0].options.onFinish();
        assert.deepEqual(form.resets, [['password', 'password_confirmation']]);
    });
}
test('reset password memakai endpoint existing dan reset seluruh isian pada finish', () => {
    const form = mockForm({ current_password: 'Aa123456', password: 'Bb123456', password_confirmation: 'Bb123456' });
    handler('pages/Admin/Accounts/ResetPassword', 'submit', { form, account: { id } })(event());
    assert.equal(form.requests[0].method, 'post');
    assert.equal(form.requests[0].url, '/admin/accounts/' + id + '/password');
    form.requests[0].options.onFinish();
    assert.deepEqual(form.resets, [[]]);
});

test('status akun mempertahankan konfirmasi, preserveScroll, dan pelepasan pending', () => {
    const target = { id, name: 'Fixture', is_active: true };
    const calls = [], pendingValues = [];
    const router = { post: (...arguments_) => { calls.push(arguments_); } };
    const bindings = { pending: null, router, setPending: (value) => pendingValues.push(value) };
    handler('pages/Admin/Accounts/Index', 'changeStatus', { ...bindings, window: { confirm: () => false } })(target);
    assert.equal(calls.length, 0);
    handler('pages/Admin/Accounts/Index', 'changeStatus', { ...bindings, window: { confirm: () => true } })(target);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/admin/accounts/' + id + '/deactivate');
    assert.deepEqual(calls[0][1], {});
    assert.equal(calls[0][2].preserveScroll, true);
    calls[0][2].onStart();
    calls[0][2].onFinish();
    assert.deepEqual(pendingValues, [id, null]);
    handler('pages/Admin/Accounts/Index', 'changeStatus', { ...bindings, pending: id, window: { confirm: () => true } })(target);
    assert.equal(calls.length, 1);
});
test('pencarian kamar stay tidak menambah parameter atau opsi GET', () => {
    const filter = mockForm({ search: 'UJI 01' });
    handler('pages/Admin/Operations/StartStay', 'search', { filter, resident })(event());
    assert.deepEqual(filter.requests, [{ method: 'get', url: '/admin/residents/' + id + '/stays/create', data: { search: 'UJI 01' }, options: undefined }]);
});

function controlHandler(path, id, attribute, bindings) {
    const { code, ast } = source(path);
    let found;
    walk(ast, (node) => {
        if (node.type !== 'JSXOpeningElement') return;
        if (!node.attributes.some((entry) => entry.name?.name === 'id' && entry.value?.value === id)) return;
        found = node.attributes.find((entry) => entry.name?.name === attribute)?.value?.expression;
    });
    assert.ok(found, path + ':' + id + ':' + attribute);
    return Function(...Object.keys(bindings), 'return (' + code.slice(found.start, found.end) + ')')(...Object.values(bindings));
}
test('Enter pada dua picker mencari atau mempertahankan debounce, tanpa submit parent', () => {
    let loads = 0;
    const family = controlHandler('components/FamilySelector', 'family-search', 'onKeyDown', { load: () => { loads++; } });
    const e = { ...event(), key: 'Enter' };
    family(e);
    assert.equal(e.prevented, 1);
    assert.equal(loads, 1);
    const room = controlHandler('components/CameraRoomSelector', 'camera-room-search', 'onKeyDown', {});
    const r = { ...event(), key: 'Enter' };
    room(r);
    assert.equal(r.prevented, 1);
    const tab = { ...event(), key: 'Tab' };
    room(tab);
    assert.equal(tab.prevented, 0);
});
test('penggantian tipe/action kamera mengosongkan hanya referensi baru dan mempertahankan token serta kamar', () => {
    const form = { data: { room_id: second, current_room_id: second, source_type: 'rtsp', source_action: 'keep', source_reference: 'fixture-only', version: camera.version, confirm_reassignment: false }, setData(value) { this.data = value; } };
    controlHandler('components/CameraFields', 'source_type', 'onChange', { form, camera })({ target: { value: 'webcam' } });
    assert.deepEqual(form.data, { room_id: second, current_room_id: second, source_type: 'webcam', source_action: 'clear', source_reference: '', version: camera.version, confirm_reassignment: false });
    controlHandler('components/CameraFields', 'source_type', 'onChange', { form, camera })({ target: { value: 'uploaded_video' } });
    assert.equal(form.data.source_action, 'replace');
    form.data.source_reference = 'fixture-only';
    controlHandler('components/CameraFields', 'source_action', 'onChange', { form })({ target: { value: 'keep' } });
    assert.equal(form.data.source_reference, '');
    assert.equal(form.data.version, camera.version);
    assert.equal(form.data.room_id, second);
});

for (const [path, name, key, payload, bindings, method, url] of [
    ['Residents/Create', 'submit', 'form', { name: 'Fixture', birth_date: '1950-01-01', family_id: second }, {}, 'post', '/admin/residents'],
    ['Residents/Edit', 'save', 'info', { name: 'Fixture', birth_date: '1950-01-01' }, { resident }, 'patch', '/admin/residents/' + id],
    ['ServiceClasses/Edit', 'save', 'form', { name: 'VIP fixture', monthly_price: '0.00' }, { item: { id } }, 'patch', '/admin/service-classes/' + id],
    ['Rooms/Create', 'save', 'form', { code: 'UJI', name: 'Fixture', service_class_id: second, capacity: '1' }, {}, 'post', '/admin/rooms'],
    ['Rooms/Edit', 'save', 'form', { code: 'UJI', name: 'Fixture', service_class_id: second, current_service_class_id: second, capacity: '2', confirm_class_change: false }, { room }, 'patch', '/admin/rooms/' + id],
    ['Operations/CreateEnrollment', 'save', 'form', { service_class_id: second, confirm_start: true }, { resident }, 'post', '/admin/residents/' + id + '/enrollments'],
    ['Operations/StartStay', 'save', 'form', { room_id: second, enrollment_id: id, confirm_start: true }, { resident }, 'post', '/admin/residents/' + id + '/stays'],
]) {
    test('submit hanya payload dan endpoint existing: ' + path, () => {
        const form = mockForm(payload);
        handler('pages/Admin/' + path, name, { ...bindings, [key]: form, window: { confirm: () => true } })(event());
        assert.deepEqual(form.requests, [{ method, url, data: payload, options: undefined }]);
        assert.deepEqual(form.resets, []);
    });
}

for (const accepted of [false, true]) {
    test('reassign keluarga mempertahankan konfirmasi, referensi lama, dan isian; persetujuan=' + accepted, () => {
        const data = { family_id: id, current_family_id: second, confirm_reassignment: true };
        const family = mockForm(data);
        let message;
        handler('pages/Admin/Residents/Edit', 'reassign', { family, resident, window: { confirm: (value) => { message = value; return accepted; } } })(event());
        assert.match(message, /kehilangan akses/);
        assert.deepEqual(family.data, data);
        assert.equal(family.requests.length, Number(accepted));
        if (accepted) assert.deepEqual(family.requests[0], { method: 'post', url: '/admin/residents/' + id + '/family', data, options: undefined });
        assert.deepEqual(family.resets, []);
    });
    test('perubahan kelas kamar tetap membutuhkan konfirmasi; persetujuan=' + accepted, () => {
        const form = mockForm({ service_class_id: id, current_service_class_id: second, confirm_class_change: true, capacity: '1' });
        handler('pages/Admin/Rooms/Edit', 'save', { form, room, window: { confirm: () => accepted } })(event());
        assert.equal(form.requests.length, Number(accepted));
        assert.equal(form.data.current_service_class_id, second);
    });
    test('pemindahan kamera tetap membutuhkan konfirmasi; persetujuan=' + accepted, () => {
        const form = mockForm({ room_id: id, current_room_id: second, source_reference: '', version: camera.version, confirm_reassignment: true });
        handler('pages/Admin/Cameras/Edit', 'save', { form, camera, window: { confirm: () => accepted } })(event());
        assert.equal(form.requests.length, Number(accepted));
        assert.equal(form.data.current_room_id, second);
    });
}
for (const path of ['Create', 'Edit']) {
    test('sumber privat tidak dipantulkan; hanya isian sumber baru dibersihkan pada validation/success: ' + path, () => {
        const form = mockForm({ room_id: second, name: 'Fixture', source_type: 'rtsp', source_reference: 'rtsp://fixture.invalid/test', source_action: 'replace' });
        handler('pages/Admin/Cameras/' + path, 'save', { form, camera, window: { confirm: () => true } })(event());
        assert.equal(form.requests[0].method, path === 'Create' ? 'post' : 'patch');
        assert.equal(form.requests[0].url, '/admin/cameras' + (path === 'Edit' ? '/' + id : ''));
        assert.deepEqual(form.resets, []);
        form.requests[0].options.onError();
        form.requests[0].options.onSuccess();
        assert.deepEqual(form.resets, [['source_reference'], ['source_reference']]);
        assert.equal(form.data.name, 'Fixture');
    });
}

for (const [path, name, bindings, initialData, url] of [
    ['components/FacilityStatusAction', 'changeStatus', { target: { id, name: 'Fixture', is_active: true }, kind: 'rooms' }, { confirm_status_change: true }, '/admin/rooms/' + id + '/deactivate'],
    ['components/CameraStatusAction', 'update', { camera }, { confirm_status_change: true }, '/admin/cameras/' + id + '/activate'],
    ['components/CloseOperationAction', 'close', { residentId: second, record: { id }, kind: 'enrollments', isStay: false, disabled: false }, { confirm_end: true }, '/admin/residents/' + second + '/enrollments/' + id + '/close'],
    ['pages/Admin/Residents/Show', 'changeStatus', { resident: { ...resident, is_active: true } }, { confirm_status_change: true }, '/admin/residents/' + id + '/deactivate'],
]) {
    for (const accepted of [false, true]) {
        test('tindakan mempertahankan konfirmasi dan endpoint ' + path + '/' + accepted, () => {
            const form = mockForm(initialData);
            const fn = handler(path, name, { ...bindings, form, status: form, window: { confirm: () => accepted } });
            fn();
            assert.equal(form.requests.length, Number(accepted));
            if (accepted) {
                assert.equal(form.requests[0].method, 'post');
                assert.equal(form.requests[0].url, url);
                assert.deepEqual(form.requests[0].data, { ...initialData, ...(path.includes('CameraStatus') ? { version: camera.version } : {}) });
            }
            form.processing = true;
            fn();
            assert.equal(form.requests.length, Number(accepted));
        });
    }
}

test('submit latch menolak pengiriman ganda dan processing, kemudian pulih setelah batal/error', async () => {
    const latch = { current: false }, submitting = { current: false };
    const data = Object.freeze({ name: 'Fixture', family_id: id });
    let calls = 0;
    const options = { event: event(), processing: false, latch, submitting, submit: () => { assert.equal(submitting.current, true); calls++; } };
    assert.equal(submitManagementForm(options), true);
    assert.equal(submitManagementForm(options), false);
    await Promise.resolve();
    assert.equal(submitting.current, false);
    assert.equal(submitManagementForm({ ...options, processing: true }), false);
    assert.throws(() => submitManagementForm({ ...options, submit: () => { throw Error('fixture'); } }), /fixture/);
    await Promise.resolve();
    assert.equal(submitManagementForm(options), true);
    assert.equal(calls, 2);
    assert.deepEqual(data, { name: 'Fixture', family_id: id });
});

function navigationFixture() {
    let before;
    let removed = 0;
    const windowTarget = new EventTarget();
    const state = { dirty: false, submitting: false };
    let answer = false, prompts = 0;
    const cleanup = protectManagementInput({
        router: { on: (name, callback) => { assert.equal(name, 'before'); before = callback; return () => { removed++; }; } },
        windowTarget, current: () => state, confirm: (message) => { assert.match(message, /belum disimpan/); prompts++; return answer; },
    });
    return { state, windowTarget, cleanup, navigate: () => { const e = event(); before(e); return e.prevented; }, answer: (value) => { answer = value; }, prompts: () => prompts, removed: () => removed };
}
test('guard hanya memperingatkan isian kotor; batal mempertahankan input dan submit sendiri lolos', () => {
    const fixture = navigationFixture();
    assert.equal(fixture.navigate(), 0);
    fixture.state.dirty = true;
    assert.equal(fixture.navigate(), 1);
    assert.equal(fixture.state.dirty, true);
    fixture.answer(true);
    assert.equal(fixture.navigate(), 0);
    fixture.state.submitting = true;
    assert.equal(fixture.navigate(), 0);
    assert.equal(fixture.prompts(), 2);
    fixture.cleanup();
    assert.equal(fixture.removed(), 1);
});
test('beforeunload hanya mencegah kehilangan isian kotor, cleanup melepas listener', () => {
    const fixture = navigationFixture();
    const unload = () => { const e = new Event('beforeunload', { cancelable: true }); fixture.windowTarget.dispatchEvent(e); return e.defaultPrevented; };
    assert.equal(unload(), false);
    fixture.state.dirty = true;
    assert.equal(unload(), true);
    fixture.cleanup();
    assert.equal(unload(), false);
});
test('fokus error memilih input, radio aktif, alias selector; referensi tersembunyi jatuh ke summary', () => {
    let focused;
    const control = (name, disabled = false) => ({ disabled, dataset: { managementField: name }, focus: () => { focused = name; } });
    const email = control('email'), radio = control('room_id'), family = control('family_id');
    const fields = { email, room_id: [control('disabled', true), radio], version: control('version', true) };
    const form = { elements: { namedItem: (field) => fields[field] }, querySelectorAll: () => [family] };
    const summary = { focus: () => { focused = 'summary'; } };
    assert.equal(focusManagementErrors(form, summary, {}), false);
    assert.equal(focusManagementErrors(form, summary, { email: 'Salah' }), true);
    assert.equal(focused, 'email');
    assert.equal(managementErrorTarget(form, 'room_id'), radio);
    focusManagementErrors(form, summary, { family_id: 'Pilih keluarga' });
    assert.equal(focused, 'family_id');
    focusManagementErrors(form, summary, { version: 'Versi berubah' });
    assert.equal(focused, 'summary');
    focusManagementErrors(null, summary, { operation: 'Konflik' });
    assert.equal(focused, 'summary');
});

test('fokus error mengabaikan kontrol hidden dan kontrol dalam fieldset nonaktif', () => {
    let focused = false;
    const hidden = { type: 'hidden', focus: () => { focused = true; } };
    const inherited = { disabled: false, matches: (selector) => selector === ':disabled', focus: () => { focused = true; } };
    const form = { elements: { namedItem: (name) => name === 'version' ? hidden : inherited }, querySelectorAll: () => [] };
    assert.equal(managementErrorTarget(form, 'version'), undefined);
    assert.equal(managementErrorTarget(form, 'room_id'), undefined);
    assert.equal(focused, false);
});
