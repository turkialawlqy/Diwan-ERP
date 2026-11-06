<script setup>
import { router } from '@inertiajs/vue3';
import { amount } from '@/utils/format';

/** Sales / purchases broken down by a dimension; the tabs reload the page with ?by=. */
const props = defineProps({
    routeName: { type: String, required: true },
    tabs: { type: Array, required: true }, // [[key, label]]
    by: { type: String, required: true },
    filters: { type: Object, required: true },
    breakdown: { type: Object, required: true },
    nameLabel: { type: String, default: null },
});
const go = (key) => router.get(route(props.routeName), { ...props.filters, by: key }, { preserveState: true });
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden" data-breakdown>
        <div class="flex flex-wrap items-center gap-1.5 p-3 border-b border-slate-100 print:hidden">
            <button v-for="[key, label] in tabs" :key="key" type="button" :data-tab="key" @click="go(key)"
                :class="by === key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="px-3 py-1.5 rounded-lg text-xs font-bold">{{ label }}</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="text-start px-4 py-2">{{ nameLabel || $t('shared.name') }}</th>
                        <th class="px-3 py-2 text-end">{{ $t('shared.documents') }}</th>
                        <th v-if="breakdown.total.quantity !== null" class="px-3 py-2 text-end">{{ $t('shared.quantity') }}</th>
                        <th class="px-3 py-2 text-end">{{ $t('shared.amount_before_tax') }}</th>
                        <th class="px-3 py-2 text-end">%</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in breakdown.rows" :key="i" class="border-t border-slate-100" data-breakdown-row>
                        <td class="px-4 py-1.5 font-bold text-slate-700">{{ r.name }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ r.documents }}</td>
                        <td v-if="breakdown.total.quantity !== null" class="px-3 py-1.5 text-end font-mono">{{ r.quantity }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ amount(r.amount) }}</td>
                        <td class="px-3 py-1.5 text-end font-mono text-slate-500">{{ r.share === null ? '' : r.share + '%' }}</td>
                    </tr>
                    <tr v-if="!breakdown.rows.length"><td colspan="5" class="px-4 py-6 text-center text-slate-400">{{ $t('shared.no_data_period') }}</td></tr>
                </tbody>
                <tfoot class="font-black bg-slate-50">
                    <tr class="border-t-2 border-slate-200">
                        <td class="px-4 py-2">{{ $t('shared.total') }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ breakdown.total.documents }}</td>
                        <td v-if="breakdown.total.quantity !== null" class="px-3 py-2 text-end font-mono">{{ breakdown.total.quantity }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ amount(breakdown.total.amount) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
