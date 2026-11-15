export const roleLabels = { admin: 'Admin', perawat: 'Perawat', keluarga: 'Keluarga' };

const monitoring = [
    { href: '/monitoring/records', label: 'Rekaman' },
    { href: '/monitoring/open-incidents', label: 'Insiden terbuka' },
    { href: '/monitoring/incidents', label: 'Seluruh insiden' },
];
const management = [
    { href: '/admin/accounts', label: 'Akun' },
    { href: '/admin/residents', label: 'Lansia' },
    { href: '/admin/service-classes', label: 'Kelas layanan' },
    { href: '/admin/rooms', label: 'Kamar' },
    { href: '/admin/cameras', label: 'Kamera' },
];

export function navigationForRole(role) {
    if (role === 'keluarga') return [{ label: 'Portal keluarga', items: [
        { href: '/keluarga', label: 'Beranda' },
        { href: '/keluarga/residents', label: 'Lansia keluarga' },
    ] }];
    if (!['admin', 'perawat'].includes(role)) return [];
    return [
        { label: 'Utama', items: [{ href: '/' + role, label: 'Beranda' }] },
        { label: 'Pemantauan', items: monitoring },
        ...(role === 'admin' ? [{ label: 'Manajemen', items: management }] : []),
        ...(role === 'admin' ? [{ label: 'Billing', items: [{ href: '/admin/billing/invoices', label: 'Invoice' }] }] : []),
        { label: 'Notifikasi', items: [{ href: '/notifications', label: 'Notifikasi saya' }] },
    ];
}

const uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const children = {
    '/admin/billing/invoices': [['create', 'Terbitkan invoice'], [uuid, 'Detail invoice']],
    '/admin/accounts': [['create', 'Buat akun'], [uuid + '/password', 'Atur ulang kata sandi']],
    '/admin/residents': [['create', 'Tambah lansia'], [uuid, 'Detail lansia'], [uuid + '/edit', 'Edit lansia'], [uuid + '/operations', 'Operasional lansia'], [uuid + '/enrollments/create', 'Buat enrollment'], [uuid + '/stays/create', 'Buat masa tinggal']],
    '/admin/service-classes': [[uuid, 'Detail kelas layanan'], [uuid + '/edit', 'Edit kelas layanan']],
    '/admin/rooms': [['create', 'Tambah kamar'], [uuid, 'Detail kamar'], [uuid + '/edit', 'Edit kamar']],
    '/admin/cameras': [['create', 'Tambah kamera'], [uuid, 'Detail kamera'], [uuid + '/edit', 'Edit kamera']],
    '/monitoring/incidents': [[uuid, 'Detail insiden']],
    '/keluarga/residents': [[uuid, 'Detail lansia']],
};

export function navigationContext(role, url) {
    const items = navigationForRole(role).flatMap((group) => group.items);
    const home = items[0];
    const path = typeof url === 'string' ? url.split(/[?#]/, 1)[0].replace(/\/$/, '') : '';
    const fallback = { title: 'Halaman', activeHref: null, breadcrumbs: home ? [{ label: 'Beranda', href: home.href }, { label: 'Halaman' }] : [{ label: 'Halaman' }] };
    for (const item of items) {
        const crumbs = item === home ? [] : [{ label: 'Beranda', href: home.href }];
        if (path === item.href) return { title: item.label, activeHref: item.href, breadcrumbs: [...crumbs, { label: item.label }] };
        const child = children[item.href]?.find(([pattern]) => new RegExp('^' + item.href + '/' + pattern + '$').test(path));
        if (child) {
            const ancestors = [...crumbs, { label: item.label, href: item.href }];
            const resident = path.match(new RegExp('^/admin/residents/(' + uuid + ')/(?:operations|enrollments/create|stays/create|edit)$'));
            if (resident) {
                ancestors.push({ label: 'Detail lansia', href: '/admin/residents/' + resident[1] });
                if (/\/(enrollments|stays)\/create$/.test(path)) ancestors.push({ label: 'Operasional lansia', href: '/admin/residents/' + resident[1] + '/operations' });
            }
            return { title: child[1], activeHref: item.href, breadcrumbs: [...ancestors, { label: child[1] }] };
        }
    }
    return fallback;
}
