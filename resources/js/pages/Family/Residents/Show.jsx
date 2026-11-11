import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResidentDetails from '../../../components/ResidentDetails';
import FamilyServiceSummary from '../../../components/Family/FamilyServiceSummary';
import FamilyBenefitAvailability from '../../../components/Family/FamilyBenefitAvailability';
import FamilyRefresh from '../../../components/Family/FamilyRefresh';

export default function Show({ resident, as_of }) {
    return (
        <AppLayout>
            <Head title="Detail lansia keluarga · Silansia" />
            <Link href="/keluarga/residents" className="text-sm font-semibold text-emerald-800 hover:underline">← Lansia keluarga Anda</Link>
            <h1 className="mt-5 text-3xl font-bold tracking-tight">Detail lansia keluarga</h1>
            <p className="mt-3 text-sm text-slate-500">Jika biodata atau hubungan keluarga perlu diperbarui, hubungi Admin panti.</p>
            <ResidentDetails resident={resident} />
            <FamilyRefresh asOf={as_of} />
            <FamilyServiceSummary service={resident.service} />
            <FamilyBenefitAvailability catalog={resident.service.catalog_benefits} />
        </AppLayout>
    );
}
