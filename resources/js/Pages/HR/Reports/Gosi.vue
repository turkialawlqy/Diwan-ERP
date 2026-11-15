<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';

const props = defineProps({ rows: Array, totals: Object, ledger: Number, difference: Number, missing_numbers: Number, filters: Object, establishment: String });
const filters = reactive({ ...props.filters });
const reload = () => router.get(route('hr.reports.gosi'), filters, { preserveState: true, preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_reports_gosi.monthly_social_insurance_report')" />
        <PageHeader :title="$t('hr_reports_gosi.social_insurance_contributions_r')" :subtitle="establishment ? $t('hr2_hr_reports_gosi.company_insurance_number_n', { n0: establishment }) : $t('hr2_hr_reports_gosi.from_the_month_s_approved_and_pa')" :crumbs="[{ label: $t('hr2_hr_reports_gosi.human_resources') }, { label: $t('hr2_hr_reports_gosi.social_insurance_report') }]">
            <template #actions>
                <DatePicker v-model="filters.month" type="month" @change="reload" class="rounded-xl border-slate-200 text-xs" />
                <a v-if="$can('hr.reports.export')" :href="route('hr.reports.gosi', { month: filters.month, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_reports_gosi.export_csv') }}</a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_gosi.insurable_wages') }}</p><p class="font-black font-mono text-base">{{ money(totals.base) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_gosi.employees_share') }}</p><p class="font-black font-mono text-base">{{ money(totals.employee) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('hr_reports_gosi.company_share') }}</p><p class="font-black font-mono text-base">{{ money(totals.employer) }}</p></div>
            <div class="bg-white border rounded-2xl p-3" :class="difference === 0 ? 'border-emerald-200' : 'border-rose-300'">
                <p class="text-slate-500">{{ $t('hr_reports_gosi.due_to_social_insurance') }}</p><p class="font-black font-mono text-base">{{ money(totals.total) }}</p>
                <p class="mt-1" :class="difference === 0 ? 'text-emerald-700' : 'text-rose-600'">{{ difference === 0 ? $t('hr2_hr_reports_gosi.matches_the_accrual_entry_in_the') : $t('hr2_hr_reports_gosi.differs_from_the_entry_n_by_n', { n0: money(ledger), n1: money(difference) }) }}</p>
            </div>
        </div>
        <p v-if="missing_numbers" class="mb-3 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800">{{ $t('hr2_hr_reports_gosi.n_employees_have_no_insurance_su', { n0: missing_numbers }) }}</p>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[760px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="p-2 text-start">{{ $t('hr_reports_gosi.subscription_number') }}</th><th class="p-2 text-start">{{ $t('hr_reports_gosi.id') }}</th><th class="p-2 text-start">{{ $t('hr_reports_gosi.employee') }}</th><th class="p-2">{{ $t('hr_reports_gosi.nationality') }}</th><th class="p-2 text-end">{{ $t('hr_reports_gosi.insurable_wage') }}</th><th class="p-2 text-end">{{ $t('hr_reports_gosi.employee_share') }}</th><th class="p-2 text-end">{{ $t('hr_reports_gosi.company_share') }}</th><th class="p-2 text-end">{{ $t('hr_reports_gosi.total') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.employee_id">
                        <td class="p-2 font-mono">{{ r.gosi_number || '—' }}</td><td class="p-2 font-mono">{{ r.national_id }}</td><td class="p-2">{{ r.name }}</td><td class="p-2 text-center">{{ r.nationality }}</td>
                        <td class="p-2 text-end font-mono">{{ money(r.base) }}</td><td class="p-2 text-end font-mono">{{ money(r.employee) }}</td><td class="p-2 text-end font-mono">{{ money(r.employer) }}</td><td class="p-2 text-end font-mono font-bold">{{ money(r.total) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8" class="p-6 text-center text-slate-400">{{ $t('hr_reports_gosi.no_approved_payroll_runs_for_thi') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
