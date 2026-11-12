import { Head, Link, useForm } from '@inertiajs/react';
import ManagementLayout from '../../../components/Management/ManagementLayout';
import FormField from '../../../components/FormField';
import ManagementForm from '../../../components/Management/ManagementForm';
import ManagementPageHeader from '../../../components/Management/ManagementPageHeader';
import Button from '../../../components/ui/Button';

export default function ResetPassword({ account }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    function submit(event) {
        event.preventDefault();
        form.post(`/admin/accounts/${account.id}/password`, { onFinish: () => form.reset() });
    }
    return (
        <ManagementLayout><Head title="Reset kata sandi · Silansia" /><ManagementPageHeader><Link href="/admin/accounts" className="ui-management-link">← Kembali ke daftar akun</Link>
            <h1 className="ui-management-title">Reset kata sandi</h1><p className="mt-3 text-sm text-ui-text-secondary">Perbarui akses akun pengguna secara aman.</p></ManagementPageHeader>
            <ManagementForm label="Reset kata sandi" kind="editor" form={form} onSubmit={submit}>
                <div className="ui-management-note"><p className="font-semibold">{account.name}</p><p className="mt-1 break-all text-sm text-ui-text-secondary">{account.email}</p></div>
                <FormField id="current_password" label="Kata sandi Admin Anda" type="password" autoComplete="current-password" required value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} error={form.errors.current_password} hint="Konfirmasi identitas Anda sebelum mengubah akses pengguna." />
                <FormField id="password" label="Kata sandi baru pengguna" type="password" autoComplete="new-password" required maxLength={72} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} hint="Minimal 8 karakter, huruf besar/kecil dan angka; maksimal 72 byte." />
                <FormField id="password_confirmation" label="Konfirmasi kata sandi baru" type="password" autoComplete="new-password" required maxLength={72} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={form.errors.password_confirmation} />
                <p className="ui-management-note ui-management-note--warning">Sesi akun ini akan dicabut. Pengguna perlu masuk kembali dengan kata sandi baru. Status aktif akun tidak berubah.</p>
                <div className="ui-management-actions"><Button processing={form.processing} variant="primary" type="submit" disabled={form.processing}>{form.processing ? 'Memperbarui…' : 'Perbarui kata sandi'}</Button><Link href="/admin/accounts" className="ui-management-link">Batal</Link></div>
            </ManagementForm>
        </ManagementLayout>
    );
}
