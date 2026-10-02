<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    PrinterIcon,
    FunnelIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    rows: Array,
    summary: Object,
    filters: Object,
});

const startDate = ref(props.filters?.start_date || '');
const endDate = ref(props.filters?.end_date || '');

const handleFilter = () => {
    router.get(route('accounting.trial-balance.index'), {
        start_date: startDate.value || undefined,
        end_date: endDate.value || undefined,
    }, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const printReport = () => {
    window.print();
};

const typeLabels = {
    asset: 'أصول',
    liability: 'خصوم',
    equity: 'حقوق ملكية',
    revenue: 'إيرادات',
    expense: 'مصروفات',
};
</script>

<template>
    <AppLayout>
        <Head title="ميزان المراجعة - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleFilter"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المحاسبة</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">ميزان المراجعة</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="printReport"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة ميزان المراجعة</span>
                    </button>
                </div>
            </div>

            <!-- Summary Integrity Card -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 no-print">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1">
                    <span class="text-xs font-bold text-slate-500">إجمالي الحركات المدينة</span>
                    <h3 class="text-lg font-black font-mono text-indigo-600">
                        {{ formatCurrency(summary?.total_debit) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1">
                    <span class="text-xs font-bold text-slate-500">إجمالي الحركات الدائنة</span>
                    <h3 class="text-lg font-black font-mono text-indigo-600">
                        {{ formatCurrency(summary?.total_credit) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div 
                    :class="[
                        summary?.is_balanced ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-rose-50 border-rose-200 text-rose-900',
                        'p-4 rounded-2xl border flex items-center justify-between shadow-2xs'
                    ]"
                >
                    <div class="space-y-1">
                        <span class="text-xs font-bold block opacity-75">حالة التوازن المحاسبي</span>
                        <h3 class="text-base font-black flex items-center gap-1.5">
                            <CheckCircleIcon v-if="summary?.is_balanced" class="w-5 h-5 text-emerald-600" />
                            <ExclamationTriangleIcon v-else class="w-5 h-5 text-rose-600" />
                            <span>{{ summary?.is_balanced ? 'ميزان متوازن (100%)' : 'يوجد عدم توازن!' }}</span>
                        </h3>
                    </div>
                    <div class="text-end font-mono text-xs font-bold">
                        الفرق: {{ formatCurrency(summary?.difference) }} ﷼
                    </div>
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between no-print">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-slate-600 font-bold">من:</span>
                        <input v-model="startDate" type="date" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-slate-600 font-bold">إلى:</span>
                        <input v-model="endDate" type="date" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>
                    <button 
                        @click="handleFilter"
                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1"
                    >
                        <FunnelIcon class="w-3.5 h-3.5" />
                        <span>تطبيق الفترة</span>
                    </button>
                </div>
            </div>

            <!-- Trial Balance Sheet -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden print:border-none print:shadow-none">
                <div class="p-4 border-b border-slate-100 hidden print:block">
                    <h2 class="text-base font-black text-slate-900">ميزان المراجعة بالأرصدة والمجاميع (TRIAL BALANCE)</h2>
                    <p class="text-3xs text-slate-500">نظام الأستاذ لإدارة الأعمال</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200 text-slate-600 font-bold">
                                <th rowspan="2" class="py-3 px-4 text-start border-e border-slate-200">كود الحساب</th>
                                <th rowspan="2" class="py-3 px-4 text-start border-e border-slate-200">اسم الحساب المحاسبي</th>
                                <th rowspan="2" class="py-3 px-4 text-start border-e border-slate-200">التصنيف</th>
                                <th colspan="2" class="py-2 px-4 text-center border-b border-e border-slate-200 bg-indigo-50/40 text-indigo-900">
                                    مجموع الحركات خلال الفترة
                                </th>
                                <th colspan="2" class="py-2 px-4 text-center border-b border-slate-200 bg-emerald-50/40 text-emerald-900">
                                    الأرصدة النهائية المقفلة
                                </th>
                            </tr>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-500 font-bold text-3xs">
                                <th class="py-2 px-3 text-end border-e border-slate-200">مدين (Debit)</th>
                                <th class="py-2 px-3 text-end border-e border-slate-200">دائن (Credit)</th>
                                <th class="py-2 px-3 text-end border-e border-slate-200">رصيد مدين</th>
                                <th class="py-2 px-3 text-end">رصيد دائن</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono">
                            <tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-2.5 px-4 font-bold text-indigo-600 border-e border-slate-100">
                                    {{ row.code }}
                                </td>
                                <td class="py-2.5 px-4 font-sans font-semibold text-slate-900 border-e border-slate-100">
                                    {{ row.name_ar }}
                                </td>
                                <td class="py-2.5 px-4 font-sans text-3xs text-slate-500 border-e border-slate-100">
                                    {{ typeLabels[row.type] || row.type }}
                                </td>
                                <td class="py-2.5 px-3 text-end text-slate-700 border-e border-slate-100">
                                    {{ row.total_debit > 0 ? formatCurrency(row.total_debit) : '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-end text-slate-700 border-e border-slate-100">
                                    {{ row.total_credit > 0 ? formatCurrency(row.total_credit) : '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-end font-bold text-emerald-600 border-e border-slate-100">
                                    {{ row.ending_debit > 0 ? formatCurrency(row.ending_debit) : '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-end font-bold text-amber-600">
                                    {{ row.ending_credit > 0 ? formatCurrency(row.ending_credit) : '-' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100 font-black border-t-2 border-slate-300 text-slate-900 font-mono text-xs">
                                <td colspan="3" class="py-3 px-4 text-start font-sans">الإجمالي العام لميزان المراجعة:</td>
                                <td class="py-3 px-3 text-end border-e border-slate-200 text-indigo-600">
                                    {{ formatCurrency(summary?.total_debit) }} ﷼
                                </td>
                                <td class="py-3 px-3 text-end border-e border-slate-200 text-indigo-600">
                                    {{ formatCurrency(summary?.total_credit) }} ﷼
                                </td>
                                <td class="py-3 px-3 text-end border-e border-slate-200 text-emerald-700">
                                    {{ formatCurrency(rows.reduce((acc, r) => acc + r.ending_debit, 0)) }} ﷼
                                </td>
                                <td class="py-3 px-3 text-end text-amber-700">
                                    {{ formatCurrency(rows.reduce((acc, r) => acc + r.ending_credit, 0)) }} ﷼
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
