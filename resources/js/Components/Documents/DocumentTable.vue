<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, qty } from '@/utils/format';

/**
 * Paginated document list. columns: [{ key: 'a.b', label, type?: money|date|status|mono|qty }]
 */
const props = defineProps({
    paginator: { type: Object, required: true },
    columns: { type: Array, required: true },
    link: { type: Function, default: null },
    searchRoute: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: null },
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const apply = () => router.get(route(props.searchRoute), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });

const value = (row, key) => key.split('.').reduce((v, k) => (v == null ? v : v[k]), row);
const format = (row, col) => {
    const v = value(row, col.key);
    if (col.type === 'money') return money(v);
    if (col.type === 'date') return date(v);
    if (col.type === 'qty') return qty(v);
    return v ?? '—';
};
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <div v-if="searchRoute" class="flex flex-wrap gap-2 p-3 border-b border-slate-100">
            <input v-model="search" @keyup.enter="apply" placeholder="بحث..." class="rounded-xl border-slate-200 text-xs w-64" />
            <select v-if="statuses" v-model="status" @change="apply" class="rounded-xl border-slate-200 text-xs">
                <option value="">كل الحالات</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <button @click="apply" class="px-3 py-1.5 rounded-xl bg-slate-800 text-white text-xs font-bold">بحث</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th v-for="c in columns" :key="c.key" class="px-4 py-2" :class="c.type === 'money' ? 'text-end' : 'text-start'">{{ c.label }}</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in paginator.data" :key="row.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                        <td v-for="c in columns" :key="c.key" class="px-4 py-2" :class="{ 'text-end font-mono': c.type === 'money', 'font-mono': c.type === 'mono' || c.type === 'date' || c.type === 'qty' }">
                            <StatusBadge v-if="c.type === 'status'" :status="value(row, c.key)" />
                            <template v-else>{{ format(row, c) }}</template>
                        </td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <slot name="actions" :row="row"><Link v-if="link" :href="link(row)" class="text-indigo-600 font-bold">فتح</Link></slot>
                        </td>
                    </tr>
                    <tr v-if="!paginator.data.length"><td :colspan="columns.length + 1" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="paginator" />
    </div>
</template>
