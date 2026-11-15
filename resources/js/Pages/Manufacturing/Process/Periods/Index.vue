<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ periods: Object });
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_process_periods_index.stage_cost_periods')" />
        <PageHeader :title="$t('mfg_manufacturing_process_periods_index.stage_cost_periods')" :subtitle="$t('mfg_manufacturing_process_periods_index.stage_cost_per_period_using_the')" :crumbs="[{ label: $t('mfg_manufacturing_process_periods_index.manufacturing') }, { label: $t('mfg_manufacturing_process_periods_index.cost_periods') }]">
            <template #actions>
                <Link v-if="$can('manufacturing.process.create')" :href="route('manufacturing.periods.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_process_periods_index.open_a_period') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.stage') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.period') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.completed_units') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.transferred') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.unit_cost') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_process_periods_index.status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="p in periods.data" :key="p.id" class="hover:bg-slate-50/60 cursor-pointer" @click="router.visit(route('manufacturing.periods.show', p.id))">
                        <td class="px-4 py-3 font-bold text-slate-800">{{ p.stage?.name }}</td><td class="px-4 py-3 font-mono">{{ String(p.date_from).slice(0, 10) }} → {{ String(p.date_to).slice(0, 10) }}</td>
                        <td class="px-4 py-3 font-mono">{{ p.units_completed }}</td><td class="px-4 py-3 font-mono">{{ money(p.transferred_out) }}</td><td class="px-4 py-3 font-mono">{{ Number(p.unit_cost).toFixed(4) }}</td>
                        <td class="px-4 py-3"><StatusPill :status="p.status === 'closed' ? 'closed' : 'open'" /></td>
                    </tr>
                    <tr v-if="!periods.data.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_process_periods_index.no_periods_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="periods.links" class="mt-4" />
    </AppLayout>
</template>
