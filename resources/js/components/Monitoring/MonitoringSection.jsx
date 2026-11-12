import { useId } from 'react';

export default function MonitoringSection({ title, description, actions, tone = 'default', className = '', children }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className={'ui-monitoring-section ' + (tone === 'attention' ? 'ui-monitoring-section--attention ' : '') + className}>
            <header className="ui-monitoring-section__header">
                <div><h2 id={titleId}>{title}</h2>{description && <p className="ui-monitoring-copy">{description}</p>}</div>
                {actions && <div className="ui-monitoring-section__actions">{actions}</div>}
            </header>
            <div className="ui-monitoring-section__body">{children}</div>
        </section>
    );
}
