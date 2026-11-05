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
        <Head title="إقرار ضريبة الاستقطاع" />
        <PageHeader title="إقرار ضريبة الاستقطاع" :subtitle="`من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'إقرار الضريبة' }, { label: 'الاستقطاع' }]">
            <template #actions>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold print:hidden">{{ $t('ui.print') }}</button>
                <ExportButtons report="withholding" :params="{ start_date: filters.start_date, end_date: filters.end_date }" permission="accounting.vat_return.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.withholding" :filters="filters" />

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-4 py-2">التاريخ</th><th class="px-3 py-2 text-start">السند</th><th class="px-3 py-2 text-start">المورد</th><th class="px-3 py-2 text-start">الدولة</th><th class="px-3 py-2 text-start">نوع الدفعة</th><th class="px-3 py-2 text-end">المبلغ الإجمالي</th><th class="px-3 py-2 text-end">النسبة</th><th class="px-3 py-2 text-end">المستقطع</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in report.rows" :key="i" class="border-t border-slate-100" data-wht-row>
                        <td class="px-4 py-1.5 font-mono">{{ r.date }}</td><td class="px-3 py-1.5 font-mono">{{ r.number }}</td><td class="px-3 py-1.5 font-bold">{{ r.supplier }}</td><td class="px-3 py-1.5">{{ r.country }}</td><td class="px-3 py-1.5">{{ r.category }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ amount(r.gross) }}</td><td class="px-3 py-1.5 text-end font-mono">{{ r.rate }}%</td><td class="px-3 py-1.5 text-end font-mono font-bold">{{ amount(r.withheld) }}</td>
                    </tr>
                    <tr v-if="!report.rows.length"><td colspan="8" class="px-4 py-6 text-center text-slate-400">لا مدفوعات خاضعة للاستقطاع في هذه الفترة</td></tr>
                </tbody>
                <tfoot class="font-black bg-slate-50">
                    <tr class="border-t-2 border-slate-200"><td colspan="5" class="px-4 py-2">الإجمالي (الواجب توريده للهيئة)</td><td class="px-3 py-2 text-end font-mono">{{ amount(report.totals.gross) }}</td><td></td><td class="px-3 py-2 text-end font-mono">{{ amount(report.totals.withheld) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
