import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import EmptyState from '../../components/ui/EmptyState';
import FamilyPageHeader from '../../components/Family/FamilyPageHeader';
import FamilyResidentCard from '../../components/Family/FamilyResidentCard';
import FamilyRefresh from '../../components/Family/FamilyRefresh';

export default function Dashboard({ summary, residents, as_of }) {
    const { auth } = usePage().props;
    const counts = [['residents', 'Lansia terkait'], ['active_residents', 'Lansia aktif administratif'], ['staying', 'Masa tinggal tercatat'], ['enrolled', 'Layanan tercatat'], ['needs_review', 'Informasi perlu diperiksa']];
    return <AppLayout><Head title="Beranda Keluarga · Silansia" />
        <div className="ui-family-page">
            <FamilyPageHeader title={`Selamat datang, ${auth.user.name}.`} description="Lihat informasi lansia yang saat ini terhubung dengan akun Anda. Data dibaca saat halaman dimuat; halaman ini tidak menunjukkan kondisi kesehatan secara langsung." />
            <FamilyRefresh asOf={as_of} />
            <section className="ui-family-section ui-family-section--list" aria-labelledby="family-residents-heading">
                <div className="ui-family-section__header">
                    <div><h2 id="family-residents-heading">Lansia keluarga Anda</h2><p>Buka detail untuk melihat biodata, layanan, dan kamar yang tercatat.</p></div>
                    <Link href="/keluarga/residents" className="ui-family-link">Lihat daftar lengkap</Link>
                </div>
                {residents.length ? <ul className="ui-family-residents">{residents.map((resident) => <FamilyResidentCard key={resident.id} resident={resident} headingLevel="h3" />)}</ul>
                    : <EmptyState title="Belum ada lansia yang terhubung" description="Hubungi Admin panti bila hubungan keluarga perlu diperbarui. Data akan ditampilkan sesuai hubungan dengan akun Anda." />}
                {summary.residents > residents.length && <p className="ui-family-list-note">Menampilkan {residents.length} dari {summary.residents} lansia. Buka daftar lengkap untuk melihat lainnya.</p>}
            </section>
            <section className="ui-family-section" aria-labelledby="family-summary-heading">
                <h2 id="family-summary-heading">Ringkasan administratif</h2>
                <p className="ui-family-section-description">Jumlah berikut berasal dari data yang dimuat, terpisah dari informasi kesehatan.</p>
                <dl className="ui-family-counts">{counts.map(([key, title]) => <div key={key}><dt>{title}</dt><dd>{summary[key]}</dd></div>)}</dl>
                {summary.needs_review > 0 && <p className="ui-family-notice ui-family-notice--warning">Sebagian informasi layanan perlu diperiksa Admin. Jumlah masa tinggal dan layanan hanya mencakup informasi yang dapat dipastikan.</p>}
            </section>
            <p className="ui-family-context-note">Histori insiden, laporan gait, notifikasi keluarga, dan live CCTV belum tersedia di portal ini. Tidak adanya data bukan bukti kondisi aman.</p>
            <p className="ui-family-context-note">Buka menu <Link href="/keluarga/tagihan" className="ui-family-link">Tagihan</Link> untuk melihat tagihan dan pembayaran saat layanannya tersedia pada lingkungan ini.</p>
        </div>
    </AppLayout>;
}

