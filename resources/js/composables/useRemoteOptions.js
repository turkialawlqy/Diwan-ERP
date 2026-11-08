import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';

// What searches have found so far, per lookup: shared by every dropdown of that lookup, so a
// record picked in one line keeps its label if the lines are reordered or removed.
const found = reactive({});

/**
 * Server-side search for a long dropdown (see SelectCreate's `search`). While `term` changes it
 * asks the lookup route, debounced, and collects the records returned. Remembers what was asked.
 */
export function useRemoteOptions(search, term, { delay = 250, minLength = 2 } = {}) {
    const config = computed(() => {
        const value = search();
        if (!value) return null;
        return typeof value === 'string' ? { route: value, params: {} } : { route: value.route, params: value.params || {} };
    });
    const key = computed(() => (config.value ? `${config.value.route}:${JSON.stringify(config.value.params)}` : ''));
    const options = computed(() => (key.value ? found[key.value] || [] : []));
    const searching = ref(false);
    const asked = new Set();
    let timer = null;
    let sequence = 0;

    watch(term, (q) => {
        clearTimeout(timer);
        const current = ++sequence;
        const askKey = `${key.value}|${q}`;
        if (!config.value || q.length < minLength || asked.has(askKey)) {
            searching.value = false;
            return;
        }
        searching.value = true;
        // The lookup as of now: the dropdown may switch to another one while the answer is on its way.
        const { route: routeName, params } = config.value;
        const storeKey = key.value;
        timer = setTimeout(async () => {
            try {
                const { data } = await axios.get(route(routeName), { params: { ...params, q } });
                const records = (found[storeKey] ??= []);
                const known = new Set(records.map((r) => r.id));
                records.push(...(data.data || []).filter((r) => !known.has(r.id)));
                asked.add(askKey);
            } catch (e) {
                // A failed search leaves the list as it is; typing again retries.
            }
            if (current === sequence) searching.value = false;
        }, delay);
    });

    return { options, searching };
}
