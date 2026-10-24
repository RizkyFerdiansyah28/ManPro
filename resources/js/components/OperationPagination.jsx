import { Link } from '@inertiajs/react';

export default function OperationPagination({ page, label }) {
    return <nav aria-label={`Halaman ${label}`} className="mt-5 flex flex-wrap justify-between gap-4 text-xs text-slate-500">
        <span>{page.total} data · Halaman {page.current_page} dari {page.last_page}</span>
        <div className="flex gap-5">{page.prev_page_url ? <Link href={page.prev_page_url} preserveScroll className="font-semibold text-emerald-800">Sebelumnya</Link> : <span aria-disabled="true">Sebelumnya</span>}{page.next_page_url ? <Link href={page.next_page_url} preserveScroll className="font-semibold text-emerald-800">Berikutnya</Link> : <span aria-disabled="true">Berikutnya</span>}</div>
    </nav>;
}
