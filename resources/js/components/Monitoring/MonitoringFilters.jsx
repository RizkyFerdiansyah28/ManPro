import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import Button from '../ui/Button';
import FormField from '../FormField';
import { initialMonitoringFilters, updateMonitoringRange, updateMonitoringEventType } from '../../utils/monitoringFilters';

export default function MonitoringFilters({ filters, url, mode = 'records', classes = [], headingLevel = 2 }) {
    const ranged = mode !== 'open';
    const detailed = mode === 'records';
    const form = useForm(initialMonitoringFilters(filters, mode));
    const pageErrors = usePage().props.errors ?? {};
    const [networkError, setNetworkError] = useState('');
    const formRef = useRef(null);
    const errorRef = useRef(null);
    const submitted = useRef(false);
    const headingId = useId();
    const Heading = headingLevel === 3 ? 'h3' : 'h2';
    const signature = JSON.stringify(filters);
    useEffect(() => { form.setData(initialMonitoringFilters(filters, mode)); }, [signature]);
    const fieldErrors = { ...pageErrors, ...form.errors };
    const errors = [...new Set(Object.values(fieldErrors))];
    const errorSignature = JSON.stringify(fieldErrors);
    useEffect(() => {
        if (!submitted.current || form.processing || !errors.length) return;
        const field = Object.keys(fieldErrors).map((key) => formRef.current?.elements.namedItem(key)).find((element) => element?.focus);
        (field ?? errorRef.current)?.focus();
        submitted.current = false;
    }, [errorSignature, form.processing]);
    const fail = () => { setNetworkError('Filter belum dapat diterapkan. Data masih berasal dari pemuatan sebelumnya. Coba kembali.'); return false; };
    function submit(event) {
        event.preventDefault();
        submitted.current = true;
        setNetworkError('');
        form.get(url, { preserveState: true, preserveScroll: true, replace: true, onNetworkError: fail, onHttpException: fail, onSuccess: () => { submitted.current = false; } });
    }
    function select(id, label, choices, change = (value) => form.setData(id, value)) {
        return (
            <div className="ui-field-group">
                <label className="ui-field-label" htmlFor={id}>{label}</label>
                <select id={id} name={id} value={form.data[id]} onChange={(event) => change(event.target.value)} aria-invalid={Boolean(fieldErrors[id])} aria-describedby={fieldErrors[id] ? id + '-error' : undefined} className={'ui-field-control ' + (fieldErrors[id] ? 'ui-field-control--invalid' : '')}>
                    {choices.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                </select>
                {fieldErrors[id] && <p id={id + '-error'} className="ui-field-error">{fieldErrors[id]}</p>}
            </div>
        );
    }
    return (
        <section aria-labelledby={headingId} className="ui-monitoring-filters">
            <div className="ui-monitoring-filters__heading">
                <Heading id={headingId}>{mode === 'dashboard' ? 'Periode kejadian' : mode === 'open' ? 'Filter antrean' : 'Filter rekaman'}</Heading>
                <p className="ui-monitoring-copy">{ranged ? 'Berdasarkan waktu kejadian. Tanggal khusus maksimal 90 hari.' : 'Seluruh umur insiden; tidak mengikuti filter waktu dashboard.'}</p>
            </div>
            <form ref={formRef} onSubmit={submit} aria-busy={form.processing} autoComplete="off">
                {ranged && <fieldset>
                    <legend className="ui-monitoring-filter-legend">Waktu kejadian</legend>
                    <div className="ui-monitoring-filter-grid">
                        {select('range', 'Rentang waktu', [['24h', '24 jam terakhir'], ['7d', '7 hari terakhir'], ['30d', '30 hari terakhir'], ['custom', 'Tanggal khusus · WIB']], (value) => form.setData(updateMonitoringRange(form.data, value)))}
                        {form.data.range === 'custom' && ['date_from', 'date_to'].map((key, index) => <FormField key={key} id={key} label={index ? 'Sampai tanggal (inklusif)' : 'Dari tanggal'} type="date" required value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} error={fieldErrors[key]} />)}
                    </div>
                </fieldset>}
                {mode !== 'dashboard' && <fieldset>
                    <legend className="ui-monitoring-filter-legend">Kamera dan lokasi</legend>
                    <div className="ui-monitoring-filter-grid">
                        <FormField id="search" label="Kamera atau kamar historis" type="search" maxLength={100} value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} error={fieldErrors.search} />
                        {select('class_code', 'Kelas pada periode kejadian', [['', 'Semua kelas'], ...classes.map((item) => [item.code, item.name])])}
                        {select('per_page', 'Data per halaman', [[15, '15 data'], [30, '30 data'], [50, '50 data']])}
                        {mode === 'open' && select('status', 'Insiden terbuka · seluruh waktu', [['', 'Pending dan acknowledged'], ['pending', 'Menunggu tindak lanjut'], ['acknowledged', 'Diakui · belum selesai']])}
                    </div>
                </fieldset>}
                {detailed && <fieldset>
                    <legend className="ui-monitoring-filter-legend">Jenis dan rincian kejadian</legend>
                    <div className="ui-monitoring-filter-grid">
                        {select('event_type', 'Jenis event', [['', 'Semua jenis'], ['fall', 'Fall'], ['gait', 'Gait']], (value) => form.setData(updateMonitoringEventType(form.data, value)))}
                        {form.data.event_type === 'fall' && <>
                            {select('fall_status', 'Status insiden', [['', 'Semua status'], ['pending', 'Menunggu tindak lanjut'], ['acknowledged', 'Diakui · belum selesai'], ['resolved', 'Selesai'], ['false_alarm', 'Alarm palsu']])}
                            {select('verification', 'Verifikasi tersimpan', [['', 'Semua'], ['verified', 'Tercatat terverifikasi'], ['unverified', 'Belum/tidak terverifikasi']])}
                        </>}
                        {form.data.event_type === 'gait' && select('gait_category', 'Kategori gait', [['', 'Semua kategori'], ['walking_normal', 'Berjalan normal'], ['walking_abnormal', 'Berjalan tidak normal'], ['no_walking_detected', 'Tidak terdeteksi berjalan']])}
                        {select('identity', 'Atribusi identitas', [['', 'Semua'], ['known', 'Identitas diketahui'], ['unknown', 'Identitas belum diketahui']])}
                        {select('location', 'Konteks historis', [['', 'Semua'], ['tracked', 'Periode tercatat'], ['legacy', 'Legacy · tanpa periode'], ['unavailable', 'Konteks tidak konsisten']])}
                    </div>
                </fieldset>}
                {['camera_id', 'room_id'].filter((key) => form.data[key]).map((key) => <div key={key} className="ui-monitoring-applied-filter">
                    <p>{key === 'camera_id' ? 'Filter kamera' : 'Filter kamar historis'}: <span>{form.data[key]}</span></p>
                    <Button variant="secondary" onClick={() => form.setData(key, '')} aria-label={key === 'camera_id' ? 'Hapus filter kamera lalu terapkan' : 'Hapus filter kamar historis lalu terapkan'}>Hapus lalu terapkan</Button>
                </div>)}
                <div className="ui-monitoring-filter-actions">
                    <Button type="submit" processing={form.processing}>{form.processing ? 'Memuat…' : 'Terapkan filter'}</Button>
                    <Button variant="secondary" disabled={form.processing} onClick={() => router.get(url)}>Reset filter</Button>
                </div>
                {errors.length > 0 && <div ref={errorRef} role="alert" tabIndex={-1} className="ui-monitoring-error"><p>Periksa pilihan filter berikut.</p>{errors.map((error) => <p key={error}>{error}</p>)}</div>}
                {networkError && <p role="alert" className="ui-monitoring-error">{networkError}</p>}
            </form>
        </section>
    );
}
