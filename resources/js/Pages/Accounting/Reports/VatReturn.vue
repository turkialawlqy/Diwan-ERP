<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { money, amount } from '@/utils/format';

const props = defineProps({ report: Object, filters: Object, byBranch: { type: Array, default: () => [] } });
// The ZATCA form is Saudi; every company also gets the summary per branch / country.
const saudi = computed(() => (usePage().props.tenant?.countries || ['SA']).includes('SA'));
const multi = computed(() => props.byBranch.length > 1 || props.byBranch.some((b) => b.country !== 'SA'));
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head title="إقرار ضريبة القيمة المضافة" />
        <PageHeader title="إقرار ضريبة القيمة المضافة" :subtitle="`الفترة من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'إقرار الضريبة' }]">
            <template #actions><button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button></template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.reports.vat-return" :filters="filters" />

        <div v-if="multi || !saudi" class="mb-6 max-w-5xl space-y-3" data-tax-by-branch>
            <h3 class="font-black text-sm text-slate-800">ملخص الضريبة حسب الفرع والدولة</h3>
            <p v-if="!byBranch.length" class="text-xs text-slate-400">لا توجد مستندات في هذه الفترة.</p>
            <div v-for="b in byBranch" :key="b.branch_id + b.currency" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <p class="font-black text-slate-800">{{ b.branch }} <span class="text-slate-500 font-bold">— {{ b.country_name }}</span></p>
                    <span class="px-2 py-0.5 rounded-lg bg-slate-100 font-mono font-bold">{{ b.currency }}</span>
                </div>
                <table class="w-full">
                    <thead class="text-slate-500"><tr><th class="text-start py-1">البند</th><th class="text-end py-1">النسبة</th><th class="text-end py-1">المبلغ</th><th class="text-end py-1">{{ b.tax_name }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in b.sales" :key="'s' + r.rate" class="border-t border-slate-100"><td class="py-1">مبيعات</td><td class="text-end font-mono">{{ r.rate }}%</td><td class="text-end font-mono">{{ money(r.amount) }}</td><td class="text-end font-mono">{{ money(r.tax) }}</td></tr>
                        <tr v-for="r in b.purchases" :key="'p' + r.rate" class="border-t border-slate-100"><td class="py-1">مشتريات</td><td class="text-end font-mono">{{ r.rate }}%</td><td class="text-end font-mono">{{ money(r.amount) }}</td><td class="text-end font-mono">{{ money(r.tax) }}</td></tr>
                    </tbody>
                    <tfoot class="font-bold">
                        <tr class="border-t-2 border-slate-200"><td class="py-1" colspan="3">ضريبة المخرجات − ضريبة المدخلات</td><td class="text-end font-mono">{{ money(b.output) }} − {{ money(b.input) }}</td></tr>
                        <tr :class="b.net >= 0 ? 'text-rose-700' : 'text-emerald-700'"><td class="py-1" colspan="3">{{ b.net >= 0 ? 'صافي المستحق' : 'صافي القابل للاسترداد' }}</td><td class="text-end font-mono font-black">{{ amount(b.net) }} {{ b.currency }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <h3 v-if="saudi && multi" class="font-black text-sm text-slate-800 mb-2">نموذج إقرار هيئة الزكاة والضريبة والجمارك (فروع السعودية وبالعملة الأساسية)</h3>
        <div v-if="saudi" class="bg-white border border-slate-200 rounded-2xl overflow-hidden max-w-5xl">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 w-12">#</th><th class="text-start px-3 py-2">البند</th><th class="text-end px-3 py-2">المبلغ ({{ $cur() }})</th><th class="text-end px-3 py-2">مبلغ التعديل</th><th class="text-end px-3 py-2">مبلغ ضريبة القيمة المضافة</th></tr>
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

        <div v-if="saudi" :class="[report.ledger_matches ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700', 'mt-4 max-w-5xl border rounded-xl px-4 py-3 text-xs']">
            <p class="font-bold">{{ report.ledger_matches ? '✓ الإقرار مطابق لحسابات الضريبة في الأستاذ العام' : '⚠ يوجد فرق بين الإقرار وحسابات الضريبة في الأستاذ العام' }}</p>
            <p class="mt-1">ضريبة المخرجات بالدفاتر: <span class="font-mono">{{ money(report.ledger_output_vat) }}</span> · ضريبة المدخلات بالدفاتر: <span class="font-mono">{{ money(report.ledger_input_vat) }}</span></p>
        </div>
    </AppLayout>
</template>
