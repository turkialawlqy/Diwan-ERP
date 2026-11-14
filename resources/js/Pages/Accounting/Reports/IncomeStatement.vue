<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import ComparisonTable from '@/Components/UI/ComparisonTable.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';

const props = defineProps({ report: Object, comparison: { type: Object, default: null }, costCenters: Array, filters: Object });
const costCenter = ref(props.filters.cost_center_id ?? '');
const compare = ref(props.filters.compare ?? '');
const printPage = () => window.print();
const pct = (part, whole) => (Number(whole) ? `${(Number(part) / Number(whole) * 100).toFixed(1)}%` : '—');
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_income.income_statement')" />
        <PageHeader :title="$t('a_income.income_statement_profit_and_loss')" :subtitle="$t('a_rep.range', { from: filters.start_date, to: filters.end_date })" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]">
            <template #actions>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button>
                <ExportButtons report="income-statement" :params="{ start_date: filters.start_date, end_date: filters.end_date, cost_center_id: filters.cost_center_id, compare: filters.compare }" permission="accounting.statements.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.income-statement" :filters="filters" :extra="{ cost_center_id: costCenter || undefined, compare: compare || undefined }">
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('a_income.compare_with') }}</span>
                <select v-model="compare" data-compare class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('a_income.no_comparison') }}</option>
                    <option value="previous">{{ $t('a_income.previous_period') }}</option>
                    <option value="year">{{ $t('a_income.same_period_last_year') }}</option>
                    <option value="budget">{{ $t('repgap.vs_budget') }}</option>
                </select>
            </label>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('a_income.cost_center') }}</span>
                <select v-model="costCenter" class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('a_income.all_centers') }}</option>
                    <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name_ar }}</option>
                </select>
            </label>
        </DateRangeFilter>

        <ComparisonTable v-if="comparison" :comparison="comparison" />
        <div v-else class="bg-white border border-slate-200 rounded-2xl p-5">
            <StatementSection :title="$t('a_income.revenue')" :rows="report.revenue.rows" :total="report.total_revenue" :ledger-query="filters" />
            <StatementSection :title="$t('a_income.expenses_and_costs')" :rows="report.expense.rows" :total="report.total_expense" :ledger-query="filters" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 text-xs">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ $t('a_income.cost_of_sales') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.cost_of_sales) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ $t('a_income.gross_profit') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.gross_profit) }}</p></div>
                <div :class="[report.net_profit >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700', 'rounded-xl p-3']">
                    <p>{{ report.net_profit >= 0 ? $t('a_rep.net_profit') : $t('a_rep.net_loss') }}</p>
                    <p class="font-mono font-black text-lg mt-1">{{ amount(report.net_profit) }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3 text-xs" data-margins>
                <div class="rounded-xl border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.gross_margin') }}</p><p class="font-mono font-black text-slate-800 mt-1" data-gross-margin>{{ pct(report.gross_profit, report.total_revenue) }}</p></div>
                <div class="rounded-xl border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.net_margin') }}</p><p class="font-mono font-black text-slate-800 mt-1" data-net-margin>{{ pct(report.net_profit, report.total_revenue) }}</p></div>
            </div>
        </div>
    </AppLayout>
</template>
