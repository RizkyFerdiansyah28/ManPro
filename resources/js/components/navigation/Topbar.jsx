import Button from '../ui/Button';
import { roleLabels } from '../../utils/navigation';

export default function Topbar({ user, context, menuOpen, drawerId, triggerRef, onMenu, onLogout, logoutProcessing }) {
    const knownRole = Object.hasOwn(roleLabels, user.role);
    const roleLabel = knownRole ? roleLabels[user.role] : 'Akun';
    return (
        <div className="ui-topbar">
            {knownRole && <Button ref={triggerRef} variant="secondary" className="ui-topbar__menu" aria-label="Buka menu navigasi" aria-expanded={menuOpen} aria-controls={drawerId} aria-haspopup="dialog" onClick={onMenu}>
                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" /></svg><span>Menu</span>
            </Button>}
            <div className="ui-topbar__context">
                <p className="ui-topbar__eyebrow">Ruang kerja {roleLabel}</p><p className="ui-topbar__title">{context.title}</p>
            </div>
            <div className="ui-topbar__account">
                <div className="ui-topbar__identity"><p className="ui-topbar__name">{user.name}</p><p className="ui-topbar__role">{roleLabel}</p></div>
                <Button variant="secondary" onClick={onLogout} processing={logoutProcessing}>{logoutProcessing ? 'Keluar…' : 'Keluar'}</Button>
            </div>
        </div>
    );
}
