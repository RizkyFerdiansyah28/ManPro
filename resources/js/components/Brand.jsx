import { Link } from '@inertiajs/react';

export default function Brand({ href = '/' }) {
    return (
        <Link href={href} className="inline-flex items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200" aria-label="Silansia, beranda">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-800 text-white" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6"><path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
            </span>
            <span className="text-xl font-bold tracking-tight">Silansia<span className="text-emerald-600">.</span></span>
        </Link>
    );
}
