export default function MonitoringContext({ context }) {
    if (context.state === 'legacy') return <p className="ui-monitoring-table__note">Legacy · lokasi historis tidak tersedia</p>;
    if (context.state !== 'tracked') return <p className="ui-monitoring-table__note ui-monitoring-table__context-warning">Konteks historis tidak konsisten; lokasi tidak ditampilkan.</p>;
    return <><p className="ui-monitoring-table__entity">{context.room.code} · {context.room.name}</p><p className="ui-monitoring-table__note">{context.service_class.name}</p></>;
}
