<script setup>
import { Link } from '@inertiajs/vue3';
import { amount } from '@/utils/format';

defineProps({
    title: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    totalLabel: { type: String, default: '' },
    ledgerQuery: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="mb-4">
        <h3 class="font-black text-sm text-slate-800 bg-slate-50 px-4 py-2 rounded-xl">{{ title }}</h3>
        <table class="w-full text-xs mt-1">
            <tbody>
                <tr v-for="row in rows" :key="row.id ?? row.account_id" class="border-b border-slate-50">
                    <td class="px-4 py-1.5 text-slate-700">
                        <Link v-if="row.id" :href="route('accounting.ledger.index', { account_id: row.id, ...ledgerQuery })" class="hover:text-indigo-600">
                            <span class="font-mono text-slate-400">{{ row.code }}</span> {{ row.name }}
                        </Link>
                        <span v-else>{{ row.name }}</span>
                    </td>
                    <td class="px-4 py-1.5 text-end font-mono w-40">{{ amount(row.amount) }}</td>
                </tr>
                <tr v-if="!rows.length"><td colspan="2" class="px-4 py-2 text-slate-400">—</td></tr>
            </tbody>
            <tfoot>
                <tr class="font-black text-slate-900">
                    <td class="px-4 py-2">{{ totalLabel || ('إجمالي ' + title) }}</td>
                    <td class="px-4 py-2 text-end font-mono border-t-2 border-slate-300">{{ amount(total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
