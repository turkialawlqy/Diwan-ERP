<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    CubeIcon,
    BanknotesIcon,
    ExclamationTriangleIcon,
    PrinterIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Array,
    stats: Object,
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const printReport = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <Head title="تقرير تقييم المخزون - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
                <div class="flex items-center gap-2">
                    <button 
                        @click="printReport"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة تقرير المخزون</span>
                    </button>
                </div>

                <!-- Breadcrumb -->
                <div class="flex items-center gap-2">
                    <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span>التقارير</span>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-semibold">تقرير المخزون</span>
                    </nav>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 no-print">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي الأصناف المسجلة</span>
                        <h3 class="text-lg font-black text-slate-900">{{ stats?.total_products || 0 }} صنف</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي القيمة التقديرية للمخزون</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_stock_value) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">تنبيهات قرب نفاد المخزون</span>
                        <h3 class="text-lg font-black text-amber-600">{{ stats?.low_stock_count || 0 }} صنف</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <ExclamationTriangleIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Inventory Valuation Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-2xs overflow-hidden print:border-none print:shadow-none">
                <div class="p-4 border-b border-slate-100 hidden print:block">
                    <h2 class="text-base font-black text-slate-900">تقرير تقييم المخزون الشامل (INVENTORY VALUATION REPORT)</h2>
                    <p class="text-3xs text-slate-500">نظام الأستاذ لإدارة الأعمال والمخازن</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-3 px-4 text-start">كود الصنف / SKU</th>
                                <th class="py-3 px-4 text-start">اسم المنتج</th>
                                <th class="py-3 px-4 text-start">التصنيف</th>
                                <th class="py-3 px-4 text-end">سعر التكلفة</th>
                                <th class="py-3 px-4 text-end">سعر البيع</th>
                                <th class="py-3 px-4 text-center">الكمية بالمخزن</th>
                                <th class="py-3 px-4 text-end">إجمالي القيمة بالتكلفة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="prod in products" :key="prod.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                    {{ prod.sku || prod.barcode || '-' }}
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ prod.name_ar }}
                                </td>
                                <td class="py-3 px-4 text-slate-600 text-3xs">
                                    {{ prod.category?.name_ar || 'عام' }}
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(prod.cost_price) }} ﷼
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(prod.sale_price) }} ﷼
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-bold">
                                    <span :class="prod.stocks?.reduce((acc, s) => acc + s.quantity, 0) <= prod.alert_quantity ? 'text-rose-600' : 'text-slate-800'">
                                        {{ prod.stocks?.reduce((acc, s) => acc + s.quantity, 0) || 0 }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono font-black text-emerald-600 text-end">
                                    {{ formatCurrency((prod.cost_price || 0) * (prod.stocks?.reduce((acc, s) => acc + s.quantity, 0) || 0)) }} ﷼
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100 font-black border-t-2 border-slate-200 text-slate-900 text-xs">
                                <td colspan="6" class="py-3 px-4 text-start font-sans">إجمالي القيمة الإجمالية للمخزون:</td>
                                <td class="py-3 px-4 font-mono text-end text-sm text-emerald-600">
                                    {{ formatCurrency(stats?.total_stock_value) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    .no-print {
        display: none !important;
    }
}
</style>
