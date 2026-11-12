export default function FormField({ id, label, error, hint, className = '', 'aria-describedby': describedBy, ...props }) {
    const descriptions = [describedBy, hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;
    return (
        <div className="ui-field-group">
            <label htmlFor={id} className="ui-field-label">{label}{props.required && <span aria-hidden="true" className="ui-field-required">Wajib</span>}</label>
            <input id={id} name={id} {...props} aria-invalid={Boolean(error)} aria-describedby={descriptions}
                className={`ui-field-control ${error ? 'ui-field-control--invalid' : ''} ${className}`} />
            {hint && <p id={`${id}-hint`} className="ui-field-hint">{hint}</p>}
            {error && <p id={`${id}-error`} role="alert" className="ui-field-error">{error}</p>}
        </div>
    );
}
