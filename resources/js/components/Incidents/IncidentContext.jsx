export default function IncidentContext({ context }) {
    if (context.state === 'legacy') return <p className="ui-monitoring-copy">Legacy · lokasi historis tidak tersedia.</p>;
    if (context.state !== 'tracked') return <p className="ui-incident-context-warning">Konteks historis tidak konsisten; lokasi tidak ditampilkan.</p>;
    return <div className="ui-incident-location"><p>{context.room_code} · {context.room_name}</p><p className="ui-monitoring-copy">{context.class_name}</p></div>;
}
