<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineProps({ rows: Array });
const color = (pct) => (pct > 100 ? 'bg-rose-500' : pct > 80 ? 'bg-amber-500' : pct > 0 ? 'bg-emerald-500' : 'bg-slate-200');
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_capacity_index.production_capacity')" />
        <PageHeader :title="$t('mfg_manufacturing_capacity_index.production_capacity')" :subtitle="$t('mfg_manufacturing_capacity_index.each_work_center_s_load_against')" :crumbs="[{ label: $t('mfg_manufacturing_capacity_index.manufacturing') }, { label: $t('mfg_manufacturing_capacity_index.capacity') }]" />
        <p v-if="!rows.length" class="bg-white border border-slate-200/80 rounded-2xl p-10 text-center text-slate-400 text-sm">{{ $t('mfg_manufacturing_capacity_index.no_work_centers') }}</p>
        <div v-else class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_capacity_index.work_center') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_capacity_index.weekly_capacity_min') }}</th><th v-for="w in rows[0].weeks" :key="w.start" class="px-3 py-3 font-mono">{{ w.start.slice(5) }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-4 py-3 font-bold text-slate-800">{{ r.name }}<span class="block text-3xs font-mono text-slate-400">{{ r.code }}</span></td><td class="px-4 py-3 font-mono">{{ num(r.weekly_capacity) }}</td>
                        <td v-for="w in r.weeks" :key="w.start" class="px-3 py-3 min-w-24">
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div :class="color(w.pct)" class="h-full" :style="{ width: Math.min(100, w.pct) + '%' }"></div></div>
                            <p class="text-3xs mt-1 font-mono text-center" :class="w.pct > 100 ? 'text-rose-600 font-bold' : 'text-slate-500'">{{ w.pct }}% · {{ num(w.load) }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
