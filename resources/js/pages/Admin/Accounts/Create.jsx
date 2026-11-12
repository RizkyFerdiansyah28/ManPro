import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FormField from '../../../components/FormField';
import ManagementForm, { ManagementControl } from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function Create() {
    const [type, setType] = useState('perawat');
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    function submit(event) {
        event.preventDefault();
        form.post(type === 'perawat' ? '/admin/accounts/perawat' : '/admin/accounts/keluarga', { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <ManagementLayout><Head title="Buat akun · Silansia" /><ManagementPageHeader><Link href="/admin/accounts" className="ui-management-link">← Kembali ke daftar akun</Link>
            <h1 className="ui-management-title">Buat akun baru</h1><p className="mt-3 text-sm text-ui-text-secondary">Berikan akses kepada perawat atau keluarga penghuni.</p></ManagementPageHeader>
            <ManagementForm label="Buat akun baru" kind="editor" form={form} onSubmit={submit}>
                <fieldset className="ui-management-selector"><legend className="ui-management-legend">Jenis akun</legend><div className="ui-management-two-column">{[['perawat', 'Perawat'], ['keluarga', 'Keluarga']].map(([value, label]) => <label key={value} className="ui-management-choice"><ManagementControl field="account-type" type="radio" name="account-type" checked={type === value} onChange={() => setType(value)} />{label}</label>)}</div></fieldset>
                <FormField id="name" label="Nama lengkap" required autoComplete="name" maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
                <FormField id="email" label="Email" type="email" required autoComplete="off" maxLength={254} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
                <FormField id="password" label="Kata sandi awal" type="password" required autoComplete="new-password" maxLength={72} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} hint="Minimal 8 karakter, dengan huruf besar, huruf kecil, dan angka. Maksimal 72 byte." />
                <FormField id="password_confirmation" label="Konfirmasi kata sandi" type="password" required autoComplete="new-password" maxLength={72} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={form.errors.password_confirmation} />
                <p className="ui-management-note ui-management-note--warning">Akun dibuat nonaktif. Setelah kredensial disampaikan secara aman, aktifkan akun melalui daftar akun.</p>
                <div className="ui-management-actions"><Button processing={form.processing} variant="primary" type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Buat akun'}</Button><Link href="/admin/accounts" className="ui-management-link">Batal</Link></div>
            </ManagementForm>
        </ManagementLayout>
    );
}
