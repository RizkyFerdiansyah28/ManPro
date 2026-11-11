const benefits = [
    ['instant_verified_emergency', 'Notifikasi keluarga setelah verifikasi'],
    ['verified_incident_summary', 'Riwayat insiden terverifikasi'],
    ['monthly_gait_report', 'Rekap gait bulanan'],
    ['weekly_gait_report', 'Rekap gait mingguan'],
    ['daily_gait_report', 'Laporan gait harian'],
    ['family_live_cctv', 'Live CCTV keluarga'],
];
export default function FamilyBenefitAvailability({ catalog }) {
    return <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <h2 className="text-lg font-semibold">Manfaat dan ketersediaan fitur</h2>
        <p className="mt-3 text-sm leading-7 text-slate-600">Monitoring keselamatan dan alert petugas berlaku untuk semua kelas, terlepas dari pembayaran. Informasi ini bukan indikator perangkat online atau hasil kesehatan.</p>
        {catalog ? <><p className="mt-3 text-xs leading-6 text-slate-500">Daftar berikut adalah ketentuan kelas layanan. Fitur tambahan keluarga di bawah belum tersedia di portal ini.</p>
            <dl className="mt-5 divide-y divide-slate-100 text-sm">{benefits.map(([key, label]) => <div key={key} className="flex flex-wrap justify-between gap-3 py-4"><dt>{label}</dt><dd className="text-slate-600">{catalog[key] ? 'Tercantum dalam kelas · belum tersedia' : 'Tidak tercantum dalam kelas'}</dd></div>)}</dl></> : <p className="mt-4 text-sm text-slate-500">Ketentuan kelas belum dapat ditampilkan sampai informasi layanan tersedia dan dapat dipastikan.</p>}
        <p className="mt-4 text-xs leading-6 text-slate-500">Riwayat insiden, laporan gait, notifikasi keluarga, informasi tagihan/pembayaran, dan live CCTV belum tersedia. Tidak adanya data bukan bukti kondisi aman atau pembayaran lunas.</p>
    </section>;
}

