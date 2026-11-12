import AppLayout from '../../layouts/AppLayout';

export default function ManagementLayout({ children }) {
    return <AppLayout><div className="ui-management-page">{children}</div></AppLayout>;
}
