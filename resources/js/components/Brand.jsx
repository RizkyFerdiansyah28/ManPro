import { Link } from '@inertiajs/react';

export default function Brand({ href = '/' }) {
    return (
        <Link href={href} className="ui-brand" aria-label="Silansia, beranda">
            <span className="ui-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6"><path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
            </span>
            <span className="ui-brand-wordmark">Silansia<span className="ui-brand-punctuation">.</span></span>
        </Link>
    );
}
