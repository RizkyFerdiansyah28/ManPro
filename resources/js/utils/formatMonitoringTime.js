const formatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Jakarta',
});

export default function formatMonitoringTime(value) {
    if (!value || Number.isNaN(new Date(value).getTime())) return 'Tidak tersedia';
    return formatter.format(new Date(value)) + ' WIB';
}
