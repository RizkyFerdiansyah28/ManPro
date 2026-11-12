import { Link } from '@inertiajs/react';
import formatOperationalTime from '../utils/formatOperationalTime';
import formatPrice from '../utils/formatPrice';

export default function OperationSummary({ resident, operations }) {
    const { stay, enrollment } = operations;
    return (
        <section className="ui-management-two-column" aria-label="Penempatan dan layanan saat ini">
            <div className="ui-management-panel">
                <h2 className="ui-management-section-title">Kamar saat ini</h2>
                {stay ? <><p className="mt-4 text-lg font-semibold">{stay.room.code} · {stay.room.name}</p><p className="mt-2 text-sm text-ui-neutral">{stay.room.service_class.name}</p><p className="mt-2 text-xs text-ui-text-secondary">Mulai {formatOperationalTime(stay.started_at)} WIB</p></> : <p className="mt-4 text-sm text-ui-text-secondary">Belum ada masa tinggal aktif.</p>}
                {!stay && resident.is_active && enrollment && <Link href={`/admin/residents/${resident.id}/stays/create`} className="ui-management-link">Mulai masa tinggal</Link>}
            </div>
            <div className="ui-management-panel">
                <h2 className="ui-management-section-title">Enrollment saat ini</h2>
                {enrollment ? <><p className="mt-4 text-lg font-semibold">{enrollment.service_class.name}</p><p className="mt-2 text-sm text-ui-neutral">Snapshot tarif: {formatPrice(enrollment.monthly_price)} / bulan</p><p className="mt-2 text-xs text-ui-text-secondary">Mulai {formatOperationalTime(enrollment.started_at)} WIB</p></> : <p className="mt-4 text-sm text-ui-text-secondary">Belum ada enrollment aktif. Buat enrollment sebelum penempatan.</p>}
                {!enrollment && resident.is_active && <Link href={`/admin/residents/${resident.id}/enrollments/create`} className="ui-management-link">Buat enrollment</Link>}
            </div>
        </section>
    );
}
