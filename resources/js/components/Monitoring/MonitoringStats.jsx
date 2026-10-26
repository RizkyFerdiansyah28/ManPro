function Cards({ items }) {
    return <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{items.map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5"><dt className="text-xs font-medium leading-6 text-slate-500">{label}</dt><dd className="mt-3 text-3xl font-semibold tracking-tight text-slate-900">{value}</dd></div>)}</dl>;
}
export default function MonitoringStats({ stats, cameras, admin }) {
    return <div className="space-y-8">
        {admin && <section aria-label="Kamera saat ini"><h2 className="mb-4 text-sm font-semibold">Kamera · kondisi administrasi saat ini</h2><Cards items={[[ 'Kamera terdaftar', cameras.registered ], [ 'Aktif administrasi', cameras.active ], [ 'Nonaktif administrasi', cameras.inactive ]]} /><p className="mt-3 text-xs leading-6 text-slate-500">Status administrasi bukan konektivitas online/offline dan tidak mengikuti filter waktu event.</p></section>}
        <section aria-label="Statistik kejadian terpilih"><h2 className="mb-4 text-sm font-semibold">Kejadian · periode terpilih</h2><Cards items={[[ 'Deteksi fall', stats.fall_detections ], [ 'Observasi gait', stats.gait_observations ], [ 'Pending', stats.pending ], [ 'Diakui · belum selesai', stats.acknowledged ], [ 'Selesai', stats.resolved ], [ 'Alarm palsu', stats.false_alarm ]]} /><p className="mt-3 text-xs leading-6 text-slate-500">{stats.verified} insiden tercatat terverifikasi dalam periode ini. Verifikasi adalah atribut terpisah dari status; pending bukan fall terkonfirmasi.</p></section>
    </div>;
}
