import { useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import Brand from '../components/Brand';
import NotificationBell from '../components/Notifications/NotificationBell';
import Breadcrumbs from '../components/navigation/Breadcrumbs';
import NavigationDrawer from '../components/navigation/NavigationDrawer';
import SidebarNavigation from '../components/navigation/SidebarNavigation';
import Topbar from '../components/navigation/Topbar';
import { navigationContext, roleLabels } from '../utils/navigation';

export default function AppLayout({ children }) {
    const { props: { auth, flash }, url } = usePage();
    const user = auth.user;
    const logout = useForm({});
    const staff = ['admin', 'perawat'].includes(user.role);
    const context = navigationContext(user.role, url);
    const [menuOpen, setMenuOpen] = useState(false);
    const closeMenu = useCallback(() => setMenuOpen(false), []);
    const triggerRef = useRef(null);
    const mainRef = useRef(null);
    const drawerId = useId();

    useEffect(() => { closeMenu(); }, [url, user.id, user.role, closeMenu]);

    return (
        <div className={'ui-shell' + (staff ? ' ui-shell--staff' : ' ui-shell--family')}>
            <a href="#main-content" className="ui-skip-link">Langsung ke konten</a>
            {staff && <aside className="ui-sidebar" aria-label="Panel navigasi staf">
                <div className="ui-shell__brand"><Brand href={user.home_url} /></div>
                <p className="ui-sidebar__caption">Ruang kerja {roleLabels[user.role]}</p>
                <SidebarNavigation role={user.role} activeHref={context.activeHref} />
                <p className="ui-sidebar__footer">Silansia · Layanan panti lansia</p>
            </aside>}
            <div className="ui-shell__body">
                <header className="ui-shell__header">
                    <Topbar user={user} context={context} menuOpen={menuOpen} drawerId={drawerId} triggerRef={triggerRef} onMenu={() => setMenuOpen(true)} onLogout={() => logout.post('/logout')} logoutProcessing={logout.processing} />
                    {staff && <div className="ui-shell__notifications"><NotificationBell actorId={user.id} /></div>}
                </header>
                <main ref={mainRef} id="main-content" tabIndex={-1} className="ui-shell__main">
                    <Breadcrumbs items={context.breadcrumbs} />
                    {flash?.status && <div role="status" className="ui-shell__flash">{flash.status}</div>}
                    {children}
                </main>
                <footer className="ui-shell__footer">Silansia · Akses layanan panti lansia</footer>
            </div>
            <NavigationDrawer id={drawerId} open={menuOpen} onClose={closeMenu} user={user} activeHref={context.activeHref} triggerRef={triggerRef} mainRef={mainRef} />
        </div>
    );
}
