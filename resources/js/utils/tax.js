import { usePage } from '@inertiajs/vue3';

/**
 * Tax rules of the signed-in user's branch (its country), shared by the
 * server as page.props.tax: default rate, allowed rates, tax name, currency.
 * Forms default new lines to taxRate(); labels use taxLabel().
 */
const fallback = { has_tax: true, rate: 15, rates: [15], name: 'ضريبة القيمة المضافة', short: 'ض.ق.م', currency: 'SAR', country: 'SA' };

export const taxInfo = () => usePage().props.tax || fallback;

export const taxRate = () => Number(taxInfo().rate ?? 0);

/** "ضريبة القيمة المضافة 15%" (or the name alone), "بدون ضريبة" where the country has none. */
export const taxLabel = (withRate = true) => {
    const t = taxInfo();
    if (!t.has_tax) return 'بدون ضريبة';
    return withRate ? `${t.name} ${Number(t.rate)}%` : t.name;
};

/** Symbol of the company's base currency (ر.س, ج.م, د.إ ...). */
export const cur = () => usePage().props.tenant?.currency_symbol || 'ر.س';
