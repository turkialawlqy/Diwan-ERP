<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ inspections: Object, filters: Object });
const tabs = computed(() => ([['', t('mfg_manufacturing_quality_inspections_index.all')], ['pending', t('mfg_manufacturing_quality_inspections_index.awaiting_inspection')], ['pass', t('mfg_manufacturing_quality_inspections_index.conforming')], ['partial', t('mfg_manufacturing_quality_inspections_index.partial')], ['fail', t('mfg_manufacturing_quality_inspections_index.non_conforming')]]));
const go = (k) => router.get(route('manufacturing.inspections.index'), { result: k || undefined }, { preserveState: true, replace: true });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_quality_inspections_index.quality_inspections')" />
        <PageHeader :title="$t('mfg_manufacturing_quality_inspections_index.quality_inspections')" :subtitle="$t('mfg_manufacturing_quality_inspections_index.receipt_inspection_results_accep')" :crumbs="[{ label: $t('mfg_manufacturing_quality_inspections_index.manufacturing') }, { label: $t('mfg_manufacturing_quality_inspections_index.quality_inspections') }]">
            <template #actions><Link v-if="$can('manufacturing.quality.create')" :href="route('manufacturing.inspections.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_quality_inspections_index.new_inspection') }}</Link></template>
        </PageHeader>
        <div class="flex flex-wrap gap-2 mb-4"><button v-for="[k, l] in tabs" :key="k" type="button" @click="go(k)" :class="(filters?.result || '') === k ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200'" class="px-3 py-1.5 rounded-full text-xs font-bold">{{ l }}</button></div>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.number') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.date') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.inspected') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.accepted') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.rejected') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_inspections_index.result') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="i in inspections.data" :key="i.id" class="hover:bg-slate-50/60 cursor-pointer" @click="router.visit(route('manufacturing.inspections.show', i.id))">
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700">{{ i.inspection_number }}</td><td class="px-4 py-3 font-bold">{{ i.product?.name_ar }}</td><td class="px-4 py-3 font-mono">{{ String(i.inspection_date).slice(0, 10) }}</td>
                        <td class="px-4 py-3 font-mono">{{ num(i.inspected_quantity) }}</td><td class="px-4 py-3 font-mono">{{ num(i.accepted_quantity) }}</td><td class="px-4 py-3 font-mono">{{ num(i.rejected_quantity) }}</td><td class="px-4 py-3"><StatusPill :status="i.result" /></td>
                    </tr>
                    <tr v-if="!inspections.data.length"><td colspan="7" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_index.no_inspections') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="inspections.links" class="mt-4" />
    </AppLayout>
</template>
