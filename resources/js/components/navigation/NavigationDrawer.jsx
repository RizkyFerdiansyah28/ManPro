import { useEffect, useRef } from 'react';
import Brand from '../Brand';
import Button from '../ui/Button';
import SidebarNavigation from './SidebarNavigation';
import { roleLabels } from '../../utils/navigation';
import { desktopNavigationQuery, openNavigationDialog } from '../../utils/navigationDialog';

export default function NavigationDrawer({ id, open, onClose, user, activeHref, triggerRef, mainRef }) {
    const dialogRef = useRef(null);
    useEffect(() => {
        if (!open) return;
        return openNavigationDialog({ dialog: dialogRef.current, trigger: triggerRef.current, fallback: mainRef.current, media: window.matchMedia(desktopNavigationQuery), onClose });
    }, [open, onClose, triggerRef, mainRef]);
    return (
        <dialog ref={dialogRef} id={id} aria-labelledby={id + '-title'} className="ui-drawer">
            {open && <div className="ui-drawer__panel">
                <div className="ui-drawer__header"><h2 id={id + '-title'}>Menu navigasi</h2><Button variant="secondary" data-drawer-close onClick={onClose}>Tutup</Button></div>
                <div className="ui-shell__brand"><Brand href={user.home_url} /></div>
                <div className="ui-drawer__identity"><p>{user.name}</p><p className="ui-topbar__role">{roleLabels[user.role]}</p></div>
                <SidebarNavigation role={user.role} activeHref={activeHref} onNavigate={onClose} />
            </div>}
        </dialog>
    );
}
