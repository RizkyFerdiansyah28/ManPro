import { Link } from '@inertiajs/react';
import StatusBadge from '../ui/StatusBadge';

const benefits = [
    ['instant_verified_emergency', 'Notifikasi keluarga setelah verifikasi'],
    ['verified_incident_summary', 'Riwayat insiden terverifikasi'],
    ['monthly_gait_report', 'Rekap gait bulanan'],
    ['weekly_gait_report', 'Rekap gait mingguan'],
    ['daily_gait_report', 'Laporan gait harian'],
    ['family_live_cctv', 'Live CCTV keluarga'],
];

export default function FamilyBenefitAvailability({ catalog }) {
    return <section className="ui-family-section" aria-labelledby="family-benefits-heading">
        <h2 id="family-benefits-heading">Ketentuan kelas dan ketersediaan fitur</h2>
        <p className="ui-family-section-description">Monitoring keselamatan dan alert petugas berlaku untuk semua kelas, terlepas dari pembayaran. Informasi ini bukan indikator perangkat online atau hasil kesehatan.</p>
        {catalog ? <>
            <p className="ui-family-section-description">Daftar ini menunjukkan manfaat yang tercantum dalam kelas layanan. Fitur tambahan keluarga berikut belum tersedia di portal ini.</p>
            <dl className="ui-family-benefits">{benefits.map(([key, label]) => <div key={key}>
                <dt>{label}</dt>
                <dd><StatusBadge tone="neutral">{catalog[key] === true ? 'Tercantum dalam kelas' : catalog[key] === false ? 'Tidak tercantum dalam kelas' : 'Ketentuan belum tersedia'}</StatusBadge><span className="ui-family-muted">Belum tersedia di portal</span></dd>
            </div>)}</dl>
        </> : <p className="ui-family-section-description">Ketentuan kelas belum dapat ditampilkan sampai informasi layanan tersedia dan dapat dipastikan.</p>}
        <p className="ui-family-context-note">Riwayat insiden, laporan gait, notifikasi keluarga, dan live CCTV belum tersedia. Tidak adanya data bukan bukti kondisi aman atau pembayaran lunas.</p>
        <p className="ui-family-context-note">Informasi pembayaran dapat dilihat melalui menu <Link href="/keluarga/tagihan" className="ui-family-link">Tagihan</Link> saat layanannya tersedia pada lingkungan ini.</p>
    </section>;
}

