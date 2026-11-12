import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Brand from '../../components/Brand';
import FormField from '../../components/FormField';
import Button from '../../components/ui/Button';
import focusFormErrors from '../../utils/focusFormErrors';

export default function Login() {
    const form = useForm({ email: '', password: '' });
    function submit(event) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }
    return <main className="ui-auth-page">
        <Head title="Masuk · Silansia" />
        <div className="ui-auth-content">
            <div className="ui-auth-brand"><Brand /></div>
            <section className="ui-auth-panel" aria-labelledby="login-title">
                <header className="ui-auth-header">
                    <h1 id="login-title">Masuk ke akun</h1>
                    <p>Gunakan akun yang telah diaktifkan oleh Admin panti untuk mengakses Silansia.</p>
                </header>
                <LoginForm form={form} onSubmit={submit} />
                <p className="ui-auth-help">Perlu bantuan masuk? Hubungi Admin panti untuk bantuan akun.</p>
            </section>
            <p className="ui-auth-security-note">Jaga kerahasiaan kata sandi Anda. Keluar dari akun setelah menggunakan perangkat bersama.</p>
        </div>
    </main>;
}

export function LoginForm({ form, onSubmit }) {
    const element = useRef(null);
    const summary = useRef(null);
    const errorsKey = JSON.stringify(form.errors);
    useEffect(() => {
        if (!form.processing) focusFormErrors(element.current, summary.current, form.errors);
    }, [errorsKey, form.processing]);
    return <form ref={element} onSubmit={onSubmit} className="ui-auth-form" aria-label="Form masuk" aria-busy={form.processing || undefined}>
        {Object.keys(form.errors).length > 0 && <div ref={summary} tabIndex={-1} role="alert" className="ui-auth-error">
            <p className="ui-auth-error__title">Periksa informasi masuk</p>
            <ul>{Object.entries(form.errors).map(([field, message]) => <li key={field}>{message}</li>)}</ul>
        </div>}
        <fieldset disabled={form.processing}>
            <legend className="sr-only">Email dan kata sandi</legend>
            <FormField id="email" label="Email" type="email" autoComplete="username" required maxLength={254} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} />
            <FormField id="password" label="Kata sandi" type="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
            <Button type="submit" processing={form.processing} className="ui-auth-submit">{form.processing ? 'Memeriksa akun…' : 'Masuk'}</Button>
        </fieldset>
    </form>;
}
