import { Link } from '@inertiajs/react';

export default function Breadcrumbs({ items }) {
    return (
        <nav aria-label="Jejak navigasi" className="ui-breadcrumbs">
            <ol>{items.map((item, index) => (
                <li key={index}>
                    {index > 0 && <span aria-hidden="true" className="ui-breadcrumbs__separator">/</span>}
                    {item.href ? <Link href={item.href}>{item.label}</Link> : <span aria-current="page">{item.label}</span>}
                </li>
            ))}</ol>
        </nav>
    );
}
