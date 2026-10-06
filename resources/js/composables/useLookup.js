import { ref } from 'vue';
import axios from 'axios';

/**
 * Debounced type-ahead search against one of the lookup.* routes.
 * status: '' | 'loading' | 'empty' | 'forbidden' | 'error' so pickers can say
 * why nothing is listed instead of staying silent.
 */
export function useLookup(routeName, { delay = 250, minLength = 1 } = {}) {
    const term = ref('');
    const results = ref([]);
    const status = ref('');
    let timer = null;
    let sequence = 0;

    const search = () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = term.value.trim();
            if (q.length < minLength) { results.value = []; status.value = ''; return; }
            const current = ++sequence;
            status.value = 'loading';
            try {
                const { data } = await axios.get(route(typeof routeName === 'function' ? routeName() : routeName), { params: { q } });
                if (current !== sequence) return; // a newer search is in flight
                results.value = data.data;
                status.value = results.value.length ? '' : 'empty';
            } catch (e) {
                if (current !== sequence) return;
                results.value = [];
                status.value = e.response?.status === 403 ? 'forbidden' : 'error';
            }
        }, delay);
    };

    const reset = () => { clearTimeout(timer); sequence++; term.value = ''; results.value = []; status.value = ''; };

    const messages = { loading: 'جارٍ البحث...', empty: 'لا توجد نتائج مطابقة', forbidden: 'لا تملك صلاحية البحث في هذه القائمة', error: 'تعذّر البحث، حاول مرة أخرى' };

    return { term, results, status, search, reset, messages };
}
