import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import FamilyPageHeader from '../../../components/Family/FamilyPageHeader';
import FamilyResidentDetails from '../../../components/Family/FamilyResidentDetails';
import FamilyServiceSummary from '../../../components/Family/FamilyServiceSummary';
import FamilyBenefitAvailability from '../../../components/Family/FamilyBenefitAvailability';
import FamilyRefresh from '../../../components/Family/FamilyRefresh';

export default function Show({ resident, as_of }) {
    const currentService = ['staying', 'enrollment_only'].includes(resident.service?.state) && Boolean(resident.service.enrollment);
    return <AppLayout>
        <Head title="Detail lansia keluarga · Silansia" />
        <div className="ui-family-page">
            <FamilyPageHeader title={resident.name || 'Detail lansia keluarga'} description="Informasi lansia yang terhubung dengan akun Anda. Jika biodata atau hubungan keluarga perlu diperbarui, hubungi Admin panti.">
                <Link href="/keluarga/residents" className="ui-family-link">← Lansia keluarga Anda</Link>
            </FamilyPageHeader>
            <FamilyRefresh asOf={as_of} />
            <FamilyResidentDetails resident={resident} />
            <FamilyServiceSummary service={resident.service} />
            <FamilyBenefitAvailability catalog={currentService ? resident.service.catalog_benefits : null} />
        </div>
    </AppLayout>;
}
