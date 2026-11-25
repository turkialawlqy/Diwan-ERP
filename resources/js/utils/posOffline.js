/**
 * The terminal's local store (IndexedDB): the sales made without a connection, waiting to be sent, and the products seen
 * so far, so the till can search them with no link. One database per company and cashier, so a shared computer never
 * mixes two people's queues.
 */
const PRODUCT_LIMIT = 5000;

function open(scope) {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') return reject(new Error('IndexedDB is not available'));
        const request = indexedDB.open(`diwan-pos-${scope}`, 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            db.createObjectStore('queue', { keyPath: 'client_uuid' });
            db.createObjectStore('products', { keyPath: 'id' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function run(scope, store, mode, work) {
    return open(scope).then((db) => new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const result = work(tx.objectStore(store));
        tx.oncomplete = () => { db.close(); resolve(result?.result ?? result); };
        tx.onerror = () => { db.close(); reject(tx.error); };
    }));
}

const all = (scope, store) => run(scope, store, 'readonly', (s) => s.getAll());

export const pos = (scope) => ({
    /** Sales waiting to be sent, oldest first. */
    queue: () => all(scope, 'queue').then((rows) => rows.sort((a, b) => a.created_at.localeCompare(b.created_at))),
    add: (sale) => run(scope, 'queue', 'readwrite', (s) => s.put(sale)),
    remove: (uuid) => run(scope, 'queue', 'readwrite', (s) => s.delete(uuid)),
    /** Keeps a refused sale (a closed shift...) with the reason, for the cashier or a manager to see. */
    reject: (uuid, message) => all(scope, 'queue').then((rows) => {
        const sale = rows.find((r) => r.client_uuid === uuid);
        return sale ? run(scope, 'queue', 'readwrite', (s) => s.put({ ...sale, rejected: message })) : null;
    }),
    /** Remembers the products shown, newest first, up to a bound. */
    cacheProducts: (list) => run(scope, 'products', 'readwrite', (s) => {
        list.slice(0, PRODUCT_LIMIT).forEach((p) => s.put(p));
    }),
    searchProducts: (query, categoryId = null, limit = 60) => all(scope, 'products').then((rows) => {
        const q = String(query || '').trim().toLowerCase();
        return rows
            .filter((p) => (!categoryId || Number(p.category_id) === Number(categoryId))
                && (!q || [p.name_ar, p.name_en, p.sku, p.barcode].some((v) => String(v || '').toLowerCase().includes(q))))
            .sort((a, b) => String(a.name_ar).localeCompare(String(b.name_ar), 'ar'))
            .slice(0, limit);
    }),
});

/** A v4 id, also where crypto.randomUUID does not exist (an older browser, or plain HTTP on the office network). */
export function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    (typeof crypto !== 'undefined' && crypto.getRandomValues ? crypto.getRandomValues(bytes) : bytes.forEach((_, i) => { bytes[i] = Math.floor(Math.random() * 256); }));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** «2026-07-03T18:30:00+03:00»: the moment of the sale with the till's own offset, so the server dates the invoice by the local day. */
export function localIso(date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    const offset = -date.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${pad(Math.floor(Math.abs(offset) / 60))}:${pad(Math.abs(offset) % 60)}`;
}
