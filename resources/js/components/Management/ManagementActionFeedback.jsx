import { useEffect, useRef } from 'react';

export default function ManagementActionFeedback({ errors }) {
    const element = useRef(null);
    const key = JSON.stringify(errors);
    useEffect(() => {
        if (Object.keys(errors).length) element.current?.focus();
    }, [key]);
    if (!Object.keys(errors).length) return null;
    return <div ref={element} tabIndex={-1} role="alert" className="ui-management-error-summary">
        <p className="ui-management-error-summary__title">Tindakan belum dapat disimpan</p>
        <ul>{Object.entries(errors).map(([field, message]) => <li key={field}>{message}</li>)}</ul>
    </div>;
}
