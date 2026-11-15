<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

defineProps({ types: Array });
</script>

<template>
    <AppLayout>
        <Head :title="$t('wo2_workorders_types_index.job_types')" />
        <PageHeader :title="$t('wo2_workorders_types_index.job_types')" :subtitle="$t('wo2_workorders_types_index.the_billed_hourly_rate_for_each')" :crumbs="[{ label: $t('wo2_workorders_types_index.work_orders'), href: route('work-orders.index') }, { label: $t('wo2_workorders_types_index.types') }]">
            <template #actions><Link v-if="$can('work_orders.types.create')" :href="route('work-orders.types.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-type><PlusIcon class="w-4 h-4" /> {{ $t('wo2_workorders_types_index.new_type') }}</Link></template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_types_index.type') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_types_index.hourly_rate') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_types_index.tax') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_types_index.minimum_billing_duration') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_types_index.status') }}</th><th class="px-4 py-2 w-16"></th></tr></thead>
            <tbody>
                <tr v-for="t in types" :key="t.id" class="border-t border-slate-100" :data-type="t.name"><td class="px-4 py-2 font-bold">{{ t.name }}</td><td class="px-4 py-2 font-mono">{{ money(t.hourly_rate) }}</td><td class="px-4 py-2 font-mono">{{ Number(t.tax_rate) }}%</td><td class="px-4 py-2 font-mono">{{ $t('wo2_workorders_types_index.n_min', { n0: t.min_billable_minutes }) }}</td>
                    <td class="px-4 py-2"><span :class="t.is_active ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ t.is_active ? $t('wo2_workorders_types_index.active') : $t('wo2_workorders_types_index.disabled') }}</span></td>
                    <td class="px-4 py-2 text-end"><RowActions :edit="$can('work_orders.types.edit') ? route('work-orders.types.edit', t.id) : null" :remove="$can('work_orders.types.delete') ? route('work-orders.types.destroy', t.id) : null" :confirm="$t('wo2_workorders_types_index.delete_n', { n0: t.name })" /></td></tr>
                <tr v-if="!types.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('wo2_workorders_types_index.no_types_yet') }}</td></tr>
            </tbody></table></div>
    </AppLayout>
</template>
