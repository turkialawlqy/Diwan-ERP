<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { money, amount } from '@/utils/format';

defineProps({ report: Object, filters: Object });
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head title="إقرار ضريبة القيمة المضافة" />
        <PageHeader title="إقرار ضريبة القيمة المضافة" :subtitle="`الفترة من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'إقرار الضريبة' }]">
            <template #actions><button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button></template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.reports.vat-return" :filters="filters" />

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden max-w-5xl">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 w-12">#</th><th class="text-start px-3 py-2">البند</th><th class="text-end px-3 py-2">المبلغ (ريال)</th><th class="text-end px-3 py-2">مبلغ التعديل</th><th class="text-end px-3 py-2">مبلغ ضريبة القيمة المضافة</th></tr>
                </thead>
                <tbody>
                    <tr v-for="box in report.boxes" :key="box.no" class="border-t border-slate-100">
                        <td class="px-3 py-2 text-center font-mono text-slate-400">{{ box.no }}</td>
                        <td class="px-3 py-2 font-semibold text-slate-700">{{ box.label }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(box.amount) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ amount(box.adjustment) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(box.vat) }}</td>
                    </tr>
                </tbody>
                <tfoot class="font-black">
                    <tr class="border-t-2 border-slate-200"><td></td><td class="px-3 py-2">إجمالي ضريبة المخرجات المستحقة</td><td></td><td></td><td class="px-3 py-2 text-end font-mono">{{ money(report.output_vat) }}</td></tr>
                    <tr><td></td><td class="px-3 py-2">إجمالي ضريبة المدخلات القابلة للاسترداد</td><td></td><td></td><td class="px-3 py-2 text-end font-mono">{{ money(report.input_vat) }}</td></tr>
                    <tr :class="report.net_vat >= 0 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'">
                        <td></td><td class="px-3 py-3">{{ report.net_vat >= 0 ? 'صافي الضريبة المستحقة الدفع' : 'صافي الضريبة القابلة للاسترداد' }}</td><td></td><td></td>
                        <td class="px-3 py-3 text-end font-mono text-base">{{ amount(report.net_vat) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div :class="[report.ledger_matches ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700', 'mt-4 max-w-5xl border rounded-xl px-4 py-3 text-xs']">
            <p class="font-bold">{{ report.ledger_matches ? '✓ الإقرار مطابق لحسابات الضريبة في الأستاذ العام' : '⚠ يوجد فرق بين الإقرار وحسابات الضريبة في الأستاذ العام' }}</p>
            <p class="mt-1">ضريبة المخرجات بالدفاتر: <span class="font-mono">{{ money(report.ledger_output_vat) }}</span> · ضريبة المدخلات بالدفاتر: <span class="font-mono">{{ money(report.ledger_input_vat) }}</span></p>
        </div>
    </AppLayout>
</template>
