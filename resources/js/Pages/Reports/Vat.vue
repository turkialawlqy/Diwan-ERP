<script setup>
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    DocumentChartBarIcon,
    PrinterIcon,
    CheckCircleIcon,
    BuildingLibraryIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    vat: Object,
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
        <Head title="إقرار ضريبة القيمة المضافة ZATCA" />

        <div class="space-y-5">
            <DateRangeFilter route-name="reports.vat" :filters="filters" />
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
                <div class="flex items-center gap-2">
                    <button 
                        @click="printReport"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة مسودة الإقرار الضريبي</span>
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
                        <span class="text-slate-900 font-semibold">الإقرار الضريبي</span>
                    </nav>
                </div>
            </div>

            <!-- Net VAT Payable Highlight Box -->
            <div class="bg-gradient-to-r from-indigo-900 via-indigo-800 to-indigo-950 text-white p-6 sm:p-8 rounded-3xl shadow-md flex flex-col sm:flex-row items-center justify-between gap-6">
                <div class="space-y-2 text-center sm:text-start">
                    <span class="px-3 py-1 rounded-full text-3xs font-bold bg-white/10 text-indigo-200 border border-white/10 inline-block">
                        هيئة الزكاة والضريبة والجمارك (ZATCA)
                    </span>
                    <h2 class="text-xl sm:text-2xl font-black">صافي ضريبة القيمة المضافة المستحقة للسداد</h2>
                    <p class="text-xs text-indigo-200">الفرق بين ضريبة المخرجات (المبيعات) وضريبة المدخلات القابلة للخصم (المشتريات والمصروفات)</p>
                </div>

                <div class="bg-white/10 backdrop-blur-md px-6 py-4 rounded-2xl border border-white/20 text-center sm:text-end flex-shrink-0">
                    <span class="text-3xs text-indigo-200 block mb-1">المبلغ الصافي المستحق</span>
                    <h3 class="text-2xl sm:text-3xl font-black font-mono">
                        {{ formatCurrency(vat?.net_vat_payable) }} <span class="text-sm font-normal">﷼</span>
                    </h3>
                </div>
            </div>

            <!-- Detailed ZATCA Return Table -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-2xs overflow-hidden print:border-none print:shadow-none space-y-6 p-6 sm:p-8">
                <!-- 1. ضريبة المخرجات (المبيعات والإيرادات) -->
                <div class="space-y-3">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2 border-b border-slate-100 pb-2">
                        <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">١</span>
                        <span>ضريبة القيمة المضافة على المبيعات (المخرجات)</span>
                    </h3>

                    <div class="overflow-x-auto"><table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold">
                                <th class="py-2.5 px-4 text-start">البند الضريبي</th>
                                <th class="py-2.5 px-4 text-end">المبلغ الخاضع للضريبة</th>
                                <th class="py-2.5 px-4 text-end">نسبة الضريبة</th>
                                <th class="py-2.5 px-4 text-end">مبلغ الضريبة المحصلة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">المبيعات الخاضعة للنسبة الأساسية ({{ $taxRate() }}%)</td>
                                <td class="py-3 px-4 font-mono text-end">{{ formatCurrency(vat?.sales_subtotal) }} ﷼</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-emerald-600 text-end">{{ formatCurrency(vat?.sales_tax) }} ﷼</td>
                            </tr>
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">الإيرادات والخدمات المتنوعة الخاضعة للضريبة</td>
                                <td class="py-3 px-4 font-mono text-end">-</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-emerald-600 text-end">{{ formatCurrency(vat?.revenues_tax) }} ﷼</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-emerald-50/50 font-bold text-emerald-950 border-t border-emerald-100">
                                <td colspan="3" class="py-2.5 px-4 text-start">إجمالي ضريبة المخرجات:</td>
                                <td class="py-2.5 px-4 font-mono font-black text-end text-emerald-700">
                                    {{ formatCurrency(vat?.total_output_tax) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table></div>
                </div>

                <!-- 2. ضريبة المدخلات (المشتريات والمصروفات) -->
                <div class="space-y-3">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2 border-b border-slate-100 pb-2">
                        <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-800 text-xs flex items-center justify-center font-bold">٢</span>
                        <span>ضريبة القيمة المضافة على المشتريات (المدخلات القابلة للخصم)</span>
                    </h3>

                    <div class="overflow-x-auto"><table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold">
                                <th class="py-2.5 px-4 text-start">البند الضريبي</th>
                                <th class="py-2.5 px-4 text-end">المبلغ الخاضع للضريبة</th>
                                <th class="py-2.5 px-4 text-end">نسبة الضريبة</th>
                                <th class="py-2.5 px-4 text-end">مبلغ الضريبة المستردة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">المشتريات الخاضعة للنسبة الأساسية ({{ $taxRate() }}%)</td>
                                <td class="py-3 px-4 font-mono text-end">{{ formatCurrency(vat?.purchases_subtotal) }} ﷼</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600 text-end">{{ formatCurrency(vat?.purchases_tax) }} ﷼</td>
                            </tr>
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">المصروفات التشغيلية الخاضعة للضريبة</td>
                                <td class="py-3 px-4 font-mono text-end">-</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600 text-end">{{ formatCurrency(vat?.expenses_tax) }} ﷼</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-indigo-50/50 font-bold text-indigo-950 border-t border-indigo-100">
                                <td colspan="3" class="py-2.5 px-4 text-start">إجمالي ضريبة المدخلات:</td>
                                <td class="py-2.5 px-4 font-mono font-black text-end text-indigo-700">
                                    {{ formatCurrency(vat?.total_input_tax) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table></div>
                </div>

                <!-- 3. النتيجة الصافية -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between text-xs sm:text-sm font-black">
                    <span class="text-slate-900">صافي الضريبة الواجب سدادها لهيئة الزكاة:</span>
                    <span class="font-mono text-indigo-600 text-base sm:text-lg">
                        {{ formatCurrency(vat?.net_vat_payable) }} ﷼
                    </span>
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
