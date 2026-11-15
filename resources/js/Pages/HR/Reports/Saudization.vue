<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';

const props = defineProps({ groups: Array, total: Object, thresholds: Object, filters: Object });
const filters = reactive({ ...props.filters });
const reload = () => router.get(route('hr.reports.saudization'), filters, { preserveState: true, preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_reports_saudization.saudization_indicator')" />
        <PageHeader :title="$t('hr_reports_saudization.saudization_indicator_nitaqat')" :subtitle="$t('hr_reports_saudization.an_estimate_from_employee_data_t')" :crumbs="[{ label: $t('hr2_hr_reports_saudization.human_resources') }, { label: $t('hr2_hr_reports_saudization.saudization_indicator') }]">
            <template #actions>
                <DatePicker v-model="filters.date" @change="reload" class="rounded-xl border-slate-200 text-xs" />
                <a v-if="$can('hr.reports.export')" :href="route('hr.reports.saudization', { date: filters.date, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_reports_saudization.export_csv') }}</a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_saudization.total_employees') }}</p><p class="font-black text-base">{{ total.headcount }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_saudization.saudis_counted') }}</p><p class="font-black text-base">{{ total.saudis }} <span class="text-slate-400 text-xs">({{ total.counted }})</span></p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_saudization.non_saudis') }}</p><p class="font-black text-base">{{ total.non_saudis }}</p></div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-3"><p class="text-indigo-700">{{ $t('hr_reports_saudization.saudization_rate') }}</p><p class="font-black text-base text-indigo-800">{{ total.ratio }}%</p></div>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ $t('hr2_hr_reports_saudization.a_saudi_counts_fully_if_their_wa', { n0: money(thresholds.full), n1: money(thresholds.half) }) }}<span v-if="total.below_half"> {{ $t('hr2_hr_reports_saudization.n_saudi_employees_not_counted_be', { n0: total.below_half }) }}</span>.</p>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[640px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="p-2 text-start">{{ $t('hr_reports_saudization.branch') }}</th><th class="p-2 text-start">{{ $t('hr_reports_saudization.section') }}</th><th class="p-2">{{ $t('hr_reports_saudization.employees') }}</th><th class="p-2">{{ $t('hr_reports_saudization.saudis') }}</th><th class="p-2">{{ $t('hr_reports_saudization.counted') }}</th><th class="p-2">{{ $t('hr_reports_saudization.non_saudis') }}</th><th class="p-2 text-end">{{ $t('hr_reports_saudization.ratio') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="g in groups" :key="g.branch + g.department">
                        <td class="p-2">{{ g.branch }}</td><td class="p-2">{{ g.department }}</td><td class="p-2 text-center">{{ g.headcount }}</td><td class="p-2 text-center">{{ g.saudis }}</td>
                        <td class="p-2 text-center">{{ g.counted }}</td><td class="p-2 text-center">{{ g.non_saudis }}</td><td class="p-2 text-end font-bold">{{ g.ratio }}%</td>
                    </tr>
                    <tr v-if="!groups.length"><td colspan="7" class="p-6 text-center text-slate-400">{{ $t('hr_reports_saudization.no_active_employees_on_this_date') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
