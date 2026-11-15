<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
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
    <Head :title="$t('rep2_reports_financial.income_and_profit_loss_statement')" />

    <AppLayout>
        <div class="space-y-6">
            <DateRangeFilter route-name="reports.financial" :filters="filters" />
            <!-- Breadcrumbs & Header -->
            <PageHeader :title="$t('rep2_reports_financial.income_statement_profit_and_loss')" :subtitle="$t('rep2_reports_financial.a_comprehensive_financial_perfor')" :crumbs="[{ label: $t('nav.reports') }, { label: $t('rep2_reports_financial.income_and_profit_loss_statement') }]">
<template #actions>
<button
                        @click="printReport"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition"
                    >
                        <PrinterIcon class="w-4 h-4 text-slate-500" />
                        <span>{{ $t('rep2_reports_financial.print_the_statement') }}</span>
                    </button>
                    <Link
                        :href="route('accounting.trial-balance.index')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 rounded-xl text-sm font-semibold text-white shadow-xs transition"
                    >
                        <ScaleIcon class="w-4 h-4" />
                        <span>{{ $t('rep2_reports_financial.trial_balance') }}</span>
                    </Link>
</template>
</PageHeader>

            <!-- KPI Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">{{ $t('rep2_reports_financial.total_revenue') }}</div>
                            <div class="text-2xl font-black text-slate-900 mt-1">
                                {{ Number(incomeStatement.total_revenue).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-emerald-600 font-semibold mt-1">{{ $t('rep2_reports_financial.sales_and_other_revenue') }}</div>
                        </div>
                        <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600">
                            <BanknotesIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">{{ $t('rep2_reports_financial.gross_profit') }}</div>
                            <div class="text-2xl font-black text-indigo-700 mt-1">
                                {{ Number(incomeStatement.gross_profit).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-indigo-500 font-semibold mt-1">{{ $t('rep2_reports_financial.after_deducting_the_cost_of_good') }}</div>
                        </div>
                        <div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                            <ArrowTrendingUpIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">{{ $t('rep2_reports_financial.operating_expenses') }}</div>
                            <div class="text-2xl font-black text-rose-600 mt-1">
                                {{ Number(incomeStatement.operating_expenses).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs text-rose-500 font-semibold mt-1">{{ $t('rep2_reports_financial.operating_and_administrative_exp') }}</div>
                        </div>
                        <div class="w-12 h-12 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600">
                            <CurrencyDollarIcon class="w-6 h-6" />
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-slate-400">{{ $t('rep2_reports_financial.net_profit') }}</div>
                            <div :class="['text-2xl font-black mt-1', incomeStatement.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ Number(incomeStatement.net_profit).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼
                            </div>
                            <div class="text-xs font-semibold mt-1" :class="incomeStatement.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                                {{ $t('rep2_reports_financial.net_profit_margin_n', { n0: incomeStatement.net_margin }) }}
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
                        <h3 class="font-bold text-slate-900 text-lg">{{ $t('rep2_reports_financial.comprehensive_income_statement') }}</h3>
                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('rep2_reports_financial.for_the_current_financial_period') }}</p>
                    </div>
                    <span class="text-xs font-bold bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full border border-indigo-200">
                        {{ $t('rep2_reports_financial.per_accounting_standards_ifrs') }}
                    </span>
                </div>

                <div class="p-6">
                    <div class="overflow-x-auto"><table class="w-full text-right border-collapse">
                        <tbody>
                            <!-- Revenue Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base">{{ $t('rep2_reports_financial.1_operating_revenues') }}</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">{{ $t('rep2_reports_financial.sales_and_service_revenue') }}</td>
                                <td class="py-3 px-4 text-sm font-semibold text-slate-800">{{ Number(incomeStatement.sales_revenue).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">{{ $t('rep2_reports_financial.other_miscellaneous_revenue') }}</td>
                                <td class="py-3 px-4 text-sm font-semibold text-slate-800">{{ Number(incomeStatement.other_revenue).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-emerald-50/50 font-bold text-emerald-900">
                                <td class="py-3 px-6 text-sm">{{ $t('rep2_reports_financial.total_revenue') }}</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-emerald-800">{{ Number(incomeStatement.total_revenue).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼</td>
                            </tr>

                            <!-- COGS Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base pt-6">{{ $t('rep2_reports_financial.2_cost_of_goods_sold_cogs') }}</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">{{ $t('rep2_reports_financial.cost_of_purchases_and_issued_sto') }}</td>
                                <td class="py-3 px-4 text-sm font-semibold text-rose-700">({{ Number(incomeStatement.cogs).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}) ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-rose-50/30 font-bold text-rose-900">
                                <td class="py-3 px-6 text-sm">{{ $t('rep2_reports_financial.total_cost_of_sales') }}</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-rose-700">({{ Number(incomeStatement.cogs).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}) ﷼</td>
                            </tr>

                            <!-- Gross Profit -->
                            <tr class="bg-indigo-50/70 border-b-2 border-indigo-200 font-black text-indigo-950 text-base">
                                <td class="py-4 px-6">{{ $t('rep2_reports_financial.gross_profit_2') }}</td>
                                <td class="py-4 px-4 text-sm font-semibold text-indigo-700">{{ incomeStatement.total_revenue > 0 ? Math.round((incomeStatement.gross_profit / incomeStatement.total_revenue) * 100) : 0 }}% من الإيرادات</td>
                                <td class="py-4 px-4 text-left text-lg font-black text-indigo-900">{{ Number(incomeStatement.gross_profit).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼</td>
                            </tr>

                            <!-- Expenses Section -->
                            <tr class="bg-slate-100 font-bold text-slate-800 border-b border-slate-300">
                                <td colspan="2" class="py-3 px-4 text-base pt-6">{{ $t('rep2_reports_financial.3_operating_and_administrative_e') }}</td>
                                <td class="py-3 px-4 text-left"></td>
                            </tr>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-8 text-sm text-slate-700">{{ $t('rep2_reports_financial.operating_rent_and_utilities_exp') }}</td>
                                <td class="py-3 px-4 text-sm font-semibold text-rose-700">({{ Number(incomeStatement.operating_expenses).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}) ﷼</td>
                                <td class="py-3 px-4"></td>
                            </tr>
                            <tr class="border-b-2 border-slate-300 bg-rose-50/30 font-bold text-rose-900">
                                <td class="py-3 px-6 text-sm">{{ $t('rep2_reports_financial.total_operating_expenses') }}</td>
                                <td class="py-3 px-4 text-sm"></td>
                                <td class="py-3 px-4 text-left text-base font-black text-rose-700">({{ Number(incomeStatement.operating_expenses).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}) ﷼</td>
                            </tr>

                            <!-- Net Profit -->
                            <tr class="bg-emerald-50/80 border-t-4 border-double border-emerald-500 font-black text-emerald-950 text-lg">
                                <td class="py-5 px-6">{{ $t('rep2_reports_financial.net_operating_profit_for_the_per') }}</td>
                                <td class="py-5 px-4 text-sm font-bold text-emerald-700">{{ $t('rep2_reports_financial.net_profit_margin_n', { n0: incomeStatement.net_margin }) }}</td>
                                <td class="py-5 px-4 text-left text-2xl font-black text-emerald-700">
                                    {{ Number(incomeStatement.net_profit).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} ﷼
                                </td>
                            </tr>
                        </tbody>
                    </table></div>
                </div>

                <!-- Footer Signatures for Official Financial Statement -->
                <div class="p-8 border-t border-slate-100 bg-slate-50/40 grid grid-cols-1 md:grid-cols-3 gap-6 text-center text-xs text-slate-500">
                    <div>
                        <div class="font-bold text-slate-700 mb-6">{{ $t('rep2_reports_financial.prepared_by_the_accountant') }}</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700 mb-6">{{ $t('rep2_reports_financial.internal_review_and_audit') }}</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700 mb-6">{{ $t('rep2_reports_financial.finance_management_approval') }}</div>
                        <div class="border-b border-dashed border-slate-300 w-40 mx-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
