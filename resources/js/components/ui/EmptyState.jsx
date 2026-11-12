export default function EmptyState({ title, description }) {
    return <div role="status" className="ui-empty-state">
        <p className="ui-empty-state-title">{title}</p>
        {description && <p className="ui-empty-state-description">{description}</p>}
    </div>;
}
