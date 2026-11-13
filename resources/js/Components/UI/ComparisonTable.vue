<script setup>
import { amount } from '@/utils/format';

/** A statement's lines with the earlier period beside the current one: change and percentage. */
defineProps({ comparison: { type: Object, required: true }, currentLabel: { type: String, default: '' } });
const pct = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${v}%`);
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-comparison>
        <table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500">
                <tr>
                    <th class="text-start px-4 py-2">{{ $t('cmp.account') }}</th>
                    <th class="px-3 py-2 text-end">{{ currentLabel || $t('cmp.current_period') }}</th>
                    <th class="px-3 py-2 text-end">{{ comparison.label }}</th>
                    <th class="px-3 py-2 text-end">{{ $t('cmp.difference') }}</th>
                    <th class="px-3 py-2 text-end">%</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in comparison.rows" :key="i" :class="[r.heading ? 'bg-slate-50 font-black' : r.bold ? 'font-black border-t border-slate-200' : 'border-t border-slate-100']">
                    <td class="px-4 py-1.5"><span v-if="r.code" class="font-mono text-slate-400 me-2">{{ r.code }}</span>{{ r.name }}</td>
                    <td class="px-3 py-1.5 text-end font-mono">{{ r.current === null ? '' : amount(r.current) }}</td>
                    <td class="px-3 py-1.5 text-end font-mono text-slate-500">{{ r.previous === null ? '' : amount(r.previous) }}</td>
                    <td class="px-3 py-1.5 text-end font-mono" :class="r.change > 0 ? 'text-emerald-700' : r.change < 0 ? 'text-rose-700' : ''">{{ r.change === null ? '' : amount(r.change) }}</td>
                    <td class="px-3 py-1.5 text-end font-mono text-slate-500">{{ r.change === null ? '' : pct(r.pct) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
