import { Head, Link } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import CloseOperationAction from '../../../components/CloseOperationAction';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import OperationPagination from '../../../components/OperationPagination';
import OperationSummary from '../../../components/OperationSummary';
import formatOperationalTime from '../../../utils/formatOperationalTime';
import formatPrice from '../../../utils/formatPrice';

export default function Index({ resident, operations, stays, enrollments }) {
    return <ManagementLayout>
        <Head title="Masa tinggal dan layanan · Silansia" />
        <ManagementPageHeader><Link href={`/admin/residents/${resident.id}`} className="ui-management-link">← Detail lansia</Link>
        <h1 className="ui-management-title">Masa tinggal dan layanan</h1><p className="mt-3 text-ui-neutral">{resident.name}</p></ManagementPageHeader>
        <OperationSummary resident={resident} operations={operations} />
        <section className="ui-management-panel">
            <p>Enrollment boleh aktif tanpa kamar. Masa tinggal memerlukan enrollment aktif dengan kelas yang sesuai. Penutupan dilakukan terpisah; tidak ada pemindahan atau penutupan otomatis.</p>
            <div className="ui-management-actions">
                {operations.stay && <CloseOperationAction residentId={resident.id} record={operations.stay} kind="stays" />}
                {operations.enrollment && <CloseOperationAction residentId={resident.id} record={operations.enrollment} kind="enrollments" disabled={Boolean(operations.stay)} />}
            </div>
        </section>
        <div className="ui-management-two-column">
            <section className="ui-management-panel"><h2 className="ui-management-section-title">Riwayat masa tinggal</h2>
                {stays.data.length ? <ul className="mt-5 divide-y divide-slate-100">{stays.data.map((stay) => <li key={stay.id} className="py-5"><p className="font-semibold">{stay.room.code} · {stay.room.name}</p><p className="mt-2 text-sm text-ui-neutral">{stay.room.service_class.name}</p><p className="mt-2 text-xs leading-6 text-ui-text-secondary">Mulai: {formatOperationalTime(stay.started_at)} WIB<br />Selesai: {formatOperationalTime(stay.ended_at)}{stay.ended_at ? ' WIB' : ''}</p></li>)}</ul> : <p className="mt-6 text-sm text-ui-text-secondary">Belum ada riwayat masa tinggal.</p>}
                <OperationPagination page={stays} label="riwayat masa tinggal" />
            </section>
            <section className="ui-management-panel"><h2 className="ui-management-section-title">Riwayat enrollment</h2>
                {enrollments.data.length ? <ul className="mt-5 divide-y divide-slate-100">{enrollments.data.map((item) => <li key={item.id} className="py-5"><p className="font-semibold">{item.service_class.name}</p><p className="mt-2 text-sm text-ui-neutral">Snapshot: {formatPrice(item.monthly_price)} / bulan</p><p className="mt-2 text-xs leading-6 text-ui-text-secondary">Mulai: {formatOperationalTime(item.started_at)} WIB<br />Selesai: {formatOperationalTime(item.ended_at)}{item.ended_at ? ' WIB' : ''}</p></li>)}</ul> : <p className="mt-6 text-sm text-ui-text-secondary">Belum ada riwayat enrollment.</p>}
                <OperationPagination page={enrollments} label="riwayat enrollment" />
            </section>
        </div>
    </ManagementLayout>;
}
