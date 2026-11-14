<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import ComparisonTable from '@/Components/UI/ComparisonTable.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';

const props = defineProps({ report: Object, comparison: { type: Object, default: null }, filters: Object, eosCurrent: { type: Number, default: 0 } });
const compare = ref(props.filters.compare ?? '');
const ledgerQuery = { end_date: props.filters.as_of };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_balance.statement_of_financial_position')" />
        <PageHeader :title="$t('a_balance.statement_of_financial_position_2')" :subtitle="$t('a_rep.as_of', { d: filters.as_of })" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]">
            <template #actions>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button>
                <ExportButtons report="balance-sheet" :params="{ as_of: filters.as_of, compare: filters.compare }" permission="accounting.statements.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.balance-sheet" :filters="filters" :extra="{ compare: compare || undefined }" single>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('a_balance.compare_with') }}</span>
                <select v-model="compare" data-compare class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('a_balance.no_comparison') }}</option>
                    <option value="previous">{{ $t('a_balance.end_of_the_previous_month') }}</option>
                    <option value="year">{{ $t('a_balance.same_date_last_year') }}</option>
                </select>
            </label>
        </DateRangeFilter>

        <div :class="[report.is_balanced ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200', 'border rounded-xl px-4 py-2 text-xs font-bold mb-4 max-w-5xl']">
            {{ report.is_balanced ? $t('a_balance.balanced') : $t('a_balance.unbalanced') }}
        </div>

 <div v-if="!comparison && report.liquidity" class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4 text-xs max-w-5xl" data-liquidity>
            <div class="rounded-xl bg-white border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.current_assets') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.liquidity.assets.current) }}</p></div>
            <div class="rounded-xl bg-white border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.non_current_assets') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.liquidity.assets.non_current) }}</p></div>
            <div class="rounded-xl bg-white border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.current_liabilities') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.liquidity.liabilities.current) }}</p></div>
            <div class="rounded-xl bg-white border border-slate-200 p-3"><p class="text-slate-500">{{ $t('repgap.non_current_liabilities') }}</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.liquidity.liabilities.non_current) }}</p></div>
            <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-3"><p class="text-indigo-700">{{ $t('repgap.current_ratio') }}</p><p class="font-mono font-black text-indigo-800 mt-1" data-current-ratio>{{ report.liquidity.current_ratio ?? '—' }}</p></div>
        </div>
        <ComparisonTable v-if="comparison" :comparison="comparison" :current-label="$t('a_rep.as_of', { d: filters.as_of })" />
        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <StatementSection :title="$t('a_balance.assets')" :rows="report.assets.rows" :total="report.total_assets" :ledger-query="ledgerQuery" />
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <StatementSection :title="$t('a_balance.liabilities')" :rows="report.liabilities.rows" :total="report.total_liabilities" :ledger-query="ledgerQuery" />
                <p v-if="eosCurrent > 0" class="text-3xs text-slate-500 px-1">{{ $t('a_balance.of_which_a_current_portion_of_th') }} <span class="font-mono font-bold">{{ amount(eosCurrent) }}</span></p>
                <StatementSection :title="$t('a_balance.equity')" :rows="[...report.equity.rows, { name: $t('a_balance.unclosed'), amount: report.unclosed_profit }]" :total="report.total_equity" :ledger-query="ledgerQuery" />
                <div class="flex justify-between px-4 py-2 rounded-xl bg-slate-100 font-black text-xs">
                    <span>{{ $t('a_balance.total_liabilities_and_equity') }}</span>
                    <span class="font-mono">{{ amount(report.total_liabilities_equity) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
