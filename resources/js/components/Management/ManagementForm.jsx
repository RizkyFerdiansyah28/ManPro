import { createContext, useContext, useEffect, useId, useRef } from 'react';
import { router } from '@inertiajs/react';
import { focusManagementErrors, managementErrorTarget, protectManagementInput, submitManagementForm } from '../../utils/managementForm';

const FormContext = createContext(null);

export default function ManagementForm({ form, label, kind = 'editor', onSubmit, children }) {
    const id = useId();
    const element = useRef(null);
    const summary = useRef(null);
    const latch = useRef(false);
    const submitting = useRef(false);
    const current = useRef(null);
    current.current = { dirty: form.isDirty && !form.recentlySuccessful, submitting: submitting.current };
    const errorsKey = JSON.stringify(form.errors);

    useEffect(() => {
        if (!form.processing) focusManagementErrors(element.current, summary.current, form.errors);
    }, [errorsKey, form.processing]);

    useEffect(() => {
        if (kind !== 'editor') return;
        return protectManagementInput({ router, windowTarget: window,
            current: () => ({ ...current.current, submitting: submitting.current }),
            confirm: (message) => window.confirm(message) });
    }, [kind]);

    const summaryId = `${id}-errors`;
    return <FormContext.Provider value={{ errors: form.errors, id, summaryId }}>
        <form ref={element} aria-label={label} aria-busy={form.processing || undefined}
            className={`ui-management-form ui-management-form--${kind}`}
            onSubmit={(event) => submitManagementForm({ event, processing: form.processing, latch, submitting, submit: onSubmit })}>
            {Object.keys(form.errors).length > 0 && <div id={summaryId} ref={summary} tabIndex={-1} role="alert" className="ui-management-error-summary">
                <p className="ui-management-error-summary__title">Periksa isian sebelum melanjutkan</p>
                <ul>{Object.entries(form.errors).map(([field, message]) => <li key={field}>
                    <button type="button" onClick={() => (managementErrorTarget(element.current, field) ?? summary.current)?.focus()}>{message}</button>
                </li>)}</ul>
            </div>}
            <fieldset disabled={form.processing} className="ui-management-form__fields">
                <legend className="sr-only">{label}</legend>
                {children}
            </fieldset>
            {kind === 'editor' && form.isDirty && !form.recentlySuccessful && <p className="ui-management-unsaved">Ada perubahan yang belum disimpan.</p>}
        </form>
    </FormContext.Provider>;
}

export function ManagementControl({ as: Control = 'input', field, className = '', 'aria-describedby': describedBy, ...props }) {
    const context = useContext(FormContext);
    const generatedId = useId();
    const error = context?.errors[field];
    const choice = props.type === 'radio' || props.type === 'checkbox';
    const errorId = `${generatedId}-error`;
    const descriptions = [describedBy, error ? choice ? context.summaryId : errorId : null].filter(Boolean).join(' ') || undefined;
    return <>
        <Control {...props} name={props.name ?? field} data-management-field={field}
            aria-invalid={Boolean(error) || Boolean(props['aria-invalid'])} aria-describedby={descriptions}
            className={`${choice ? 'ui-management-choice-control' : 'ui-field-control'} ${error ? 'ui-field-control--invalid' : ''} ${className}`} />
        {error && !choice && <span id={errorId} className="ui-field-error">{error}</span>}
    </>;
}
