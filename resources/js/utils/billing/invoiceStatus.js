const calendar = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit',
});

export function wibDate(now = new Date()) {
    const parts = Object.fromEntries(calendar.formatToParts(now).map(({ type, value }) => [type, value]));
    return `${parts.year.padStart(4, '0')}-${parts.month}-${parts.day}`;
}

export function millisecondsUntilNextWibDay(now, today = wibDate(now)) {
    const startOfDay = Date.parse(`${today}T00:00:00+07:00`);
    return startOfDay + 86_400_000 - now.getTime();
}

export function isInvoiceDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000-')) return false;

    const date = new Date(`${value}T00:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

export function invoiceDisplayStatus({ status, due_date, grace_period_ends_on }, today = wibDate()) {
    if (status === 'paid' || status === 'cancelled') return status;
    if (status !== 'unpaid') return 'unknown';
    if (!isInvoiceDate(due_date) || !isInvoiceDate(grace_period_ends_on) || grace_period_ends_on < due_date) return 'unpaid';
    if (today <= due_date) return 'not_due';
    return today <= grace_period_ends_on ? 'in_grace' : 'past_grace';
}
