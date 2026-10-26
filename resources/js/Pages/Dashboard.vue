<script setup>
import { cur } from '@/utils/tax';
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import QuickActionButton from '@/Components/QuickActionButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
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
    PlusIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    kpis: Object,
    recentInvoices: Array,
    topProducts: Array,
    salesChart: Object,
    cashOutlook: { type: Object, default: null },
    onboarding: { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const tenant = computed(() => page.props.tenant);

// Current date formatted in Arabic
const currentDate = computed(() => {
    return new Date().toLocaleDateString('ar-SA', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
});

// Chart Options for ApexCharts
const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 300,
        fontFamily: 'Cairo, sans-serif',
        toolbar: { show: false },
    },
    colors: ['#4F46E5', '#10B981'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.35,
            opacityTo: 0.05,
            stops: [0, 90, 100]
        }
    },
    xaxis: {
        categories: props.salesChart?.months || [],
        labels: {
            style: { colors: '#64748B', fontSize: '11px', fontFamily: 'Cairo' }
        }
    },
    yaxis: {
        labels: {
            formatter: (val) => `${val.toLocaleString()} ${cur()}`,
            style: { colors: '#64748B', fontSize: '11px', fontFamily: 'Cairo' }
        }
    },
    tooltip: {
        theme: 'light',
        y: { formatter: (val) => `${val.toLocaleString()} ${cur()}` }
    },
    legend: {
        position: 'top',
        horizontalAlign: 'right',
        fontFamily: 'Cairo',
        fontSize: '12px'
    }
}));
</script>

<template>
    <AppLayout>
        <Head title="لوحة التحكم الرئيسية" />

        <!-- First-run setup checklist (owner) -->
        <div v-if="onboarding" class="mb-6 bg-white border border-indigo-100 rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-black text-slate-800 text-sm">إعداد منشأتك للبدء ({{ onboarding.done }} من {{ onboarding.total }})</h2>
                <div class="w-40 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-indigo-600" :style="{ width: (onboarding.done / onboarding.total * 100) + '%' }"></div></div>
            </div>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2 text-xs">
                <li v-for="s in onboarding.steps" :key="s.key">
                    <Link :href="s.url" class="flex items-center gap-2 p-2 rounded-xl border" :class="s.done ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 hover:border-indigo-300 text-slate-700'">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-3xs font-black" :class="s.done ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500'">{{ s.done ? '✓' : '' }}</span>
                        <span :class="s.done ? 'line-through' : 'font-bold'">{{ s.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <!-- Welcome Banner & Top Header -->
        <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">
                        مرحباً بك، {{ user?.name }}
                    </h1>
                    <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 text-xs font-bold px-2.5 py-0.5 rounded-full border border-emerald-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        متصل
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
                    <CalendarIcon class="w-4 h-4 text-slate-400" />
                    <span>{{ currentDate }}</span>
                    <span class="text-slate-300">•</span>
                    <span>فرع: الفرع الرئيسي</span>
                </p>
            </div>

            <!-- Header Action Buttons -->
            <div class="flex items-center gap-2.5">
                <Link
                    v-if="$canRoute('sales.invoices.create')"
                    :href="route('sales.invoices.create')"
                    class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition active:scale-95"
                >
                    <PlusIcon class="w-4 h-4" />
                    <span>فاتورة جديدة</span>
                </Link>
                <Link
                    :href="route('pos.index')"
                    class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition active:scale-95"
                >
                    <DeviceTabletIcon class="w-4 h-4" />
                    <span>نقطة البيع (POS)</span>
                </Link>
            </div>
        </div>

        <!-- 1. Quick Actions Row -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-8">
            <QuickActionButton 
                v-if="$canRoute('sales.invoices.create')"
                :href="route('sales.invoices.create')"
                title="فاتورة مبيعات جديدة"
                subtitle="إصدار فاتورة إلكترونية معتمدة"
                color="indigo"
            >
                <template #icon>
                    <DocumentPlusIcon class="w-5 h-5" />
                </template>
            </QuickActionButton>

            <QuickActionButton 
                v-if="$canRoute('sales.clients.create')"
                :href="route('sales.clients.create')"
                title="إضافة عميل جديد"
                subtitle="تسجيل بيانات عميل جديد"
                color="blue"
            >
                <template #icon>
                    <UserPlusIcon class="w-5 h-5" />
                </template>
            </QuickActionButton>

            <QuickActionButton 
                v-if="$canRoute('inventory.products.create')"
                :href="route('inventory.products.create')"
                title="إضافة منتج جديد"
                subtitle="إدراج صنف وتكلفة ومخزون"
                color="purple"
            >
                <template #icon>
                    <CubeIcon class="w-5 h-5" />
                </template>
            </QuickActionButton>

            <QuickActionButton 
                :href="route('pos.index')"
                title="جلسة كاشير POS"
                subtitle="بدء نقطة البيع السريعة"
                color="emerald"
            >
                <template #icon>
                    <DeviceTabletIcon class="w-5 h-5" />
                </template>
            </QuickActionButton>
        </div>

        <!-- 2. KPI Summary Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <KpiCard
                title="مبيعات اليوم"
                :value="kpis.today_sales"
                :unit="$cur()"
                color="indigo"
            >
                <template #icon>
                    <BanknotesIcon class="w-5 h-5" />
                </template>
            </KpiCard>

            <KpiCard
                title="مبيعات الشهر الحالي"
                :value="kpis.month_sales"
                :unit="$cur()"
                :trend="kpis.month_sales_trend"
                trendText="مقارنة بالشهر الماضي"
                color="emerald"
            >
                <template #icon>
                    <ArrowTrendingUpIcon class="w-5 h-5" />
                </template>
            </KpiCard>

            <KpiCard
                title="عدد الفواتير المصدرة"
                :value="kpis.invoices_count"
                unit="فاتورة"
                :trend="kpis.invoices_count_trend"
                trendText="نمو الفواتير"
                color="blue"
            >
                <template #icon>
                    <DocumentTextIcon class="w-5 h-5" />
                </template>
            </KpiCard>

            <KpiCard
                title="المبالغ المستحقة (الآجل)"
                :value="kpis.receivables"
                :unit="$cur()"
                :trend="kpis.receivables_trend"
                trendText="مستحقات التحصيل"
                color="amber"
            >
                <template #icon>
                    <BanknotesIcon class="w-5 h-5" />
                </template>
            </KpiCard>
        </div>

        <!-- Cash outlook (from the insights dashboards) -->
        <Link v-if="cashOutlook" :href="route('reports.insights')" class="mb-6 grid grid-cols-2 lg:grid-cols-4 gap-3 bg-white border border-slate-200 rounded-2xl p-4 text-xs hover:border-indigo-300">
            <div><div class="text-slate-500">النقدية اليوم</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.now).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">المتوقع بعد 30 يوماً</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.in30).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">أدنى رصيد متوقع</div><div class="text-base font-black font-mono mt-1" :class="cashOutlook.lowest < 0 ? 'text-rose-700' : ''">{{ Number(cashOutlook.lowest).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
            <div><div class="text-slate-500">ذمم عملاء متأخرة</div><div class="text-base font-black font-mono mt-1">{{ Number(cashOutlook.overdue).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</div></div>
        </Link>

        <!-- 3. Performance Chart & Analytics -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs mb-8">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">نظرة عامة على المبيعات والمشتريات</h2>
                    <p class="text-xs text-slate-500">حركة المبيعات مقارنة بتكاليف الشراء خلال الأشهر الستة الأخيرة</p>
                </div>
            </div>

            <div class="min-h-[300px]">
                <apexchart
                    type="area"
                    height="300"
                    :options="chartOptions"
                    :series="salesChart?.series || []"
                />
            </div>
        </div>

        <!-- 4. Bottom Grid: Recent Invoices & Top Products -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Invoices (2 Cols) -->
            <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden flex flex-col">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">أحدث فواتير المبيعات</h2>
                        <p class="text-[11px] text-slate-400">آخر الفواتير الإلكترونية الصادرة</p>
                    </div>
                    <Link
                        :href="route('sales.invoices.index')"
                        class="inline-flex items-center gap-1 text-xs font-bold text-indigo-600 hover:text-indigo-800 transition"
                    >
                        <span>عرض الكل</span>
                        <ArrowLeftIcon class="w-3.5 h-3.5" />
                    </Link>
                </div>

                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-start text-xs">
                        <thead class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                            <tr>
                                <th class="px-5 py-3 text-start">رقم الفاتورة</th>
                                <th class="px-5 py-3 text-start">العميل</th>
                                <th class="px-5 py-3 text-start">التاريخ</th>
                                <th class="px-5 py-3 text-start">الإجمالي</th>
                                <th class="px-5 py-3 text-center">الحالة</th>
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
                    <h2 class="text-sm font-bold text-slate-900">أكثر المنتجات مبيعاً</h2>
                    <p class="text-[11px] text-slate-400">الأصناف الأعلى طلباً وإيراداً</p>
                </div>

                <div class="space-y-3 flex-1">
                    <div 
                        v-for="(product, idx) in topProducts" 
                        :key="idx"
                        class="p-3 rounded-2xl bg-slate-50/70 hover:bg-slate-50 border border-slate-100 flex items-center justify-between gap-3 transition"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-white border border-slate-200 flex items-center justify-center font-bold text-xs text-indigo-600 shadow-xs">
                                {{ idx + 1 }}
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-800 truncate max-w-[150px]">{{ product.name }}</p>
                                <span class="text-[10px] text-slate-400">{{ product.category }}</span>
                            </div>
                        </div>

                        <div class="text-end">
                            <p class="text-xs font-bold text-emerald-700 font-sans">{{ product.revenue }} {{ $cur() }}</p>
                            <span class="text-[10px] text-slate-500">{{ product.sold_units }} قطعة</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
