<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import BreakdownTable from '@/Components/UI/BreakdownTable.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    DocumentChartBarIcon,
    BanknotesIcon,
    ArrowTrendingUpIcon,
    ShoppingCartIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    stats: Object,
    by: { type: String, default: 'customer' },
    breakdown: { type: Object, default: () => ({ rows: [], total: { documents: 0, quantity: null, amount: 0 } }) },
});
const tabs = computed(() => ([['customer', t('rep2_reports_sales.by_customer')], ['item', t('rep2_reports_sales.by_item')], ['salesperson', t('rep2_reports_sales.by_sales_rep')], ['branch', t('rep2_reports_sales.by_branch')]]));

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('rep2_reports_sales.sales_reports')" />

        <div class="space-y-6">
            <!-- Breadcrumb Navigation -->
            <PageHeader :title="$t('rep2_reports_sales.sales_reports')" :crumbs="[{ label: $t('rep2_reports_sales.reports') }, { label: $t('rep2_reports_sales.sales') }]">
                <template #actions>
                    <ExportButtons report="sales-analysis" :params="{ start_date: filters.start_date, end_date: filters.end_date, by }" permission="reports.sales.export" />
                </template>
            </PageHeader>

            <DateRangeFilter route-name="reports.sales" :filters="filters" :extra="{ by }" />

            <!-- Live KPI Overview Banner -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_sales.total_sales') }}</span>
                        <h3 class="text-lg font-black font-mono text-indigo-600">
                            {{ formatCurrency(stats?.total_sales) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <ShoppingCartIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_sales.payments_collected') }}</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_payments_received) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $taxLabel() }}</span>
                        <h3 class="text-lg font-black font-mono text-slate-900">
                            {{ formatCurrency(stats?.total_tax) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center">
                        <DocumentChartBarIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_sales.estimated_gross_profit') }}</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            <template v-if="stats?.gross_profit === null">—</template>
                            <template v-else>{{ formatCurrency(stats?.gross_profit) }} <span class="text-xs font-normal">﷼</span></template>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <ArrowTrendingUpIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <BreakdownTable route-name="reports.sales" :tabs="tabs" :by="by" :filters="filters" :breakdown="breakdown" :name-label="by === 'item' ? $t('rep2_reports_sales.item') : by === 'salesperson' ? $t('rep2_reports_sales.sales_rep') : by === 'branch' ? $t('rep2_reports_sales.branch') : $t('rep2_reports_sales.customer')" />

            <!-- 3 Main Report Blocks (Exact Match to anas.diwan.io) -->
            <div class="space-y-4">
                <!-- 1. تقارير المبيعات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <DocumentChartBarIcon class="w-5 h-5 text-indigo-600" />
                            <h2 class="text-base font-bold text-slate-900">{{ $t('rep2_reports_sales.sales_reports') }}</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            {{ $t('rep2_reports_sales.sales_reports_show_summary_and_d') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('sales.invoices.index')"
                            class="px-5 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-xl text-xs font-bold transition"
                        >
                            {{ $t('rep2_reports_sales.summary') }}
                        </Link>
                        <Link 
                            :href="route('sales.invoices.index')"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition"
                        >
                            {{ $t('rep2_reports_sales.detailed') }}
                        </Link>
                    </div>
                </div>

                <!-- 2. تقارير المدفوعات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-emerald-600" />
                            <h2 class="text-base font-bold text-slate-900">{{ $t('rep2_reports_sales.payment_reports') }}</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            {{ $t('rep2_reports_sales.payment_reports_show_summary_and') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('sales.payments.index')"
                            class="px-5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-xl text-xs font-bold transition"
                        >
                            {{ $t('rep2_reports_sales.summary') }}
                        </Link>
                        <Link 
                            :href="route('sales.payments.index')"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-emerald-500/20 transition"
                        >
                            {{ $t('rep2_reports_sales.detailed') }}
                        </Link>
                    </div>
                </div>

                <!-- 3. تقارير أرباح بيع المنتجات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <ArrowTrendingUpIcon class="w-5 h-5 text-sky-600" />
                            <h2 class="text-base font-bold text-slate-900">{{ $t('rep2_reports_sales.product_sales_profit_reports') }}</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            {{ $t('rep2_reports_sales.product_sales_profit_reports_sho') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('reports.financial')"
                            class="px-5 py-2 bg-sky-50 hover:bg-sky-100 text-sky-700 rounded-xl text-xs font-bold transition"
                        >
                            {{ $t('rep2_reports_sales.summary') }}
                        </Link>
                        <Link 
                            :href="route('reports.financial')"
                            class="px-5 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-sky-500/20 transition"
                        >
                            {{ $t('rep2_reports_sales.detailed') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
