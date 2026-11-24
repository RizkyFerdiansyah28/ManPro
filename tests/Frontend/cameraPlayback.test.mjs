import assert from 'node:assert/strict';
import test from 'node:test';
import { createCameraPlayback, isSimulationVideo } from '../../resources/js/utils/cameraPlayback.js';

const mp4 = { name: 'demo.MP4', size: 256, type: 'video/mp4' };

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((onResolve, onReject) => {
        resolve = onResolve;
        reject = onReject;
    });
    return { promise, resolve, reject };
}

function makeStream() {
    const tracks = Array.from({ length: 2 }, () => Object.assign(new EventTarget(), {
        stops: 0,
        stop() { this.stops++; },
    }));
    return { tracks, stream: { active: true, getTracks: () => tracks } };
}

function fixture(requestWebcam) {
    const statuses = [];
    const created = [];
    const revoked = [];
    const video = Object.assign(new EventTarget(), {
        srcObject: null, loop: false, error: null, loads: 0, pauses: 0,
        async play() {},
        pause() { this.pauses++; },
        removeAttribute(name) { delete this[name]; },
        load() { this.loads++; this.error = null; },
    });
    const player = createCameraPlayback({
        video, requestWebcam,
        createUrl(file) { created.push(file); return `blob:simulation-${created.length}`; },
        revokeUrl: (url) => revoked.push(url),
        onStatus: (status) => statuses.push(status),
    });
    return { player, video, statuses, created, revoked };
}

test('webcam menunggu Start, hanya meminta video dan berhenti pada semua track', async () => {
    const { stream, tracks } = makeStream();
    const requests = [];
    const { player, video, statuses } = fixture((options) => {
        requests.push(options);
        return stream;
    });
    assert.deepEqual(requests, []);
    await player.start('webcam');
    await player.start('webcam');
    assert.deepEqual(requests, [{ video: true, audio: false }]);
    assert.equal(video.srcObject, stream);
    assert.equal(video.loop, false);
    assert.deepEqual(statuses, ['requesting', 'live']);
    player.stop();
    player.dispose();
    assert.deepEqual(tracks.map((track) => track.stops), [1, 1]);
    assert.equal(video.srcObject, null);
    assert.equal(video.pauses, 1);
    assert.equal(video.loads, 1);
    assert.deepEqual(statuses, ['requesting', 'live', 'idle']);
});

test('izin ditolak dan API tidak tersedia dapat dibedakan tanpa membuka stream', async () => {
    const denied = fixture(async () => { throw new DOMException('for test', 'NotAllowedError'); });
    await denied.player.start('webcam');
    assert.deepEqual(denied.statuses, ['requesting', 'permission-denied']);
    assert.equal(denied.video.srcObject, null);
    const unsupported = fixture();
    await unsupported.player.start('webcam');
    assert.deepEqual(unsupported.statuses, ['webcam-unsupported']);
    assert.equal(unsupported.video.srcObject, null);
});

test('MP4 diputar berulang dan setiap Start punya object URL yang dilepas saat berhenti', async () => {
    const { player, video, statuses, created, revoked } = fixture(() => assert.fail('izin webcam diminta dari simulasi'));
    await player.start('simulation', mp4);
    assert.equal(video.loop, true);
    assert.equal(video.src, 'blob:simulation-1');
    assert.deepEqual(statuses, ['loading-video', 'simulation']);
    player.stop();
    assert.equal(video.loop, false);
    assert.equal(video.src, undefined);
    assert.deepEqual(revoked, ['blob:simulation-1']);
    await player.start('simulation', mp4);
    player.dispose();
    assert.deepEqual(created, [mp4, mp4]);
    assert.deepEqual(revoked, ['blob:simulation-1', 'blob:simulation-2']);
});

test('file kosong, tanpa ekstensi MP4, atau tipe lain tidak membuat object URL', async () => {
    const { player, statuses, created } = fixture();
    for (const file of [null, { ...mp4, size: 0 }, { ...mp4, name: 'demo.mov' }, { ...mp4, type: 'text/plain' }]) {
        await player.start('simulation', file);
    }
    assert.deepEqual(statuses, Array(4).fill('invalid-video'));
    assert.deepEqual(created, []);
    assert.equal(isSimulationVideo({ ...mp4, type: '' }), true);
});

test('webcam dengan izin tertunda tidak dapat menimpa simulasi setelah sumber beralih', async () => {
    const permission = deferred();
    const { stream, tracks } = makeStream();
    let requests = 0;
    const { player, video, statuses, revoked } = fixture(() => {
        requests++;
        return permission.promise;
    });
    const starting = player.start('webcam');
    player.stop();
    await player.start('webcam');
    assert.equal(requests, 1);
    assert.equal(statuses.at(-1), 'permission-pending');
    await player.start('simulation', mp4);
    permission.resolve(stream);
    await starting;
    assert.deepEqual(tracks.map((track) => track.stops), [1, 1]);
    assert.equal(video.srcObject, null);
    assert.equal(video.src, 'blob:simulation-1');
    assert.deepEqual(revoked, []);
    assert.equal(statuses.at(-1), 'simulation');
    player.dispose();
});

test('izin webcam yang baru selesai setelah keluar langsung melepaskan track tanpa memperbarui status', async () => {
    const permission = deferred();
    const { stream, tracks } = makeStream();
    const { player, video, statuses } = fixture(() => permission.promise);
    const starting = player.start('webcam');
    player.dispose();
    permission.resolve(stream);
    await starting;
    assert.deepEqual(tracks.map((track) => track.stops), [1, 1]);
    assert.equal(video.srcObject, null);
    assert.deepEqual(statuses, ['requesting']);
});

test('penolakan playback sumber lama tidak menghentikan sumber pengganti', async () => {
    const previousPlay = deferred();
    const { stream, tracks } = makeStream();
    const { player, video, statuses, revoked } = fixture(() => stream);
    video.play = () => previousPlay.promise;
    const starting = player.start('simulation', mp4);
    player.stop();
    video.play = async () => {};
    await player.start('webcam');
    previousPlay.reject(new DOMException('for test', 'AbortError'));
    await starting;
    assert.equal(statuses.at(-1), 'live');
    assert.equal(video.srcObject, stream);
    assert.deepEqual(revoked, ['blob:simulation-1']);
    assert.deepEqual(tracks.map((track) => track.stops), [0, 0]);
    player.dispose();
});

test('track berakhir dan decoder gagal sama-sama melepas sumber yang terpasang', async () => {
    const { stream, tracks } = makeStream();
    const { player, video, statuses, revoked } = fixture(() => stream);
    await player.start('webcam');
    tracks[0].dispatchEvent(new Event('ended'));
    assert.deepEqual(tracks.map((track) => track.stops), [1, 1]);
    assert.equal(video.srcObject, null);
    assert.equal(statuses.at(-1), 'webcam-ended');

    await player.start('simulation', mp4);
    video.error = { code: 3 };
    video.dispatchEvent(new Event('error'));
    assert.deepEqual(revoked, ['blob:simulation-1']);
    assert.equal(video.src, undefined);
    assert.equal(statuses.at(-1), 'playback-error');
});
