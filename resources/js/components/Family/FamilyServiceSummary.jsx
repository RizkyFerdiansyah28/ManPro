import StatusBadge from '../ui/StatusBadge';
import formatPrice from '../../utils/formatPrice';
import formatMonitoringTime from '../../utils/formatMonitoringTime';

const labels = {
    none: 'Belum ada layanan yang berlaku',
    enrollment_only: 'Layanan tercatat · belum ada masa tinggal aktif',
    staying: 'Layanan dan masa tinggal tercatat',
    needs_review: 'Informasi layanan perlu diperiksa',
};

export function FamilyServiceStatus({ service }) {
    return <StatusBadge tone={service?.state === 'needs_review' ? 'warning' : 'neutral'}>{Object.hasOwn(labels, service?.state) ? labels[service.state] : 'Informasi layanan belum tersedia'}</StatusBadge>;
}

export default function FamilyServiceSummary({ service }) {
    const state = service?.state;
    const currentService = ['staying', 'enrollment_only'].includes(state);
    const enrollment = currentService ? service.enrollment : null;
    const stay = state === 'staying' && enrollment ? service.stay : null;
    return <section aria-labelledby="family-current-service" className="ui-family-section">
        <div className="ui-family-section__header"><h2 id="family-current-service">Layanan dan kamar saat ini</h2><FamilyServiceStatus service={service} /></div>
        {state === 'needs_review' && <p className="ui-family-notice ui-family-notice--warning">Informasi layanan belum dapat dipastikan. Hubungi Admin panti untuk pemeriksaan.</p>}
        {state === 'none' && <p className="ui-family-section-description">Belum ada kamar atau layanan yang berlaku saat data dimuat. Hubungi Admin bila informasi ini perlu diperbarui.</p>}
        {!Object.hasOwn(labels, state) && <p className="ui-family-section-description">Informasi layanan belum tersedia. Hubungi Admin panti bila Anda memerlukan penjelasan.</p>}
        {currentService && <div className="ui-family-service-grid">
            <div className="ui-family-service-group">
                <h3>Layanan yang tercatat</h3>
                {enrollment ? <dl className="ui-family-facts">
                    <div><dt>Kelas layanan</dt><dd>{enrollment.service_class?.name || 'Belum tersedia'}</dd><dd className="ui-family-muted">{enrollment.service_class?.room_type === 'private' ? 'Kamar privat' : enrollment.service_class?.room_type === 'shared' ? 'Kamar bersama' : 'Jenis kamar belum tersedia'}</dd></div>
                    <div><dt>Layanan mulai</dt><dd><time dateTime={enrollment.started_at ?? undefined}>{formatMonitoringTime(enrollment.started_at)}</time></dd></div>
                    <div className="ui-family-facts__full"><dt>Tarif layanan per bulan</dt><dd className="ui-family-price">{enrollment.monthly_price === null || enrollment.monthly_price === undefined ? 'Tarif belum tercatat' : formatPrice(enrollment.monthly_price)}</dd><dd className="ui-family-muted">Tarif yang tersimpan saat layanan dimulai. Ini informasi layanan, bukan tagihan atau status pembayaran.</dd></div>
                </dl> : <p className="ui-family-section-description">Informasi layanan belum tersedia. Hubungi Admin panti untuk pemeriksaan.</p>}
            </div>
            <div className="ui-family-service-group">
                <h3>Kamar yang tercatat</h3>
                {stay ? <dl className="ui-family-facts">
                    <div className="ui-family-facts__full"><dt>Kode dan nama kamar</dt><dd>{stay.room_code || 'Kode belum tersedia'} · {stay.room_name || 'Nama kamar belum tersedia'}</dd></div>
                    <div className="ui-family-facts__full"><dt>Masa tinggal mulai</dt><dd><time dateTime={stay.started_at ?? undefined}>{formatMonitoringTime(stay.started_at)}</time></dd></div>
                </dl> : <p className="ui-family-section-description">{state === 'enrollment_only' ? 'Belum ada masa tinggal aktif.' : 'Informasi kamar belum tersedia. Hubungi Admin panti untuk pemeriksaan.'}</p>}
            </div>
        </div>}
        <p className="ui-family-context-note">Nama kamar dan kelas mengikuti informasi saat ini. Penempatan tercatat tidak menunjukkan lokasi fisik lansia secara langsung. Histori masa tinggal dan layanan belum tersedia di portal ini.</p>
    </section>;
}

