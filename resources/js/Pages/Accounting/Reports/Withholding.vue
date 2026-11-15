<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { amount } from '@/utils/format';

defineProps({ report: Object, filters: Object });
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_wht.withholding_tax_return')" />
        <PageHeader :title="$t('a_wht.withholding_tax_return')" :subtitle="$t('a_rep.range', { from: filters.start_date, to: filters.end_date })" :crumbs="[{ label: 'المحاسبة' }, { label: 'إقرار الضريبة' }, { label: 'الاستقطاع' }]">
            <template #actions>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold print:hidden">{{ $t('ui.print') }}</button>
                <ExportButtons report="withholding" :params="{ start_date: filters.start_date, end_date: filters.end_date }" permission="accounting.vat_return.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.withholding" :filters="filters" />

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-4 py-2">{{ $t('a_wht.date') }}</th><th class="px-3 py-2 text-start">{{ $t('a_wht.voucher') }}</th><th class="px-3 py-2 text-start">{{ $t('a_wht.supplier') }}</th><th class="px-3 py-2 text-start">{{ $t('a_wht.country') }}</th><th class="px-3 py-2 text-start">{{ $t('a_wht.payment_type') }}</th><th class="px-3 py-2 text-end">{{ $t('a_wht.gross_amount') }}</th><th class="px-3 py-2 text-end">{{ $t('a_wht.rate') }}</th><th class="px-3 py-2 text-end">{{ $t('a_wht.withheld') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in report.rows" :key="i" class="border-t border-slate-100" data-wht-row>
                        <td class="px-4 py-1.5 font-mono">{{ r.date }}</td><td class="px-3 py-1.5 font-mono">{{ r.number }}</td><td class="px-3 py-1.5 font-bold">{{ r.supplier }}</td><td class="px-3 py-1.5">{{ r.country }}</td><td class="px-3 py-1.5">{{ r.category }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ amount(r.gross) }}</td><td class="px-3 py-1.5 text-end font-mono">{{ r.rate }}%</td><td class="px-3 py-1.5 text-end font-mono font-bold">{{ amount(r.withheld) }}</td>
                    </tr>
                    <tr v-if="!report.rows.length"><td colspan="8" class="px-4 py-6 text-center text-slate-400">{{ $t('a_wht.no_payments_subject_to_withholdi') }}</td></tr>
                </tbody>
                <tfoot class="font-black bg-slate-50">
                    <tr class="border-t-2 border-slate-200"><td colspan="5" class="px-4 py-2">{{ $t('a_wht.total_payable_to_the_authority') }}</td><td class="px-3 py-2 text-end font-mono">{{ amount(report.totals.gross) }}</td><td></td><td class="px-3 py-2 text-end font-mono">{{ amount(report.totals.withheld) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
