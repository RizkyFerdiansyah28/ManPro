const variants = { primary: 'ui-button--primary', secondary: 'ui-button--secondary' };

export default function Button({ type = 'button', variant = 'primary', processing = false, disabled = false, className = '', children, ...props }) {
    return (
        <button {...props} type={type} disabled={disabled || processing} aria-busy={processing || undefined}
            className={`ui-button ${variants[variant] ?? variants.primary} ${className}`}>
            {processing && <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" className="ui-button-spinner">
                <circle cx="10" cy="10" r="7" stroke="currentColor" strokeWidth="2" opacity="0.25" />
                <path d="M10 3a7 7 0 0 1 7 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            </svg>}
            {children}
        </button>
    );
}
