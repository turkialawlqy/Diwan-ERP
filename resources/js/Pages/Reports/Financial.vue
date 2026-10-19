<script setup>
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    PrinterIcon,
    ArrowTrendingUpIcon,
    ScaleIcon,
    CurrencyDollarIcon,
    BanknotesIcon,
    ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    incomeStatement: Object,
});

const printReport = () => {
    window.print();
};
</script>

<template>
    <Head title="قائمة الدخل والأرباح والخسائر" />

    <AppLayout>
        <div class="space-y-6">
            <DateRangeFilter route-name="reports.financial" :filters="filters" />
            <!-- Breadcrumbs & Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center gap-1">
                            <HomeIcon class="w-4 h-4" />
                            <span>{{ $t('nav.dashboard') }}</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-400" />
                        <Link :href="route('reports.sales')" class="hover:text-indigo-600">
                            {{ $t('nav.reports') }}
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-400" />
                        <span class="text-slate-800 font-medium">قائمة الدخل والأرباح والخسائر</span>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">قائمة الدخل (الأرباح والخسائر)</h1>
                    <p class="text-sm text-slate-500 mt-0.5">تقرير الأداء المالي الشامل للإيرادات والمصروفات وصافي الأرباح للفترة الحالية</p>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        @click="printReport"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition"
                    >
                        <PrinterIcon class="w-4 h-4 text-slate-500" />
                        <span>طباعة القائمة</span>
                    </button>
                    <Link
                        :href="route('accounting.trial-balance.index')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 rounded-xl text-sm font-semibold text-white shadow-xs transition"
                    >
                        <ScaleIcon class="w-4 h-4" />
                        <span>ميزان المراجعة</span>
                    </Link>
                </div>
            </div>

            <!-- KPI Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">إجمالي الإيرادات</div>
                            <div class="text-2xl font-black text-slate-900 mt-1">
                                {{ Number(incomeStatement.total_revenue).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-emerald-600 font-semibold mt-1">المبيعات والإيرادات الأخرى</div>
                        </div>
                        <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600">
                            <BanknotesIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">مجمل الربح</div>
                            <div class="text-2xl font-black text-indigo-700 mt-1">
                                {{ Number(incomeStatement.gross_profit).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-indigo-500 font-semibold mt-1">بعد خصم تكلفة البضاعة المباعة</div>
                        </div>
                        <div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                            <ArrowTrendingUpIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">المصروفات التشغيلية</div>
                            <div class="text-2xl font-black text-rose-600 mt-1">
                                {{ Number(incomeStatement.operating_expenses).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-rose-500 font-semibold mt-1">مصاريف التشغيل والإدارة</div>
                        </div>
                        <div class="w-12 h-12 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600">
                            <CurrencyDollarIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">صافي الربح</div>
                            <div :class="['text-2xl font-black mt-1', incomeStatement.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ Number(incomeStatement.net_profit).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs font-semibold mt-1" :class="incomeStatement.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                                هامش صافي الربح: {{ incomeStatement.net_margin }}%
                            </div>
                        </div>
                        <div :class="['w-12 h-12 rounded-xl flex items-center justify-center', incomeStatement.net_profit >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600']">
                            <ScaleIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formal Income Statement Table -->
            <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden print:shadow-none print:border-none">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="font-bold text-slate-900 text-lg">قائمة الدخل الشاملة</h3>
                        <p class="text-xs text-slate-500 mt-0.5">للفترة المالية الحالية (جميع المبالغ بالريال السعودي ﷼)</p>
                    </div>
                    <span class="text-xs font-bold bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full border border-indigo-200">
                        معتمدة حسب المعايير المحاسبية (IFRS)
                    </span>
                </div>

                <div class="p-6">
                    <div class="overflow-x-auto"><table class="w-full text-right border-collapse">
                        <tbody>
                            <!-- Revenue Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base">1. الإيرادات التشغيلية (Operating Revenues)</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">إيرادات المبيعات والخدمات</td>
                                <td class="py-3 px-4 text-sm font-semibold text-slate-800">{{ Number(incomeStatement.sales_revenue).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">إيرادات أخرى متنوعة</td>
                                <td class="py-3 px-4 text-sm font-semibold text-slate-800">{{ Number(incomeStatement.other_revenue).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-emerald-50/50 font-bold text-emerald-900">
                                <td class="py-3 px-6 text-sm">إجمالي الإيرادات</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-emerald-800">{{ Number(incomeStatement.total_revenue).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼</td>
                            </tr>

                            <!-- COGS Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base pt-6">2. تكلفة البضاعة المباعة (Cost of Goods Sold - COGS)</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">تكلفة المشتريات والمخزون المنصرف</td>
                                <td class="py-3 px-4 text-sm font-semibold text-rose-700">({{ Number(incomeStatement.cogs).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}) ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-rose-50/30 font-bold text-rose-900">
                                <td class="py-3 px-6 text-sm">إجمالي تكلفة المبيعات</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-rose-700">({{ Number(incomeStatement.cogs).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}) ﷼</td>
                            </tr>

                            <!-- Gross Profit -->
                            <tr class="bg-indigo-50/70 border-b-2 border-indigo-200 font-black text-indigo-950 text-base">
                                <td class="py-4 px-6">مجمل الربح (Gross Profit)</td>
                                <td class="py-4 px-4 text-sm font-semibold text-indigo-700">{{ incomeStatement.total_revenue > 0 ? Math.round((incomeStatement.gross_profit / incomeStatement.total_revenue) * 100) : 0 }}% من الإيرادات</td>
                                <td class="py-4 px-4 text-left text-lg font-black text-indigo-900">{{ Number(incomeStatement.gross_profit).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼</td>
                            </tr>

                            <!-- Expenses Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base pt-6">3. المصروفات التشغيلية والإدارية (Operating Expenses)</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">مصاريف التشغيل والإيجارات والمنافع</td>
                                <td class="py-3 px-4 text-sm font-semibold text-rose-700">({{ Number(incomeStatement.operating_expenses).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}) ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-rose-50/30 font-bold text-rose-900">
                                <td class="py-3 px-6 text-sm">إجمالي المصروفات التشغيلية</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-rose-700">({{ Number(incomeStatement.operating_expenses).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}) ﷼</td>
                            </tr>

                            <!-- Net Profit -->
                            <tr class="bg-emerald-50/80 border-t-4 border-double border-emerald-500 font-black text-emerald-950 text-lg">
                                <td class="py-5 px-6">صافي الربح التشغيلي للفترة (Net Profit)</td>
                                <td class="py-5 px-4 text-sm font-bold text-emerald-700">هامش صافي الربح: {{ incomeStatement.net_margin }}%</td>
                                <td class="py-5 px-4 text-left text-2xl font-black text-emerald-700">
                                    {{ Number(incomeStatement.net_profit).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} ﷼
                                </td>
                            </tr>
                        </tbody>
                    </table></div>
                </div>

                <!-- Footer Signatures for Official Financial Statement -->
                <div class="p-8 border-t border-slate-100 bg-slate-50/40 grid grid-cols-1 md:grid-cols-3 gap-6 text-center text-xs text-slate-500">
                    <div>
                        <div class="font-bold text-slate-700 mb-6">إعداد المحاسب المالي</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700 mb-6">المراجعة والتدقيق الداخلي</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700 mb-6">اعتماد الإدارة المالية</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
