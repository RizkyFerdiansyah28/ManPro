import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Brand from '../components/Brand';
import StatusBadge from '../components/ui/StatusBadge';

const messages = {
    403: ['Akses dibatasi', 'Akun Anda tidak memiliki izin untuk membuka halaman ini.'],
    404: ['Halaman tidak ditemukan', 'Alamat yang Anda buka tidak tersedia.'],
    405: ['Metode tidak tersedia', 'Gunakan tindakan yang tersedia pada halaman ini.'],
    409: ['Data telah berubah', 'Muat ulang detail untuk melihat keadaan terkini sebelum melanjutkan.'],
    419: ['Sesi perlu diperbarui', 'Buka kembali beranda untuk memuat halaman dengan sesi yang berlaku.'],
    429: ['Terlalu banyak permintaan', 'Tunggu sejenak sebelum mencoba kembali.'],
    500: ['Layanan sedang bermasalah', 'Halaman belum dapat ditampilkan. Silakan coba kembali nanti.'],
    503: ['Layanan belum tersedia', 'Silakan coba kembali beberapa saat lagi.'],
};
const billingPaths = ['/admin/billing/invoices', '/admin/pembayaran', '/keluarga/tagihan'];

export default function Error({ status }) {
    const known = Object.hasOwn(messages, status);
    const path = usePage().url.split(/[?#]/, 1)[0];
    const billingUnavailable = Number(status) === 503 && billingPaths.some(base => path === base || path.startsWith(base + '/'));
    const [title, description] = billingUnavailable
        ? ['Layanan tagihan dan pembayaran belum tersedia', 'Data tagihan belum dapat dimuat saat ini. Hubungi pengelola bila Anda memerlukan bantuan.']
        : known ? messages[status] : messages[500];
    const heading = useRef(null);
    useEffect(() => { heading.current?.focus(); }, [status]);
    return <main className="ui-error-page">
        <Head title={`${title} · Silansia`} />
        <div className="ui-error-content">
            <div className="ui-error-brand"><Brand /></div>
            <section className="ui-error-panel" aria-labelledby="error-title">
                {known && <p className="ui-error-code">Kode respons <StatusBadge tone="neutral">{Number(status)}</StatusBadge></p>}
                <h1 id="error-title" ref={heading} tabIndex={-1}>{title}</h1>
                <p className="ui-error-description">{description}</p>
                {known && [409, 419, 429, 500, 503].includes(Number(status)) && <p className="ui-error-context">Jika halaman ini muncul setelah menyimpan perubahan, periksa keadaan data sebelum mengulangi tindakan.</p>}
                <Link href="/" className="ui-button ui-button--primary">Kembali ke beranda</Link>
            </section>
        </div>
    </main>;
}
