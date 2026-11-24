import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { createCameraPlayback } from '../../utils/cameraPlayback';
import Button from '../ui/Button';
import StatusBadge from '../ui/StatusBadge';
import MonitoringSection from './MonitoringSection';

const statuses = {
    idle: ['neutral', 'Berhenti'],
    requesting: ['info', 'Menunggu izin webcam'],
    'loading-video': ['info', 'Memuat video simulasi'],
    live: ['positive', 'Webcam aktif'],
    simulation: ['positive', 'Video simulasi diputar berulang'],
    'permission-denied': ['warning', 'Izin webcam ditolak. Periksa pengaturan izin kamera di browser, lalu tekan Start kembali.'],
    'permission-pending': ['warning', 'Selesaikan permintaan izin webcam sebelumnya, lalu tekan Start kembali.'],
    'webcam-missing': ['warning', 'Webcam tidak ditemukan. Sambungkan webcam atau pilih video simulasi.'],
    'webcam-unavailable': ['warning', 'Webcam tidak dapat dibuka. Periksa perangkat dan aplikasi lain yang sedang memakai kamera.'],
    'webcam-unsupported': ['warning', 'Webcam memerlukan browser yang mendukung akses kamera di HTTPS atau localhost.'],
    'webcam-ended': ['warning', 'Sambungan webcam berakhir. Tekan Start untuk mencoba kembali.'],
    'invalid-video': ['warning', 'Pilih file MP4 yang berisi video.'],
    'playback-error': ['warning', 'Video tidak dapat diputar. Coba ulang atau pilih MP4 dengan codec yang didukung browser.'],
};

export default function CameraMonitor({ cameras = [] }) {
    const id = useId();
    const [cameraId, setCameraId] = useState('');
    const camera = cameras.find((option) => option.id === cameraId);

    return (
        <MonitoringSection title="Monitoring kamera demo" description="Pilih kamera untuk menandai kamar yang sedang didemokan. Video berasal dari webcam perangkat ini atau MP4 lokal dan hanya diputar di browser.">
            {cameras.length === 0 ? (
                <p className="ui-monitoring-copy">Belum ada kamera terdaftar. Admin dapat mendaftarkan kamera pada kamar untuk memulai demo.</p>
            ) : (
                <div className="space-y-5">
                    <div className="ui-field-group max-w-xl">
                        <label className="ui-field-label" htmlFor={id}>Kamera dan kamar</label>
                        <select id={id} className="ui-field-control" value={camera?.id ?? ''} onChange={(event) => setCameraId(event.target.value)}>
                            <option value="">Pilih kamera</option>
                            {cameras.map((option) => (
                                <option key={option.id} value={option.id}>{`${option.name} — ${option.room.code} · ${option.room.name}`}</option>
                            ))}
                        </select>
                    </div>
                    {camera && <CameraPreview key={`${camera.id}:${camera.room.id}`} />}
                </div>
            )}
        </MonitoringSection>
    );
}

function CameraPreview() {
    const id = useId();
    const video = useRef(null);
    const playback = useRef(null);
    const [source, setSource] = useState('webcam');
    const [file, setFile] = useState(null);
    const [status, setStatus] = useState('idle');
    const webcam = source === 'webcam';
    const busy = ['requesting', 'loading-video', 'live', 'simulation'].includes(status);
    const [tone, statusLabel] = statuses[status];

    useEffect(() => {
        const mediaDevices = navigator.mediaDevices;
        const player = createCameraPlayback({
            video: video.current,
            requestWebcam: mediaDevices?.getUserMedia?.bind(mediaDevices),
            createUrl: (selectedFile) => URL.createObjectURL(selectedFile),
            revokeUrl: (url) => URL.revokeObjectURL(url),
            onStatus: setStatus,
        });
        playback.current = player;
        const stopOnExit = () => player.stop();
        const removeBeforeVisit = router.on('before', ({ detail: { visit } }) => {
            if (visit.url.pathname !== window.location.pathname) stopOnExit();
        });
        window.addEventListener('pagehide', stopOnExit);

        return () => {
            removeBeforeVisit();
            window.removeEventListener('pagehide', stopOnExit);
            playback.current = null;
            player.dispose();
        };
    }, []);

    function changeSource(event) {
        playback.current?.stop();
        setSource(event.target.value);
        setFile(null);
    }

    function changeFile(event) {
        playback.current?.stop();
        setFile(event.target.files[0] ?? null);
    }

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <figure className="min-w-0">
                <div className="relative grid aspect-video place-items-center overflow-hidden rounded-lg bg-slate-950 text-slate-200">
                    <video ref={video} muted playsInline disablePictureInPicture disableRemotePlayback aria-labelledby={`${id}-caption`} className="h-full w-full object-contain" />
                    {status !== 'live' && status !== 'simulation' && (
                        <p className="pointer-events-none absolute px-4 text-center text-sm">{busy ? 'Menyiapkan video…' : 'Pratinjau belum aktif'}</p>
                    )}
                </div>
                <figcaption id={`${id}-caption`} className="ui-monitoring-copy mt-2">
                    {webcam ? 'Live webcam · perangkat ini' : 'Video simulasi · file MP4 lokal'}
                </figcaption>
            </figure>
            <div className="flex min-w-0 flex-col gap-4">
                <div className="ui-field-group">
                    <label className="ui-field-label" htmlFor={`${id}-source`}>Sumber video</label>
                    <select id={`${id}-source`} className="ui-field-control" value={source} onChange={changeSource}>
                        <option value="webcam">Webcam laptop (live)</option>
                        <option value="simulation">Video simulasi (MP4)</option>
                    </select>
                </div>
                {!webcam && (
                    <div className="ui-field-group">
                        <label className="ui-field-label" htmlFor={`${id}-file`}>File MP4</label>
                        <input id={`${id}-file`} type="file" accept=".mp4,video/mp4" className="ui-field-control" onChange={changeFile} />
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    <Button disabled={busy || (!webcam && !file)} onClick={() => playback.current?.start(source, file)}>Start</Button>
                    <Button variant="secondary" disabled={!busy} onClick={() => playback.current?.stop()}>Stop</Button>
                </div>
                <div role="status" className="min-w-0">
                    <StatusBadge tone={tone} className="whitespace-normal">{statusLabel}</StatusBadge>
                </div>
            </div>
        </div>
    );
}
