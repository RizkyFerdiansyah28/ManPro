export function initialMonitoringFilters(filters, mode = 'records') {
    return {
        ...(mode !== 'open' ? { range: filters.range ?? '24h', date_from: filters.date_from ?? '', date_to: filters.date_to ?? '' } : {}),
        ...(mode !== 'dashboard' ? { search: filters.search ?? '', class_code: filters.class_code ?? '', camera_id: filters.camera_id ?? '', room_id: filters.room_id ?? '', per_page: filters.per_page ?? 15 } : {}),
        ...(mode === 'open' ? { status: filters.status ?? '' } : {}),
        ...(mode === 'records' ? Object.fromEntries(['event_type', 'fall_status', 'gait_category', 'verification', 'identity', 'location'].map((key) => [key, filters[key] ?? ''])) : {}),
    };
}

export function updateMonitoringRange(data, range) {
    return { ...data, range, date_from: '', date_to: '' };
}

export function updateMonitoringEventType(data, eventType) {
    return { ...data, event_type: eventType, fall_status: '', gait_category: '', verification: '' };
}

const dates = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' });
export function monitoringRangeLabel(filters) {
    if (filters.range === 'custom') {
        const values = [filters.date_from, filters.date_to].map((date) => date ? new Date(date + 'T00:00:00+07:00') : null);
        return values.every((date) => date && !Number.isNaN(date.getTime()))
            ? dates.format(values[0]) + ' – ' + dates.format(values[1]) + ' (WIB)'
            : 'Tanggal khusus (WIB)';
    }
    return { '24h': '24 jam terakhir', '7d': '7 hari terakhir', '30d': '30 hari terakhir' }[filters.range ?? '24h'] ?? 'Periode sesuai filter';
}
