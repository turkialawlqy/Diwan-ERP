<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { cur } from '@/utils/tax';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { money } from '@/utils/format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

/**
 * Management dashboards: cash forecast, aging, profitability, budget.
 * Charts use palette slots 1 (blue) and 2 (orange), validated for CVD; every
 * chart also has its numbers in a table.
 */
const props = defineProps({ filters: Object, cash: Object, aging: Object, profitability: Object, budget: Object });

const tabs = computed(() => [
    { id: 'cash', label: t('ins.tab_cash') },
    { id: 'aging', label: t('ins.tab_aging') },
    { id: 'profit', label: t('ins.tab_profit') },
    { id: 'budget', label: t('ins.tab_budget') },
]);
const tab = ref('cash');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const scenario = ref({ collect_delay_days: props.filters.collect_delay_days, collect_pct: props.filters.collect_pct, pay_delay_days: props.filters.pay_delay_days });
const applyScenario = () => router.get(route('reports.insights'), { from: from.value, to: to.value, ...scenario.value }, { preserveState: true, preserveScroll: true });
const applyPeriod = () => router.get(route('reports.insights'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });

const BLUE = '#2a78d6';
const ORANGE = '#eb6834';
const weekLabel = (w) => w.start.substring(5).replace('-', '/');
const labels = computed(() => props.cash.weeks.map(weekLabel));

const baseOptions = {
    chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', animations: { enabled: false } },
    dataLabels: { enabled: false },
    grid: { borderColor: '#eef0f3', strokeDashArray: 0, yaxis: { lines: { show: true } }, xaxis: { lines: { show: false } } },
    xaxis: { categories: [], labels: { rotate: -45, rotateAlways: true, hideOverlappingLabels: true, style: { colors: '#64748b', fontSize: '10px' } }, axisBorder: { color: '#e2e8f0' }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: '#64748b', fontSize: '11px' }, formatter: (v) => Math.round(v).toLocaleString('en-US') } },
    tooltip: { shared: true, intersect: false, y: { formatter: (v) => `${money(v)} ${cur()}` } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px', markers: { size: 6 } },
};

const balanceChart = computed(() => ({
    series: [{ name: t('ins.cash_balance'), data: props.cash.weeks.map((w) => w.balance) }],
    options: { ...baseOptions, colors: [BLUE], stroke: { width: 2, curve: 'straight' }, markers: { size: 0, hover: { size: 6 } }, legend: { show: false },
        xaxis: { ...baseOptions.xaxis, categories: labels.value }, annotations: { yaxis: [{ y: 0, borderColor: '#94a3b8' }] } },
}));

const flowChart = computed(() => ({
    series: [
        { name: t('ins.receipts'), data: props.cash.weeks.map((w) => w.inflow) },
        { name: t('ins.payments'), data: props.cash.weeks.map((w) => w.outflow) },
    ],
    options: { ...baseOptions, colors: [BLUE, ORANGE], plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
        stroke: { show: true, width: 2, colors: ['transparent'] }, xaxis: { ...baseOptions.xaxis, categories: labels.value } },
}));

const buckets = computed(() => [['current', t('ins.not_due')], ['d30', t('ins.d1_30')], ['d60', t('ins.d31_60')], ['d90', t('ins.d61_90')], ['d90plus', t('ins.over_90')]]);
const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
const varianceClass = (v) => (v < 0 ? 'text-rose-700' : 'text-emerald-700');
</script>

<template>
    <AppLayout>
        <Head :title="$t('ins.dashboards_and_analytics')" />
        <PageHeader :title="$t('ins.dashboards_and_analytics')" :subtitle="$t('ins.all_amounts_are_in_the_company_b')" :crumbs="[{ label: $t('ins.reports') }, { label: $t('ins.dashboards') }]" />

        <div class="flex flex-wrap items-end justify-between gap-3 mb-4 text-xs">
            <div class="flex flex-wrap gap-1 bg-white border border-slate-200 rounded-xl p-1">
                <button v-for="t in tabs" :key="t.id" type="button" @click="tab = t.id" :class="['px-3 py-2 rounded-lg font-bold', tab === t.id ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50']">{{ t.label }}</button>
            </div>
            <div v-if="tab === 'profit'" class="flex items-end gap-2">
                <label class="block">{{ $t('ins.from') }}<DatePicker v-model="from" class="block rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">{{ $t('ins.to') }}<DatePicker v-model="to" class="block rounded-xl border-slate-200 text-xs" /></label>
                <button @click="applyPeriod" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">{{ $t('ins.apply') }}</button>
            </div>
        </div>

        <!-- Cash forecast -->
        <section v-show="tab === 'cash'" class="space-y-4 text-xs">
            <form @submit.prevent="applyScenario" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-wrap items-end gap-3" data-cash-scenario>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.collect_delay') }}</span><input v-model="scenario.collect_delay_days" type="text" inputmode="decimal" data-num class="rounded-xl border-slate-200 text-xs w-28" /></label>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.collect_pct') }}</span><input v-model="scenario.collect_pct" type="text" inputmode="decimal" data-num class="rounded-xl border-slate-200 text-xs w-28" /></label>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.pay_delay') }}</span><input v-model="scenario.pay_delay_days" type="text" inputmode="decimal" data-num class="rounded-xl border-slate-200 text-xs w-28" /></label>
                <button class="rounded-xl bg-indigo-600 text-white font-bold px-5 py-2">{{ $t('aging3.recalc') }}</button>
                <ExportButtons report="cash-forecast" :params="{ collect_delay_days: filters.collect_delay_days, collect_pct: filters.collect_pct, pay_delay_days: filters.pay_delay_days }" permission="accounting.controls.export" />
                <span v-if="filters.collect_delay_days || filters.collect_pct < 100 || filters.pay_delay_days" class="font-bold text-amber-700">{{ $t('aging3.scenario_active') }}</span>
            </form>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-slate-500">{{ $t('ins.cash_today') }}</div><div class="text-xl font-black mt-1 font-mono">{{ money(cash.opening) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-slate-500">{{ $t('ins.expected_after', { n: cash.weeks.length }) }}</div><div class="text-xl font-black mt-1 font-mono">{{ money(cash.closing) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('ins.lowest_expected_balance') }}</div>
                    <div class="text-xl font-black mt-1 font-mono" :class="cash.lowest < 0 ? 'text-rose-700' : ''">{{ money(cash.lowest) }}</div>
                    <div v-if="cash.lowest < 0" class="text-rose-700 font-bold mt-1">{{ $t('ins.expected_shortfall_review_collec') }}</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('ins.overdue_receivables_payables_out') }}</div>
                    <div class="text-sm font-bold mt-1">{{ $t('ins.customers') }} <span class="font-mono">{{ money(cash.overdue.receivables) }}</span></div>
                    <div class="text-sm font-bold">{{ $t('ins.suppliers') }} <span class="font-mono">{{ money(cash.overdue.payables) }}</span></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-1">{{ $t('ins.expected_cash_balance_by_week') }}</h3>
                    <apexchart type="line" height="260" :options="balanceChart.options" :series="balanceChart.series" />
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-1">{{ $t('ins.expected_receipts_and_payments') }}</h3>
                    <apexchart type="bar" height="260" :options="flowChart.options" :series="flowChart.series" />
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('ins.week') }}</th><th class="px-3 py-2">{{ $t('ins.invoice_collections') }}</th><th class="px-3 py-2">{{ $t('ins.recurring_invoices') }}</th><th class="px-3 py-2">{{ $t('ins.incoming_cheques') }}</th><th class="px-3 py-2">{{ $t('ins.suppliers_2') }}</th><th class="px-3 py-2">{{ $t('ins.outgoing_cheques') }}</th><th class="px-3 py-2">{{ $t('ins.payroll') }}</th><th class="px-3 py-2">{{ $t('ins.net') }}</th><th class="px-3 py-2">{{ $t('ins.balance') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="w in cash.weeks" :key="w.start" class="border-t border-slate-100 font-mono text-center">
                            <td class="px-3 py-2 text-start">{{ w.start }} — {{ w.end.substring(5) }}</td>
                            <td class="px-3 py-2">{{ money(w.receivables) }}</td><td class="px-3 py-2">{{ money(w.recurring) }}</td><td class="px-3 py-2">{{ money(w.cheques_in) }}</td>
                            <td class="px-3 py-2">{{ money(w.payables) }}</td><td class="px-3 py-2">{{ money(w.cheques_out) }}</td><td class="px-3 py-2">{{ money(w.payroll) }}</td>
                            <td class="px-3 py-2" :class="w.net < 0 ? 'text-rose-700' : ''">{{ money(w.net) }}</td>
                            <td class="px-3 py-2 font-bold" :class="w.balance < 0 ? 'text-rose-700' : ''">{{ money(w.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-slate-500">{{ $t('ins.forecast_note', { payroll: cash.payroll_estimate ? ` (${money(cash.payroll_estimate)})` : '' }) }}</p>
        </section>

        <!-- Aging -->
        <section v-show="tab === 'aging'" class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div v-for="(label, kind) in { receivables: $t('ins.customer_receivable_aging'), payables: $t('ins.supplier_payable_aging') }" :key="kind" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ label }}</h3>
                <table class="w-full">
                    <tbody>
                        <tr v-for="[key, name] in buckets" :key="key" class="border-t border-slate-100">
                            <td class="px-4 py-2">{{ name }}</td>
                            <td class="px-4 py-2 w-1/2">
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :style="{ width: (aging[kind].totals.total ? (aging[kind].totals[key] / aging[kind].totals.total * 100) : 0) + '%', background: key === 'current' ? BLUE : ORANGE }"></div></div>
                            </td>
                            <td class="px-4 py-2 font-mono text-end">{{ money(aging[kind].totals[key]) }}</td>
                        </tr>
                        <tr class="border-t border-slate-200 font-black"><td class="px-4 py-2">{{ $t('ins.total') }}</td><td></td><td class="px-4 py-2 font-mono text-end">{{ money(aging[kind].totals.total) }}</td></tr>
                    </tbody>
                </table>
                <h4 class="px-4 pt-3 pb-1 font-bold text-slate-600">{{ $t('ins.most_overdue') }}</h4>
                <div class="overflow-x-auto"><table class="w-full">
                    <tbody>
                        <tr v-for="r in aging[kind].top" :key="r.id ?? `none`" class="border-t border-slate-100">
                            <td class="px-4 py-2 font-bold">{{ r.name }}</td>
                            <td class="px-4 py-2 font-mono text-rose-700">{{ money(r.overdue) }}</td>
                            <td class="px-4 py-2 text-end"><ShareDocument v-if="kind === 'receivables' && r.id && $can('sales.clients.view')" type="statement" :id="r.id" /></td>
                        </tr>
                        <tr v-if="!aging[kind].top.length"><td class="px-4 py-4 text-center text-slate-400">{{ $t('ins.no_overdue_amounts') }}</td></tr>
                    </tbody>
                </table></div>
            </div>
        </section>

        <!-- Profitability -->
        <section v-show="tab === 'profit'" class="space-y-4 text-xs">
            <div v-for="(block, key) in { products: $t('ins.item_profitability'), customers: $t('ins.customer_profitability') }" :key="key" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ block }} <span class="font-normal text-slate-400">({{ filters.from }} — {{ filters.to }})</span></h3>
                <table class="w-full min-w-[640px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ key === 'products' ? $t('ins.item') : $t('ins.customer') }}</th><th class="px-3 py-2">{{ key === 'products' ? $t('ins.quantity') : $t('ins.invoices') }}</th><th class="px-3 py-2">{{ $t('ins.sales_before_tax') }}</th><th class="px-3 py-2">{{ $t('ins.cost') }}</th><th class="px-3 py-2">{{ $t('ins.gross_profit') }}</th><th class="px-3 py-2">{{ $t('ins.margin') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in profitability[key]" :key="r.id" class="border-t border-slate-100 text-center font-mono">
                            <td class="px-3 py-2 text-start font-sans font-bold">{{ r.name }}</td>
                            <td class="px-3 py-2">{{ key === 'products' ? r.qty : r.invoices }}</td>
                            <td class="px-3 py-2">{{ money(r.revenue) }}</td><td class="px-3 py-2">{{ money(r.cost) }}</td>
                            <td class="px-3 py-2" :class="r.margin < 0 ? 'text-rose-700' : ''">{{ money(r.margin) }}</td>
                            <td class="px-3 py-2">{{ pct(r.margin_pct) }}</td>
                        </tr>
                        <tr v-if="!profitability[key].length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">{{ $t('ins.no_sales_in_the_period') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ $t('ins.profitability_of_running_project') }}</h3>
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('ins.project') }}</th><th class="px-3 py-2">{{ $t('ins.contract_value') }}</th><th class="px-3 py-2">{{ $t('ins.progress') }}</th><th class="px-3 py-2">{{ $t('ins.revenue') }}</th><th class="px-3 py-2">{{ $t('ins.actual_cost') }}</th><th class="px-3 py-2">{{ $t('ins.gross_profit') }}</th><th class="px-3 py-2">{{ $t('ins.actual_margin') }}</th><th class="px-3 py-2">{{ $t('ins.planned_margin') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="p in profitability.projects" :key="p.id" class="border-t border-slate-100 text-center font-mono">
                            <td class="px-3 py-2 text-start font-sans"><Link :href="route('projects.show', p.id)" class="font-bold text-indigo-700">{{ p.code }} {{ p.name }}</Link></td>
                            <td class="px-3 py-2">{{ money(p.contract_value) }}</td><td class="px-3 py-2">{{ pct(p.progress_percent) }}</td>
                            <td class="px-3 py-2">{{ money(p.revenue) }}</td><td class="px-3 py-2">{{ money(p.actual_cost) }}</td>
                            <td class="px-3 py-2" :class="p.gross_profit < 0 ? 'text-rose-700' : ''">{{ money(p.gross_profit) }}</td>
                            <td class="px-3 py-2">{{ pct(p.margin_percent) }}</td><td class="px-3 py-2">{{ pct(p.projected_margin) }}</td>
                        </tr>
                        <tr v-if="!profitability.projects.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">{{ $t('ins.no_running_projects') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Budget -->
        <section v-show="tab === 'budget'" class="space-y-4 text-xs">
            <div v-if="!budget" class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center text-slate-500">
                {{ $t('ins.there_is_no_approved_budget_for') }}
            </div>
            <template v-else>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div v-for="(label, k) in { revenue: $t('ins.revenue_to_date'), expense: $t('ins.expenses_to_date') }" :key="k" class="bg-white border border-slate-200 rounded-2xl p-4">
                        <div class="text-slate-500">{{ label }} — {{ budget.budget.name }}</div>
                        <div class="text-xl font-black mt-1 font-mono">{{ money(budget.totals[k].actual) }} <span class="text-sm text-slate-400">/ {{ money(budget.totals[k].planned) }}</span></div>
                        <div class="h-2 mt-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :style="{ width: Math.min(100, budget.totals[k].planned ? budget.totals[k].actual / budget.totals[k].planned * 100 : 0) + '%', background: BLUE }"></div></div>
                    </div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ $t('ins.largest_unfavourable_variances') }}</h3>
                    <table class="w-full min-w-[600px]">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('ins.account') }}</th><th class="px-3 py-2">{{ $t('ins.planned') }}</th><th class="px-3 py-2">{{ $t('ins.actual') }}</th><th class="px-3 py-2">{{ $t('ins.variance') }}</th><th class="px-3 py-2">{{ $t('ins.utilisation') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="r in budget.worst" :key="r.code" class="border-t border-slate-100 text-center font-mono">
                                <td class="px-3 py-2 text-start font-sans">{{ r.code }} — {{ r.name }}</td>
                                <td class="px-3 py-2">{{ money(r.planned) }}</td><td class="px-3 py-2">{{ money(r.actual) }}</td>
                                <td class="px-3 py-2 font-bold" :class="varianceClass(r.variance)">{{ money(r.variance) }}</td><td class="px-3 py-2">{{ pct(r.used_pct) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>
    </AppLayout>
</template>
