import { Link } from '@inertiajs/react';
import StatusBadge from '../ui/StatusBadge';
import { FamilyServiceStatus } from './FamilyServiceSummary';

export default function FamilyResidentCard({ resident, headingLevel = 'h2', showAge = false }) {
    const Heading = headingLevel;
    return <li className="ui-family-resident">
        <div className="ui-family-resident__content">
            <Heading className="ui-family-resident__name">{resident.name || 'Nama belum tersedia'}</Heading>
            <div className="ui-family-resident__status">
                <StatusBadge tone="neutral">{resident.is_active === true ? 'Lansia aktif administratif' : resident.is_active === false ? 'Lansia nonaktif administratif' : 'Status administratif belum tersedia'}</StatusBadge>
                {showAge && <span className="ui-family-muted">{resident.age === null || resident.age === undefined ? 'Usia belum tersedia' : `${resident.age} tahun`}</span>}
            </div>
            <p className="ui-family-resident__service"><FamilyServiceStatus service={resident.service} /></p>
        </div>
        {resident.id && <Link href={'/keluarga/residents/' + resident.id} aria-label={'Lihat ' + (resident.name || 'detail lansia')} className="ui-family-link">Lihat detail</Link>}
    </li>;
}
