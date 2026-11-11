import formatPrice from '../../utils/formatPrice';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

const labels = { none: 'Belum ada layanan yang berlaku', enrollment_only: 'Enrollment tercatat · belum menempati kamar', staying: 'Masa tinggal dan enrollment tercatat', needs_review: 'Informasi layanan perlu pemeriksaan' };
export function FamilyServiceStatus({ service }) {
    return <span className={service.state === 'needs_review' ? 'text-amber-900' : 'text-slate-600'}>{labels[service.state]}</span>;
}
export default function FamilyServiceSummary({ service }) {
    return <section aria-label="Layanan saat ini" className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <h2 className="text-lg font-semibold">Layanan saat ini</h2><p className="mt-3 text-sm"><FamilyServiceStatus service={service} /></p>
        {service.state === 'needs_review' && <p className="mt-4 rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-950">Informasi layanan belum dapat dipastikan. Hubungi Admin panti untuk pemeriksaan.</p>}
        {service.state === 'none' && <p className="mt-4 text-sm leading-7 text-slate-500">Belum ada kamar atau enrollment yang berlaku saat data dimuat. Hubungi Admin bila informasi ini perlu diperbarui.</p>}
        {service.enrollment && <dl className="mt-6 grid gap-6 text-sm sm:grid-cols-2">
            <div><dt className="text-slate-500">Kelas layanan</dt><dd className="mt-2 break-words font-semibold">{service.enrollment.service_class.name}</dd><dd className="mt-1 text-xs text-slate-500">{service.enrollment.service_class.room_type === 'private' ? 'Kamar privat' : 'Kamar bersama'}</dd></div>
            <div><dt className="text-slate-500">Enrollment mulai</dt><dd className="mt-2">{formatMonitoringTime(service.enrollment.started_at)}</dd></div>
            <div><dt className="text-slate-500">Tarif enrollment per bulan</dt><dd className="mt-2 font-semibold">{service.enrollment.monthly_price === null ? 'Tarif belum tercatat' : formatPrice(service.enrollment.monthly_price)}</dd><dd className="mt-2 text-xs leading-6 text-slate-500">Tarif yang tersimpan pada enrollment berjalan. Ini informasi layanan, bukan tagihan atau status pembayaran.</dd></div>
            <div><dt className="text-slate-500">Kamar tercatat</dt><dd className="mt-2 break-words font-semibold">{service.stay ? service.stay.room_code + ' · ' + service.stay.room_name : 'Belum ada masa tinggal aktif'}</dd>{service.stay && <dd className="mt-2 text-xs text-slate-500">Mulai {formatMonitoringTime(service.stay.started_at)}</dd>}</div>
        </dl>}
        <p className="mt-5 text-xs leading-6 text-slate-500">Nama kamar dan kelas mengikuti informasi saat ini. Status lansia terpisah dari masa tinggal dan layanan.</p>
    </section>;
}

