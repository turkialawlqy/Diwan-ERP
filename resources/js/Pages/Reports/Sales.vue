<script setup>
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
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

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    stats: Object,
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="تقارير المبيعات" />

        <div class="space-y-6">
            <!-- Breadcrumb Navigation -->
            <div class="flex items-center justify-between">
                <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                    <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                        <HomeIcon class="w-3.5 h-3.5" />
                    </Link>
                    <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                    <span>التقارير</span>
                    <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                    <span class="text-slate-900 font-semibold">المبيعات</span>
                </nav>
            </div>

            <DateRangeFilter route-name="reports.sales" :filters="filters" />

            <!-- Live KPI Overview Banner -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي المبيعات</span>
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
                        <span class="text-3xs font-bold text-slate-400">المدفوعات المحصلة</span>
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
                        <span class="text-3xs font-bold text-slate-400">تقدير مجمل الأرباح</span>
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

            <!-- 3 Main Report Blocks (Exact Match to anas.diwan.io) -->
            <div class="space-y-4">
                <!-- 1. تقارير المبيعات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2 max-w-3xl">
                        <div class="flex items-center gap-2">
                            <DocumentChartBarIcon class="w-5 h-5 text-indigo-600" />
                            <h2 class="text-base font-bold text-slate-900">تقارير المبيعات</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            تعرض تقارير المبيعات معلومات ملخصة وتفصيلية عن المبيعات والمرتجعات التي تمت خلال فترة زمنية مُجمعة حسب اليوم أو الشهر أو السنة أو الموظف أو العميل أو المنتج أو تصنيف المنتج ويمكنك تحديد الفروع والمزيد من الخيارات الأخرى ..
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('sales.invoices.index')"
                            class="px-5 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-xl text-xs font-bold transition"
                        >
                            ملخص
                        </Link>
                        <Link 
                            :href="route('sales.invoices.index')"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition"
                        >
                            تفصيلي
                        </Link>
                    </div>
                </div>

                <!-- 2. تقارير المدفوعات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2 max-w-3xl">
                        <div class="flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-emerald-600" />
                            <h2 class="text-base font-bold text-slate-900">تقارير المدفوعات</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            تعرض تقارير المدفوعات معلومات ملخصة وتفصيلية عن مدفوعات العملاء التي تمت خلال فترة زمنية مُجمعة حسب اليوم أو الشهر أو السنة أو الموظف أو العميل ويمكنك تحديد الفروع والمزيد من الخيارات الأخرى ..
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('sales.payments.index')"
                            class="px-5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-xl text-xs font-bold transition"
                        >
                            ملخص
                        </Link>
                        <Link 
                            :href="route('sales.payments.index')"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-emerald-500/20 transition"
                        >
                            تفصيلي
                        </Link>
                    </div>
                </div>

                <!-- 3. تقارير أرباح بيع المنتجات -->
                <div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-6 hover:shadow-md transition">
                    <div class="space-y-2 max-w-3xl">
                        <div class="flex items-center gap-2">
                            <ArrowTrendingUpIcon class="w-5 h-5 text-sky-600" />
                            <h2 class="text-base font-bold text-slate-900">تقارير أرباح بيع المنتجات</h2>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            تعرض تقارير أرباح بيع المنتجات معلومات ملخصة وتفصيلية عن أرباح بيع المنتجات ذات المخزون التي تمت خلال فترة زمنية مُجمعة حسب اليوم أو الشهر أو السنة أو الموظف أو العميل أو المنتج أو تصنيف المنتج ويمكنك تحديد الفروع والمزيد من الخيارات الأخرى ..
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <Link 
                            :href="route('reports.financial')"
                            class="px-5 py-2 bg-sky-50 hover:bg-sky-100 text-sky-700 rounded-xl text-xs font-bold transition"
                        >
                            ملخص
                        </Link>
                        <Link 
                            :href="route('reports.financial')"
                            class="px-5 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-sky-500/20 transition"
                        >
                            تفصيلي
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
