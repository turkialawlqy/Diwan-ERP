<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ centers: Array });
const types = computed(() => ({ machine: t('mfg_manufacturing_workcenters_index.machine'), line: t('mfg_manufacturing_workcenters_index.production_line'), labour: t('mfg_manufacturing_workcenters_index.manual_labor'), workshop: t('mfg_manufacturing_workcenters_index.workshop') }));
const remove = (c) => confirm(t('mfg_manufacturing_workcenters_index.delete_the_work_center_n', { n0: c.name })) && router.delete(route('manufacturing.work-centers.destroy', c.id), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_workcenters_index.work_centers')" />
        <PageHeader :title="$t('mfg_manufacturing_workcenters_index.work_centers')" :subtitle="$t('mfg_manufacturing_workcenters_index.machines_production_lines_and_wo')" :crumbs="[{ label: $t('mfg_manufacturing_workcenters_index.manufacturing') }, { label: $t('mfg_manufacturing_workcenters_index.work_centers') }]">
            <template #actions>
                <Link v-if="$can('manufacturing.work_centers.create')" :href="route('manufacturing.work-centers.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_workcenters_index.new_work_center') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.code') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.name') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.type') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.capacity_hours_day') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.efficiency') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.wages_hour') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_workcenters_index.expenses_hour') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="c in centers" :key="c.id" class="hover:bg-slate-50/60" :class="{ 'opacity-50': !c.is_active }">
                        <td class="px-4 py-3 font-mono font-bold">{{ c.code }}</td><td class="px-4 py-3 font-bold text-slate-800">{{ c.name }}<span v-if="c.equipment" class="block text-3xs text-slate-400">{{ $t('mfg_manufacturing_workcenters_index.equipment_n', { n0: c.equipment.name }) }}</span></td>
                        <td class="px-4 py-3">{{ types[c.type] }}</td><td class="px-4 py-3 font-mono">{{ c.capacity_hours_per_day }}</td><td class="px-4 py-3 font-mono">{{ c.efficiency }}%</td>
                        <td class="px-4 py-3 font-mono">{{ c.labour_rate_per_hour }}</td><td class="px-4 py-3 font-mono">{{ c.overhead_rate_per_hour }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <Link v-if="$can('manufacturing.work_centers.edit')" :href="route('manufacturing.work-centers.edit', c.id)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50"><PencilSquareIcon class="w-4 h-4" /></Link>
                            <button v-if="$can('manufacturing.work_centers.delete')" type="button" @click="remove(c)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50"><TrashIcon class="w-4 h-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!centers.length"><td colspan="8" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_workcenters_index.no_work_centers_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
