import { useId } from 'react';

export default function TableFrame({ label, children }) {
    const hintId = useId();
    return <div className="ui-table-frame">
        <p id={hintId} className="ui-table-hint">Tabel dapat digeser secara horizontal bila diperlukan.</p>
        <div role="region" aria-label={label} aria-describedby={hintId} tabIndex={0} className="ui-table-scroll">{children}</div>
    </div>;
}
