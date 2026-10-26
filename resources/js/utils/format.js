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

// Hijri (Umm al-Qura) date, e.g. "29 ربيع الآخر 1448 هـ". Noon avoids
// day shifts from the viewer's timezone.
const hijriFormatter = (() => {
    try {
        return new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura-nu-latn', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Riyadh' });
    } catch {
        return null;
    }
})();

export function hijri(value) {
    if (!value || !hijriFormatter) return '';
    const d = new Date(`${String(value).substring(0, 10)}T12:00:00+03:00`);
    return Number.isNaN(d.getTime()) ? '' : hijriFormatter.format(d);
}
