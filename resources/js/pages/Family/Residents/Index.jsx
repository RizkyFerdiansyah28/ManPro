import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import FormField from '../../../components/FormField';
import OperationPagination from '../../../components/OperationPagination';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import FamilyPageHeader from '../../../components/Family/FamilyPageHeader';
import FamilyResidentCard from '../../../components/Family/FamilyResidentCard';
import FamilyRefresh from '../../../components/Family/FamilyRefresh';
import focusFormErrors from '../../../utils/focusFormErrors';

export default function Index({ residents, filters, as_of, errors }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [perPage, setPerPage] = useState(filters.per_page);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const formRef = useRef(null);
    const errorRef = useRef(null);
    const errorsKey = JSON.stringify(errors || {});
    const hasErrors = Boolean(error) || Object.values(errors || {}).length > 0;
    const filtered = Boolean(filters.search || filters.status);
    useEffect(() => {
        if (loading || !hasErrors) return;
        if (error || !focusFormErrors(formRef.current, errorRef.current, errors || {})) errorRef.current?.focus();
    }, [error, errorsKey, loading]);

    function submit(event) {
        event.preventDefault();
        setError('');
        const fail = () => { setError('Daftar belum dapat dimuat. Coba kembali.'); return false; };
        router.get('/keluarga/residents', { search, status, per_page: perPage }, { preserveState: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false), onNetworkError: fail, onHttpException: fail });
    }
    return <AppLayout><Head title="Lansia keluarga · Silansia" />
        <div className="ui-family-page">
            <FamilyPageHeader title="Lansia keluarga Anda" description="Biodata dan informasi layanan lansia yang saat ini terhubung dengan akun Anda. Informasi tersedia untuk dibaca." />
            <FamilyRefresh asOf={as_of} />
            <section className="ui-family-section ui-family-section--list" aria-label="Daftar lansia keluarga">
                {hasErrors && <div ref={errorRef} tabIndex={-1} role="alert" className="ui-family-notice ui-family-notice--error">
                    <p className="ui-family-notice__title">Daftar belum dapat diperbarui</p>
                    {error && <p>{error}</p>}
                    {Object.values(errors || {}).length > 0 && <ul>{Object.values(errors || {}).map((message, index) => <li key={index}>{message}</li>)}</ul>}
                </div>}
                <form ref={formRef} onSubmit={submit} aria-label="Filter lansia keluarga" aria-busy={loading || undefined} className="ui-family-filters">
                    <fieldset disabled={loading}>
                        <legend className="sr-only">Filter lansia keluarga</legend>
                        <FormField id="family-resident-search" name="search" label="Cari nama lansia" type="search" autoComplete="off" maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} error={errors?.search} />
                        <div className="ui-field-group">
                            <label htmlFor="family-resident-status" className="ui-field-label">Status administratif</label>
                            <select id="family-resident-status" name="status" value={status} onChange={(e) => setStatus(e.target.value)} aria-invalid={Boolean(errors?.status)} aria-describedby={errors?.status ? 'family-resident-status-error' : undefined} className="ui-field-control">
                                <option value="">Semua</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option>
                            </select>
                            {errors?.status && <p id="family-resident-status-error" className="ui-field-error">{errors.status}</p>}
                        </div>
                        <div className="ui-field-group">
                            <label htmlFor="family-resident-per-page" className="ui-field-label">Per halaman</label>
                            <select id="family-resident-per-page" name="per_page" value={perPage} onChange={(e) => setPerPage(e.target.value)} aria-invalid={Boolean(errors?.per_page)} aria-describedby={errors?.per_page ? 'family-resident-per-page-error' : undefined} className="ui-field-control">
                                {[15, 30, 50].map((n) => <option key={n}>{n}</option>)}
                            </select>
                            {errors?.per_page && <p id="family-resident-per-page-error" className="ui-field-error">{errors.per_page}</p>}
                        </div>
                        <Button type="submit" processing={loading} disabled={loading}>{loading ? 'Memuat…' : 'Terapkan'}</Button>
                    </fieldset>
                </form>
                {residents.data.length ? <ul className="ui-family-residents">{residents.data.map((resident) => <FamilyResidentCard key={resident.id} resident={resident} showAge />)}</ul>
                    : <EmptyState title={filtered || residents.total > 0 ? 'Belum ada lansia yang sesuai di halaman ini' : 'Belum ada lansia yang terhubung'}
                        description={filtered || residents.total > 0 ? 'Ubah filter atau gunakan navigasi halaman untuk melihat data lain yang terhubung dengan akun Anda.' : 'Hubungi Admin panti bila hubungan keluarga perlu diperbarui. Data hanya ditampilkan untuk lansia yang terhubung dengan akun Anda.'} />}
                <div className="ui-family-pagination"><OperationPagination page={residents} label="lansia keluarga" /></div>
            </section>
            <p className="ui-family-context-note">Status administratif lansia terpisah dari kondisi kesehatan, masa tinggal, dan layanan. Data pada halaman ini tidak menunjukkan pemantauan secara langsung.</p>
        </div>
    </AppLayout>;
}
