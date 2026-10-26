import axios from 'axios';

/**
 * Remote type-ahead for long lists (customers, products, suppliers).
 * Pages receive only a first slice of a list; when the user types, matching
 * records are fetched from the server and merged into that same list, so the
 * existing client-side filtering keeps working unchanged.
 *
 *   const searchProducts = createRemoteLookup('lookup.products', productsList);
 *   searchProducts(query); // debounced, cached per query
 */
export function createRemoteLookup(routeName, listRef, { minLength = 2, delay = 250 } = {}) {
    const done = new Set();
    let timer = null;

    return (query) => {
        const q = (query || '').trim();
        if (q.length < minLength || done.has(q)) return;

        clearTimeout(timer);
        timer = setTimeout(async () => {
            done.add(q);
            try {
                const { data } = await axios.get(route(routeName), { params: { q } });
                const known = new Set(listRef.value.map((item) => item.id));
                const fresh = (data.data || []).filter((item) => !known.has(item.id));
                if (fresh.length) listRef.value.push(...fresh);
            } catch (e) {
                done.delete(q);
            }
        }, delay);
    };
}
