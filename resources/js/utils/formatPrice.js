export default function formatPrice(value) {
    if (value === null) return 'Belum diatur';
    const [whole, fraction = '00'] = String(value).split('.');
    return `Rp ${whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${fraction.padEnd(2, '0')}`;
}
