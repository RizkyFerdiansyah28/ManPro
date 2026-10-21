export default function FormField({ id, label, error, hint, ...props }) {
    return (
        <div className="space-y-2">
            <label htmlFor={id} className="block text-sm font-semibold text-slate-800">{label}</label>
            <input id={id} name={id} {...props} aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                className={`w-full rounded-xl border bg-white px-3.5 py-3 text-sm text-slate-900 outline-none transition focus:ring-4 ${error ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : 'border-slate-300 focus:border-emerald-600 focus:ring-emerald-100'}`} />
            {hint && <p id={`${id}-hint`} className="text-xs leading-relaxed text-slate-500">{hint}</p>}
            {error && <p id={`${id}-error`} role="alert" className="text-sm text-red-700">{error}</p>}
        </div>
    );
}
