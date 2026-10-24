import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import OperationSummary from '../../../components/OperationSummary';
import CloseOperationAction from '../../../components/CloseOperationAction';
import OperationPagination from '../../../components/OperationPagination';
import formatOperationalTime from '../../../utils/formatOperationalTime';
import formatPrice from '../../../utils/formatPrice';

export default function Index({ resident, operations, stays, enrollments }) {
    return <AppLayout>
        <Head title="Masa tinggal dan layanan · Silansia" />
        <Link href={`/admin/residents/${resident.id}`} className="text-sm font-semibold text-emerald-800 hover:underline">← Detail lansia</Link>
        <h1 className="mt-5 text-3xl font-bold">Masa tinggal dan layanan</h1><p className="mt-3 text-slate-600">{resident.name}</p>
        <OperationSummary resident={resident} operations={operations} />
        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
            <p>Enrollment boleh aktif tanpa kamar. Masa tinggal memerlukan enrollment aktif dengan kelas yang sesuai. Penutupan dilakukan terpisah; tidak ada pemindahan atau penutupan otomatis.</p>
            <div className="flex flex-wrap gap-8">
                {operations.stay && <CloseOperationAction residentId={resident.id} record={operations.stay} kind="stays" />}
                {operations.enrollment && <CloseOperationAction residentId={resident.id} record={operations.enrollment} kind="enrollments" disabled={Boolean(operations.stay)} />}
            </div>
        </section>
        <div className="mt-8 grid items-start gap-6 lg:grid-cols-2">
            <section className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-lg font-semibold">Riwayat masa tinggal</h2>
                {stays.data.length ? <ul className="mt-5 divide-y divide-slate-100">{stays.data.map((stay) => <li key={stay.id} className="py-5"><p className="font-semibold">{stay.room.code} · {stay.room.name}</p><p className="mt-2 text-sm text-slate-600">{stay.room.service_class.name}</p><p className="mt-2 text-xs leading-6 text-slate-500">Mulai: {formatOperationalTime(stay.started_at)} WIB<br />Selesai: {formatOperationalTime(stay.ended_at)}{stay.ended_at ? ' WIB' : ''}</p></li>)}</ul> : <p className="mt-6 text-sm text-slate-500">Belum ada riwayat masa tinggal.</p>}
                <OperationPagination page={stays} label="riwayat masa tinggal" />
            </section>
            <section className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-lg font-semibold">Riwayat enrollment</h2>
                {enrollments.data.length ? <ul className="mt-5 divide-y divide-slate-100">{enrollments.data.map((item) => <li key={item.id} className="py-5"><p className="font-semibold">{item.service_class.name}</p><p className="mt-2 text-sm text-slate-600">Snapshot: {formatPrice(item.monthly_price)} / bulan</p><p className="mt-2 text-xs leading-6 text-slate-500">Mulai: {formatOperationalTime(item.started_at)} WIB<br />Selesai: {formatOperationalTime(item.ended_at)}{item.ended_at ? ' WIB' : ''}</p></li>)}</ul> : <p className="mt-6 text-sm text-slate-500">Belum ada riwayat enrollment.</p>}
                <OperationPagination page={enrollments} label="riwayat enrollment" />
            </section>
        </div>
    </AppLayout>;
}
