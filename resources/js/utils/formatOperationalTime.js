const formatter = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' });
export default function formatOperationalTime(value) {
    return value ? formatter.format(new Date(value)) : 'Masih aktif';
}
