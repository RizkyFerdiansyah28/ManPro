import StatusBadge from '../ui/StatusBadge';

export default function FamilyResidentDetails({ resident }) {
    const birthDate = resident.birth_date ? new Date(`${resident.birth_date}T00:00:00`) : null;
    const validDate = birthDate && !Number.isNaN(birthDate.getTime());
    const formattedDate = validDate
        ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(birthDate)
        : 'Belum tersedia';
    return <section className="ui-family-section" aria-labelledby="family-resident-details">
        <h2 id="family-resident-details">Biodata lansia</h2>
        <dl className="ui-family-facts">
            <div><dt>Nama lengkap</dt><dd>{resident.name || 'Belum tersedia'}</dd></div>
            <div><dt>Status administratif</dt><dd><StatusBadge tone="neutral">{resident.is_active === true ? 'Aktif' : resident.is_active === false ? 'Nonaktif' : 'Belum tersedia'}</StatusBadge></dd></div>
            <div><dt>Tanggal lahir</dt><dd>{validDate ? <time dateTime={resident.birth_date}>{formattedDate}</time> : formattedDate}</dd></div>
            <div><dt>Usia saat data dimuat</dt><dd>{resident.age === null || resident.age === undefined ? 'Belum tersedia' : `${resident.age} tahun`}</dd></div>
        </dl>
        <p className="ui-family-context-note">Status administratif terpisah dari masa tinggal, layanan, dan kondisi kesehatan. Hubungi Admin panti bila biodata perlu diperbarui.</p>
    </section>;
}
