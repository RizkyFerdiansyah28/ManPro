import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { navigationForRole } from '../../utils/navigation';

export default function SidebarNavigation({ role, activeHref, onNavigate, compact = false }) {
    const id = useId();
    return (
        <nav aria-label="Navigasi utama" className={compact ? 'ui-navigation ui-navigation--compact' : 'ui-navigation'}>
            {navigationForRole(role).map((group, index) => (
                <div key={group.label} className="ui-navigation__group" role="group" aria-labelledby={id + '-' + index}>
                    <p id={id + '-' + index} className="ui-navigation__heading">{group.label}</p>
                    <ul>{group.items.map((item) => (
                        <li key={item.href}><Link href={item.href} onClick={onNavigate} aria-current={activeHref === item.href ? 'page' : undefined} className="ui-navigation__link"><span>{item.label}</span></Link></li>
                    ))}</ul>
                </div>
            ))}
        </nav>
    );
}
