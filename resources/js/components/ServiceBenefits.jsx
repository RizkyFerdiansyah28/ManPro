const benefits = [
    ['private_room', 'Kamar privat'],
    ['staff_ai_monitoring', 'Monitoring AI oleh petugas'],
    ['instant_verified_emergency', 'Notifikasi darurat instan ke keluarga (setelah verifikasi)'],
    ['verified_incident_summary', 'Riwayat insiden terverifikasi'],
    ['monthly_gait_report', 'Rekap gait bulanan'],
    ['weekly_gait_report', 'Rekap gait mingguan'],
    ['daily_gait_report', 'Laporan gait harian'],
    ['family_live_cctv', 'CCTV live untuk keluarga'],
];

export default function ServiceBenefits({ serviceClass }) {
    const approved = serviceClass.approved_benefits;
    const values = approved ? { ...approved, private_room: approved.room_type === 'private', staff_ai_monitoring: serviceClass.staff_ai_monitoring } : null;
    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <h2 className="text-lg font-semibold">Manfaat kelas</h2>
            <p className="mt-2 text-sm leading-6 text-slate-500">Matriks manfaat final yang disetujui. Monitoring AI dan alert petugas berlaku untuk semua kelas, terlepas dari status pembayaran.</p>
            <p className="mt-2 text-xs leading-6 text-slate-500">Ini adalah ketentuan layanan. Integrasi AI, pengiriman notifikasi, laporan gait, dan CCTV belum dijalankan oleh halaman ini.</p>
            {!serviceClass.is_canonical && <p role="alert" className="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Data manfaat tersimpan belum sesuai matriks final. Sinkronisasi memerlukan persetujuan; daftar di bawah menunjukkan aturan final, bukan konfirmasi bahwa data sudah disinkronkan. Perubahan kelas dan kamar yang bergantung pada konfigurasi ini ditolak sampai data sesuai.</p>}
            {values ? <dl className="mt-6 divide-y divide-slate-100 text-sm">
                {benefits.map(([key, label]) => <div key={key} className="flex items-start justify-between gap-5 py-4">
                    <dt className="max-w-sm text-slate-700">{label}</dt>
                    <dd className={`whitespace-nowrap font-semibold ${values[key] ? 'text-emerald-800' : 'text-slate-400'}`}>{values[key] ? 'Ya' : 'Tidak'}</dd>
                </div>)}
            </dl> : <p role="alert" className="mt-6 text-sm text-red-700">Kode kelas tidak dikenali. Matriks manfaat perlu diperiksa operator.</p>}
        </section>
    );
}
