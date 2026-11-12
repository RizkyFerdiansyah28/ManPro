export default function FamilyPageHeader({ title, description, children }) {
    return <header className="ui-family-header">
        {children}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
    </header>;
}
