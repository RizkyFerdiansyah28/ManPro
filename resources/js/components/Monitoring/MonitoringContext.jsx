export default function MonitoringContext({ context }) {
    if (context.state === 'legacy') return <p className="max-w-52 text-xs leading-6 text-slate-500">Legacy · lokasi historis tidak tersedia</p>;
    if (context.state !== 'tracked') return <p className="max-w-52 text-xs leading-6 text-amber-800">Konteks historis tidak konsisten; lokasi tidak ditampilkan.</p>;
    return <><p className="font-medium">{context.room.code} · {context.room.name}</p><p className="mt-1 text-xs text-slate-500">{context.service_class.name}</p></>;
}
