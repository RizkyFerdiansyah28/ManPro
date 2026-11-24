const webcamErrors = {
    NotAllowedError: 'permission-denied',
    SecurityError: 'permission-denied',
    NotFoundError: 'webcam-missing',
    NotReadableError: 'webcam-unavailable',
};

export function isSimulationVideo(file) {
    return Boolean(file && file.size > 0 && /\.mp4$/i.test(file.name) && (!file.type || file.type === 'video/mp4'));
}

export function createCameraPlayback({ video, requestWebcam, createUrl, revokeUrl, onStatus }) {
    let active = null;
    let pendingWebcam = false;

    function release() {
        if (!active) return;
        const { tracks, url, ended, playbackError } = active;
        active = null;
        video.removeEventListener('error', playbackError);
        for (const track of tracks) {
            track.removeEventListener('ended', ended);
            track.stop();
        }
        video.pause();
        video.srcObject = null;
        video.removeAttribute('src');
        video.loop = false;
        video.load();
        if (url !== null) revokeUrl(url);
    }

    function stop(status = 'idle') {
        release();
        onStatus(status);
    }

    async function start(source, file) {
        if (active) return;
        const webcam = source === 'webcam';
        if (webcam && !requestWebcam) return onStatus('webcam-unsupported');
        if (webcam && pendingWebcam) return onStatus('permission-pending');
        if (!webcam && !isSimulationVideo(file)) return onStatus('invalid-video');

        const playback = { tracks: [], url: null, ended, playbackError };
        active = playback;
        onStatus(webcam ? 'requesting' : 'loading-video');

        function ended() {
            if (active === playback) stop('webcam-ended');
        }

        function playbackError() {
            if (active === playback && video.error) stop('playback-error');
        }

        if (webcam) {
            pendingWebcam = true;
            try {
                const stream = await requestWebcam({ video: true, audio: false });
                const tracks = stream.getTracks();
                // The browser can grant permission after Stop, a source switch or unmount.
                if (active !== playback) {
                    for (const track of tracks) track.stop();
                    return;
                }
                playback.tracks = tracks;
                for (const track of tracks) track.addEventListener('ended', ended);
                if (!stream.active) return stop('webcam-ended');
                video.srcObject = stream;
            } catch (error) {
                if (active === playback) stop(webcamErrors[error?.name] ?? 'webcam-unavailable');
                return;
            } finally {
                pendingWebcam = false;
            }
        }

        try {
            video.addEventListener('error', playbackError);
            if (!webcam) {
                playback.url = createUrl(file);
                video.loop = true;
                video.src = playback.url;
            }
            await video.play();
            if (active === playback) onStatus(webcam ? 'live' : 'simulation');
        } catch {
            if (active === playback) stop('playback-error');
        }
    }

    return { start, stop, dispose: release };
}
