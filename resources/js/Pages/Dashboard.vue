<script setup>
import { cur } from '@/utils/tax';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import QuickActionButton from '@/Components/QuickActionButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ModuleWidget from '@/Components/Dashboard/ModuleWidget.vue';
import {
    DocumentPlusIcon,
    UserPlusIcon,
    CubeIcon,
    DeviceTabletIcon,
    BanknotesIcon,
    ArrowTrendingUpIcon,
    DocumentTextIcon,
    BuildingStorefrontIcon,
    CalendarIcon,
    ArrowLeftIcon,
    PlusIcon,
    ReceiptPercentIcon,
    ClockIcon
} from '@heroicons/vue/24/outline';
import { timeZone } from '@/utils/format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Open while setup is under way; folded away once most steps are done.
const checklistOpen = ref(true);

const props = defineProps({
    kpis: Object,
    recentInvoices: Array,
    topProducts: Array,
    salesChart: Object,
    cashOutlook: { type: Object, default: null },
    yearData: { type: Object, default: null },
    projects: { type: Array, default: () => [] },
    activeYearName: { type: String, default: null },
    onboarding: { type: Object, default: null },
    widgets: { type: Array, default: () => [] },
});

const shownWidgets = computed(() => props.widgets.filter((w) => !w.hidden));
const customizing = ref(false);
const toggleWidget = (w) => router.post(route('dashboard.widgets'), { key: w.key, hidden: !w.hidden }, { preserveScroll: true });

const page = usePage();
const user = computed(() => page.props.auth?.user);
const tenant = computed(() => page.props.tenant);

// Current date formatted in Arabic
const currentDate = computed(() => {
    return new Date().toLocaleDateString('ar-SA-u-nu-latn', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: timeZone()
    });
});

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
const totalRevenue = computed(() => (props.yearData?.revenue || []).reduce((a, b) => a + b, 0));
const totalExpense = computed(() => (props.yearData?.expense || []).reduce((a, b) => a + b, 0));
const branchSwitcher = computed(() => page.props.branchSwitcher);
const branchName = computed(() => {
    const sw = branchSwitcher.value;
    if (!sw || sw.items.length < 2) return null;
    return sw.active === 'all' ? t('dash.all_branches') : sw.items.find((b) => b.id === sw.active)?.name;
});

// Revenue against expenses, month by month of the fiscal year.
const flowSeries = computed(() => [
    { name: t('dash.revenue'), data: props.yearData?.revenue || [] },
    { name: t('dash.expenses'), data: props.yearData?.expense || [] },
]);
const flowOptions = computed(() => ({
    chart: { type: 'area', height: 290, fontFamily: 'Cairo, sans-serif', toolbar: { show: false } },
    colors: ['#0d9488', '#f43f5e'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: [2.5, 2], dashArray: [0, 5] },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 90, 100] } },
    xaxis: { categories: props.yearData?.labels || [], labels: { style: { colors: '#64748B', fontSize: '11px', fontFamily: 'Cairo' } } },
    yaxis: { labels: { formatter: (v) => `${fmt(v)}`, style: { colors: '#64748B', fontSize: '10px', fontFamily: 'Cairo' } } },
    grid: { borderColor: '#f1f5f9' },
    tooltip: { theme: 'light', y: { formatter: (v) => `${fmt(v)} ${cur()}` } },
    legend: { position: 'top', horizontalAlign: 'right', fontFamily: 'Cairo', fontSize: '12px' },
}));

// Where the money went: purchases, running expenses and salaries.
const costSeries = computed(() => [props.yearData?.purchases || 0, props.yearData?.expenses || 0, props.yearData?.payroll || 0]);
const costTotal = computed(() => costSeries.value.reduce((a, b) => a + b, 0));
const costOptions = computed(() => ({
    chart: { type: 'donut', fontFamily: 'Cairo, sans-serif' },
    labels: [t('dash.cost_purchases'), t('dash.cost_operating'), t('dash.cost_payroll')],
    colors: ['#0d9488', '#0284c7', '#d97706'],
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: { pie: { donut: { size: '72%' } } },
    legend: { position: 'bottom', fontFamily: 'Cairo', fontSize: '11px' },
    tooltip: { y: { formatter: (v) => `${fmt(v)} ${cur()}` } },
}));
</script>

<template>
    <AppLayout>
        <Head :title="$t('dash.main_dashboard')" />

        <!-- Welcome banner: summary of the day and the quick actions -->
        <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-navy-850 via-slate-900 to-brand-900 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden" data-welcome>
            <div class="relative z-10">
                <div class="flex items-center gap-2 text-brand-100 text-xs font-semibold mb-2">
                    <span class="bg-white/10 px-2.5 py-0.5 rounded-full border border-white/10">{{ $t('dash.financial_and_operating_performa') }}</span>
                    <span>•</span>
                    <span class="inline-flex items-center gap-1"><CalendarIcon class="w-4 h-4" /> {{ currentDate }}</span>
                </div>
                <h1 class="text-2xl font-bold tracking-tight">{{ $t('dash.welcome', { name: user?.name }) }}</h1>
                <p class="text-slate-300 text-sm mt-1 max-w-xl">
                    <template v-if="yearData?.pending">{{ $t('dash.you_have') }} <span class="text-amber-400 font-bold">{{ $t('dash.unpaid_invoices', { n: yearData.pending }) }}</span><template v-if="yearData.overdue"> {{ $t('dash.of_which') }} <span class="text-rose-300 font-bold">{{ $t('dash.overdue_n', { n: yearData.overdue }) }}</span></template>{{ $t('dash.text') }} </template>
                    {{ $t('dash.and_this_month_s_sales') }} <span class="text-emerald-400 font-bold">{{ kpis.month_sales }} {{ $cur() }}</span>
                    <template v-if="branchName">{{ $t('dash.in_branch', { name: branchName }) }}</template>.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5 relative z-10 shrink-0">
                <Link v-if="$canRoute('accounting.journals.index')" :href="route('accounting.journals.index')"
                    class="px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold transition shadow-md shadow-brand-900/30 inline-flex items-center gap-2">
                    <PlusIcon class="w-4 h-4" /> {{ $t('dash.journal_entry') }}
                </Link>
                <Link v-if="$canRoute('sales.invoices.create')" :href="route('sales.invoices.create')"
                    class="px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-semibold transition inline-flex items-center gap-2">
                    <DocumentTextIcon class="w-4 h-4" /> {{ $t('dash.new_invoice') }}
                </Link>
                <Link v-if="$canRoute('pos.index')" :href="route('pos.index')"
                    class="px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-semibold transition inline-flex items-center gap-2">
                    <DeviceTabletIcon class="w-4 h-4" /> {{ $t('dash.point_of_sale') }}
                </Link>
            </div>
        </div>

        <!-- 1. KPI cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <KpiCard :title="$t('dash.fiscal_year_revenue')" :value="fmt(totalRevenue)" :unit="$cur()" color="emerald">
                <template #icon><BanknotesIcon class="w-5 h-5" /></template>
            </KpiCard>
            <KpiCard :title="$t('dash.expenses_and_purchases')" :value="fmt(totalExpense)" :unit="$cur()" color="rose">
                <template #icon><ReceiptPercentIcon class="w-5 h-5" /></template>
            </KpiCard>
            <KpiCard :title="$t('dash.sales_this_month')" :value="kpis.month_sales" :unit="$cur()" :trend="kpis.month_sales_trend" :trendText="$t('dash.vs_last_month')" color="blue">
                <template #icon><ArrowTrendingUpIcon class="w-5 h-5" /></template>
            </KpiCard>
            <KpiCard :title="$t('dash.amounts_due_credit')" :value="kpis.receivables" :unit="$cur()" color="amber">
                <template #icon><ClockIcon class="w-5 h-5" /></template>
            </KpiCard>
        </div>

        <!-- Enabled systems: one small card each (hide / show per user) -->
        <section v-if="widgets.length" class="mb-6" data-module-widgets>
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-black text-slate-700">{{ $t('dash.enabled_systems') }}</h2>
                <button type="button" class="text-xs font-bold text-slate-500 hover:text-indigo-600" data-customize @click="customizing = !customizing">{{ $t('dash.customize_dashboard') }}</button>
            </div>
            <div v-if="customizing" class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap gap-3 text-xs" data-customize-panel>
                <label v-for="w in widgets" :key="w.key" class="inline-flex items-center gap-1.5"><input type="checkbox" :checked="!w.hidden" :data-toggle="w.key" @change="toggleWidget(w)" /> {{ w.title }}</label>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-4">
                <ModuleWidget v-for="w in shownWidgets" :key="w.key" :widget="w" />
            </div>
        </section>

        <!-- First-run setup checklist (owner): below the day's figures, collapsible -->
        <div v-if="onboarding" class="mb-6 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-xs" data-onboarding>
            <button type="button" class="w-full flex items-center justify-between gap-4" @click="checklistOpen = !checklistOpen">
                <h2 class="font-black text-slate-800 text-sm">{{ $t('dash.setup_title', { done: onboarding.done, total: onboarding.total }) }}</h2>
                <span class="flex items-center gap-3">
                    <span class="w-40 h-2 bg-slate-100 rounded-full overflow-hidden hidden sm:block"><span class="block h-full bg-brand-600" :style="{ width: (onboarding.done / onboarding.total * 100) + '%' }"></span></span>
                    <span class="text-xs text-slate-400">{{ checklistOpen ? $t('dash.hide') : $t('dash.show') }}</span>
                </span>
            </button>
            <ul v-show="checklistOpen" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 text-xs mt-3">
                <li v-for="s in onboarding.steps" :key="s.key">
                    <Link :href="s.url" class="flex items-center gap-2 p-2 rounded-xl border" :class="s.done ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 hover:border-brand-300 text-slate-700'">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-3xs font-black" :class="s.done ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500'">{{ s.done ? '✓' : '' }}</span>
                        <span :class="s.done ? 'line-through' : 'font-bold'">{{ s.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <!-- 2. Charts: revenue vs expenses over the fiscal year, and the cost split -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-bold text-base text-slate-900">{{ $t('dash.cash_flows_and_financial_perform') }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ activeYearName ? $t('dash.rev_vs_exp_year', { year: activeYearName }) : $t('dash.rev_vs_exp') }}</p>
                    </div>
                </div>
                <div class="min-h-[290px]" data-cashflow-chart>
                    <apexchart type="area" height="290" :options="flowOptions" :series="flowSeries" />
                </div>
            </div>

            <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs flex flex-col justify-between">
                <div>
                    <h2 class="font-bold text-base text-slate-900">{{ $t('dash.cost_breakdown') }}</h2>
                    <p class="text-xs text-slate-400 mt-0.5 mb-3">{{ $t('dash.where_the_money_went_this_fiscal') }}</p>
                    <div v-if="costTotal > 0" class="min-h-[230px]" data-cost-chart>
                        <apexchart type="donut" height="230" :options="costOptions" :series="costSeries" />
                    </div>
                    <p v-else class="text-xs text-slate-400 py-16 text-center">{{ $t('dash.no_expenses_recorded_this_year_y') }}</p>
                </div>
            </div>
        </div>

        <!-- 3. Contracts and quick actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div v-if="projects?.length" class="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs" data-projects>
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="font-bold text-base text-slate-900">{{ $t('dash.active_projects_and_contracts') }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $t('dash.billed_of_the_contract_value') }}</p>
                    </div>
                    <Link :href="route('projects.index')" class="text-xs text-brand-600 hover:text-brand-800 font-bold inline-flex items-center gap-1">{{ $t('dash.view_all') }} <ArrowLeftIcon class="w-3.5 h-3.5" /></Link>
                </div>
                <div class="space-y-4">
                    <Link v-for="p in projects" :key="p.id" :href="route('projects.show', p.id)" class="block p-4 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-slate-50 transition-colors">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-700 flex items-center justify-center font-bold text-[10px] shrink-0">{{ p.code }}</div>
                                <div class="min-w-0">
                                    <h3 class="font-bold text-sm text-slate-800 truncate">{{ p.name }}</h3>
                                    <span class="text-[11px] text-slate-500">{{ p.customer || '—' }}</span>
                                </div>
                            </div>
                            <div class="text-end shrink-0 text-xs">
                                <span class="text-slate-400 block text-[10px]">{{ $t('dash.contract_value') }}</span>
                                <span class="font-bold text-slate-700">{{ p.value }} {{ $cur() }}</span>
                            </div>
                        </div>
                        <div class="flex justify-between text-xs text-slate-500 font-medium mb-1.5"><span>{{ $t('dash.billed') }}</span><span class="text-brand-700 font-bold">{{ p.billed_percent }}%</span></div>
                        <div class="w-full bg-slate-200 h-2.5 rounded-full overflow-hidden"><div class="bg-brand-600 h-full rounded-full transition-all duration-700" :style="{ width: p.billed_percent + '%' }"></div></div>
                    </Link>
                </div>
            </div>

            <div :class="projects?.length ? '' : 'lg:col-span-3'" class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs">
                <h2 class="font-bold text-base text-slate-900 mb-1">{{ $t('dash.quick_actions') }}</h2>
                <p class="text-xs text-slate-400 mb-4">{{ $t('dash.create_documents_directly') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <Link v-if="$canRoute('sales.invoices.create')" :href="route('sales.invoices.create')" class="p-3.5 rounded-xl border border-slate-200 hover:border-brand-500/50 hover:bg-brand-50/50 transition-all group flex flex-col justify-between h-24">
                        <DocumentPlusIcon class="w-5 h-5 text-brand-600 group-hover:scale-110 transition-transform" />
                        <div><span class="block text-xs font-bold text-slate-800">{{ $t('dash.sales_invoice') }}</span><span class="text-[10px] text-slate-400">{{ $t('dash.for_the_current_branch') }}</span></div>
                    </Link>
                    <Link v-if="$canRoute('sales.clients.create')" :href="route('sales.clients.create')" class="p-3.5 rounded-xl border border-slate-200 hover:border-brand-500/50 hover:bg-brand-50/50 transition-all group flex flex-col justify-between h-24">
                        <UserPlusIcon class="w-5 h-5 text-sky-600 group-hover:scale-110 transition-transform" />
                        <div><span class="block text-xs font-bold text-slate-800">{{ $t('dash.new_customer') }}</span><span class="text-[10px] text-slate-400">{{ $t('dash.record_his_details') }}</span></div>
                    </Link>
                    <Link v-if="$canRoute('inventory.products.create')" :href="route('inventory.products.create')" class="p-3.5 rounded-xl border border-slate-200 hover:border-brand-500/50 hover:bg-brand-50/50 transition-all group flex flex-col justify-between h-24">
                        <CubeIcon class="w-5 h-5 text-amber-600 group-hover:scale-110 transition-transform" />
                        <div><span class="block text-xs font-bold text-slate-800">{{ $t('dash.new_item') }}</span><span class="text-[10px] text-slate-400">{{ $t('dash.cost_and_stock') }}</span></div>
                    </Link>
                    <Link v-if="$canRoute('pos.index')" :href="route('pos.index')" class="p-3.5 rounded-xl border border-slate-200 hover:border-brand-500/50 hover:bg-brand-50/50 transition-all group flex flex-col justify-between h-24">
                        <DeviceTabletIcon class="w-5 h-5 text-emerald-600 group-hover:scale-110 transition-transform" />
                        <div><span class="block text-xs font-bold text-slate-800">{{ $t('dash.point_of_sale') }}</span><span class="text-[10px] text-slate-400">{{ $t('dash.quick_cashier') }}</span></div>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Cash outlook (from the insights dashboards) -->
        <Link v-if="cashOutlook" :href="route('reports.insights')" class="mb-6 grid grid-cols-2 lg:grid-cols-4 gap-3 bg-white border border-slate-200 rounded-2xl p-4 text-xs hover:border-indigo-300">
            <div><div class="text-slate-500">{{ $t('dash.cash_today') }}</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.now).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">{{ $t('dash.expected_in_30_days') }}</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.in30).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">{{ $t('dash.lowest_expected_balance') }}</div><div class="text-base font-black font-mono mt-1" :class="cashOutlook.lowest < 0 ? 'text-rose-700' : ''">{{ Number(cashOutlook.lowest).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">{{ $t('dash.overdue_customer_receivables') }}</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.overdue).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
        </Link>

        <!-- 4. Bottom Grid: Recent Invoices & Top Products. Side by side only from xl: at lg the
             expanded sidebar leaves the products column about 200px, too narrow for a name and an amount. -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- Recent Invoices (2 Cols) -->
            <div class="xl:col-span-2 bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden flex flex-col">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">{{ $t('dash.latest_sales_invoices') }}</h2>
                        <p class="text-[11px] text-slate-400">{{ $t('dash.latest_e_invoices_issued') }}</p>
                    </div>
                    <Link
                        :href="route('sales.invoices.index')"
                        class="inline-flex items-center gap-1 text-xs font-bold text-indigo-600 hover:text-indigo-800 transition"
                    >
                        <span>{{ $t('dash.view_all') }}</span>
                        <ArrowLeftIcon class="w-3.5 h-3.5" />
                    </Link>
                </div>

                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-start text-xs">
                        <thead class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                            <tr>
                                <th class="px-5 py-3 text-start">{{ $t('dash.invoice_number') }}</th>
                                <th class="px-5 py-3 text-start">{{ $t('dash.customer') }}</th>
                                <th class="px-5 py-3 text-start">{{ $t('dash.date') }}</th>
                                <th class="px-5 py-3 text-start">{{ $t('dash.total') }}</th>
                                <th class="px-5 py-3 text-center">{{ $t('dash.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="inv in recentInvoices" 
                                :key="inv.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="px-5 py-3.5 font-bold text-indigo-600 font-mono">
                                    {{ inv.number }}
                                </td>
                                <td class="px-5 py-3.5 font-semibold text-slate-800">
                                    {{ inv.customer_name }}
                                </td>
                                <td class="px-5 py-3.5 text-slate-500 font-mono text-[11px]" dir="ltr">
                                    {{ inv.date }}
                                </td>
                                <td class="px-5 py-3.5 font-bold text-slate-900 font-sans">
                                    {{ inv.total }} {{ $cur() }}
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <StatusBadge :status="inv.status" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Selling Products (1 Col) -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 flex flex-col">
                <div class="mb-4">
                    <h2 class="text-sm font-bold text-slate-900">{{ $t('dash.best_selling_products') }}</h2>
                    <p class="text-[11px] text-slate-400">{{ $t('dash.items_with_the_highest_demand_an') }}</p>
                </div>

                <div class="space-y-3 flex-1">
                    <div 
                        v-for="(product, idx) in topProducts" 
                        :key="idx"
                        class="p-3 rounded-2xl bg-slate-50/70 hover:bg-slate-50 border border-slate-100 flex items-center justify-between gap-3 transition"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 shrink-0 rounded-xl bg-white border border-slate-200 flex items-center justify-center font-bold text-xs text-indigo-600 shadow-xs">
                                {{ idx + 1 }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-800 truncate" :title="product.name">{{ product.name }}</p>
                                <span class="block text-[10px] text-slate-400 truncate">{{ product.category }}</span>
                            </div>
                        </div>

                        <div class="text-end shrink-0 whitespace-nowrap">
                            <p class="text-xs font-bold text-emerald-700 font-sans">{{ product.revenue }} {{ $cur() }}</p>
                            <span class="text-[10px] text-slate-500">{{ $t('dash.units', { n: product.sold_units }) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
