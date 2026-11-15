<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
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
        <Head :title="$t('rep2_reports_vat.zatca_vat_return')" />

        <div class="space-y-5">
            <DateRangeFilter route-name="reports.vat" :filters="filters" />
            <!-- Header Ribbon -->
            <PageHeader :title="$t('rep2_reports_vat.tax_return')" :crumbs="[{ label: $t('rep2_reports_vat.reports') }, { label: $t('rep2_reports_vat.tax_return') }]">
<template #actions>
<button 
                        @click="printReport"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('rep2_reports_vat.print_the_tax_return_draft') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- Net VAT Payable Highlight Box -->
            <div class="bg-gradient-to-r from-indigo-900 via-indigo-800 to-indigo-950 text-white p-6 sm:p-8 rounded-3xl shadow-md flex flex-col sm:flex-row items-center justify-between gap-6">
                <div class="space-y-2 text-center sm:text-start">
                    <span class="px-3 py-1 rounded-full text-3xs font-bold bg-white/10 text-indigo-200 border border-white/10 inline-block">
                        {{ $t('rep2_reports_vat.zakat_tax_and_customs_authority') }}
                    </span>
                    <h2 class="text-xl sm:text-2xl font-black">{{ $t('rep2_reports_vat.net_vat_due_for_payment') }}</h2>
                    <p class="text-xs text-indigo-200">{{ $t('rep2_reports_vat.the_difference_between_output_ta') }}</p>
                </div>

                <div class="bg-white/10 backdrop-blur-md px-6 py-4 rounded-2xl border border-white/20 text-center sm:text-end flex-shrink-0">
                    <span class="text-3xs text-indigo-200 block mb-1">{{ $t('rep2_reports_vat.net_amount_due') }}</span>
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
                        <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">{{ $t('rep2_reports_vat.1') }}</span>
                        <span>{{ $t('rep2_reports_vat.vat_on_sales_output') }}</span>
                    </h3>

                    <div class="overflow-x-auto"><table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold">
                                <th class="py-2.5 px-4 text-start">{{ $t('rep2_reports_vat.tax_item') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.taxable_amount') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.tax_rate') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.tax_collected') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">{{ $t('rep2_reports_vat.sales_at_the_standard_rate_n', { n0: $taxRate() }) }}</td>
                                <td class="py-3 px-4 font-mono text-end">{{ formatCurrency(vat?.sales_subtotal) }} ﷼</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-emerald-600 text-end">{{ formatCurrency(vat?.sales_tax) }} ﷼</td>
                            </tr>
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">{{ $t('rep2_reports_vat.taxable_miscellaneous_revenue_an') }}</td>
                                <td class="py-3 px-4 font-mono text-end">-</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-emerald-600 text-end">{{ formatCurrency(vat?.revenues_tax) }} ﷼</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-emerald-50/50 font-bold text-emerald-950 border-t border-emerald-100">
                                <td colspan="3" class="py-2.5 px-4 text-start">{{ $t('rep2_reports_vat.total_output_tax') }}</td>
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
                        <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-800 text-xs flex items-center justify-center font-bold">{{ $t('rep2_reports_vat.2') }}</span>
                        <span>{{ $t('rep2_reports_vat.vat_on_purchases_deductible_inpu') }}</span>
                    </h3>

                    <div class="overflow-x-auto"><table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold">
                                <th class="py-2.5 px-4 text-start">{{ $t('rep2_reports_vat.tax_item') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.taxable_amount') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.tax_rate') }}</th>
                                <th class="py-2.5 px-4 text-end">{{ $t('rep2_reports_vat.tax_recoverable') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">{{ $t('rep2_reports_vat.purchases_at_the_standard_rate_n', { n0: $taxRate() }) }}</td>
                                <td class="py-3 px-4 font-mono text-end">{{ formatCurrency(vat?.purchases_subtotal) }} ﷼</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600 text-end">{{ formatCurrency(vat?.purchases_tax) }} ﷼</td>
                            </tr>
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-900">{{ $t('rep2_reports_vat.taxable_operating_expenses') }}</td>
                                <td class="py-3 px-4 font-mono text-end">-</td>
                                <td class="py-3 px-4 text-end font-mono">{{ $taxRate() }}%</td>
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600 text-end">{{ formatCurrency(vat?.expenses_tax) }} ﷼</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-indigo-50/50 font-bold text-indigo-950 border-t border-indigo-100">
                                <td colspan="3" class="py-2.5 px-4 text-start">{{ $t('rep2_reports_vat.total_input_tax') }}</td>
                                <td class="py-2.5 px-4 font-mono font-black text-end text-indigo-700">
                                    {{ formatCurrency(vat?.total_input_tax) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table></div>
                </div>

                <!-- 3. النتيجة الصافية -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between text-xs sm:text-sm font-black">
                    <span class="text-slate-900">{{ $t('rep2_reports_vat.net_tax_payable_to_the_zakat_aut') }}</span>
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
