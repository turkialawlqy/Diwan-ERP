/**
 * Number / money / date formatting shared by pages.
 */
const moneyFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 });

export function money(value) {
    const n = Number(value ?? 0);
    return Number.isFinite(n) ? moneyFormatter.format(n) : '0.00';
}

/** Accounting style: negatives in parentheses. */
export function amount(value) {
    const n = Number(value ?? 0);
    return n < 0 ? `(${money(-n)})` : money(n);
}

export function qty(value) {
    return qtyFormatter.format(Number(value ?? 0));
}

export function date(value) {
    return value ? String(value).substring(0, 10) : '—';
}

export function monthLabel(period) {
    const s = String(period);
    return `${s.substring(0, 4)}-${s.substring(4, 6)}`;
}
