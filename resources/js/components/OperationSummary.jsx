import { Link } from '@inertiajs/react';
import formatPrice from '../utils/formatPrice';
import formatOperationalTime from '../utils/formatOperationalTime';

export default function OperationSummary({ resident, operations }) {
    const { stay, enrollment } = operations;
    return (
        <section className="mt-8 grid gap-5 md:grid-cols-2" aria-label="Penempatan dan layanan saat ini">
            <div className="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 className="font-semibold">Kamar saat ini</h2>
                {stay ? <><p className="mt-4 text-lg font-semibold">{stay.room.code} · {stay.room.name}</p><p className="mt-2 text-sm text-slate-600">{stay.room.service_class.name}</p><p className="mt-2 text-xs text-slate-500">Mulai {formatOperationalTime(stay.started_at)} WIB</p></> : <p className="mt-4 text-sm text-slate-500">Belum ada masa tinggal aktif.</p>}
                {!stay && resident.is_active && enrollment && <Link href={`/admin/residents/${resident.id}/stays/create`} className="mt-5 inline-block rounded font-semibold text-emerald-800 hover:underline">Mulai masa tinggal</Link>}
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 className="font-semibold">Enrollment saat ini</h2>
                {enrollment ? <><p className="mt-4 text-lg font-semibold">{enrollment.service_class.name}</p><p className="mt-2 text-sm text-slate-600">Snapshot tarif: {formatPrice(enrollment.monthly_price)} / bulan</p><p className="mt-2 text-xs text-slate-500">Mulai {formatOperationalTime(enrollment.started_at)} WIB</p></> : <p className="mt-4 text-sm text-slate-500">Belum ada enrollment aktif. Buat enrollment sebelum penempatan.</p>}
                {!enrollment && resident.is_active && <Link href={`/admin/residents/${resident.id}/enrollments/create`} className="mt-5 inline-block rounded font-semibold text-emerald-800 hover:underline">Buat enrollment</Link>}
            </div>
        </section>
    );
}
