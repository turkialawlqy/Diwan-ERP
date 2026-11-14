<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { money, amount } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ report: Object, filters: Object, byBranch: { type: Array, default: () => [] }, settlement: Object });
// The ZATCA form is Saudi; every company also gets the summary per branch / country.
const saudi = computed(() => (usePage().props.tenant?.countries || ['SA']).includes('SA'));
const multi = computed(() => props.byBranch.length > 1 || props.byBranch.some((b) => b.country !== 'SA'));
const printPage = () => window.print();
const settle = () => {
    if (confirm(t('a_vat.confirm_settle'))) {
        router.post(route('accounting.reports.vat-return.settle'), { start_date: props.filters.start_date, end_date: props.filters.end_date });
    }
};
const cancelSettlement = () => {
    if (confirm(t('a_vat.confirm_cancel'))) router.delete(route('accounting.reports.vat-settlements.cancel', props.settlement.settlement.id));
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_vat.vat_return')" />
        <PageHeader :title="$t('a_vat.vat_return')" :subtitle="$t('a_rep.period_range', { from: filters.start_date, to: filters.end_date })" :crumbs="[{ label: 'المحاسبة' }, { label: 'إقرار الضريبة' }]">
            <template #actions>
                <button v-if="!settlement.settlement && $can('accounting.vat_return.settle')" @click="settle" data-settle class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('a_vat.settle_the_return') }}</button>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button>
                <ExportButtons report="vat-return" :params="{ start_date: filters.start_date, end_date: filters.end_date }" permission="accounting.vat_return.export" />
            </template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.reports.vat-return" :filters="filters" />

        <div v-if="settlement.settlement" class="mb-4 border border-emerald-200 bg-emerald-50 text-emerald-800 rounded-xl px-4 py-3 text-xs flex flex-wrap items-center gap-3" data-settled>
            <span class="font-bold">✓ {{ $t('a_vat.settled') }} ({{ String(settlement.settlement.start_date).substring(0, 10) }} → {{ String(settlement.settlement.end_date).substring(0, 10) }}) — صافي {{ amount(settlement.settlement.net_amount) }}</span>
            <a v-if="settlement.settlement.journal_entry" :href="route('accounting.journals.show', settlement.settlement.journal_entry.id)" class="underline font-mono">{{ settlement.settlement.journal_entry.entry_number }}</a>
            <button v-if="$can('accounting.vat_return.settle')" @click="cancelSettlement" class="ms-auto text-rose-600 font-bold">{{ $t('a_vat.cancel_the_settlement') }}</button>
        </div>

        <div v-if="multi || !saudi" class="mb-6 space-y-3" data-tax-by-branch>
            <h3 class="font-black text-sm text-slate-800">{{ $t('a_vat.tax_summary_by_branch_and_countr') }}</h3>
            <p v-if="!byBranch.length" class="text-xs text-slate-400">{{ $t('a_vat.no_documents_in_this_period') }}</p>
            <div v-for="b in byBranch" :key="b.branch_id + b.currency" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <p class="font-black text-slate-800">{{ b.branch }} <span class="text-slate-500 font-bold">— {{ b.country_name }}</span></p>
                    <span class="px-2 py-0.5 rounded-lg bg-slate-100 font-mono font-bold">{{ b.currency }}</span>
                </div>
                <table class="w-full">
                    <thead class="text-slate-500"><tr><th class="text-start py-1">{{ $t('a_vat.item') }}</th><th class="text-end py-1">{{ $t('a_vat.rate') }}</th><th class="text-end py-1">{{ $t('a_vat.amount') }}</th><th class="text-end py-1">{{ b.tax_name }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in b.sales" :key="'s' + r.rate" class="border-t border-slate-100"><td class="py-1">{{ $t('a_vat.sales') }}</td><td class="text-end font-mono">{{ r.rate }}%</td><td class="text-end font-mono">{{ money(r.amount) }}</td><td class="text-end font-mono">{{ money(r.tax) }}</td></tr>
                        <tr v-for="r in b.purchases" :key="'p' + r.rate" class="border-t border-slate-100"><td class="py-1">{{ $t('a_vat.purchases') }}</td><td class="text-end font-mono">{{ r.rate }}%</td><td class="text-end font-mono">{{ money(r.amount) }}</td><td class="text-end font-mono">{{ money(r.tax) }}</td></tr>
                    </tbody>
                    <tfoot class="font-bold">
                        <tr class="border-t-2 border-slate-200"><td class="py-1" colspan="3">{{ $t('a_vat.output_tax_input_tax') }}</td><td class="text-end font-mono">{{ money(b.output) }} − {{ money(b.input) }}</td></tr>
                        <tr :class="b.net >= 0 ? 'text-rose-700' : 'text-emerald-700'"><td class="py-1" colspan="3">{{ b.net >= 0 ? $t('a_vat.net_due') : $t('a_vat.net_refund') }}</td><td class="text-end font-mono font-black">{{ amount(b.net) }} {{ b.currency }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <h3 v-if="saudi && multi" class="font-black text-sm text-slate-800 mb-2">{{ $t('a_vat.zatca_return_form_saudi_branches') }}</h3>
        <div v-if="saudi" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 w-12">#</th><th class="text-start px-3 py-2">{{ $t('a_vat.item') }}</th><th class="text-end px-3 py-2">{{ $t('a_rep.amount_cur', { cur: $cur() }) }}</th><th class="text-end px-3 py-2">{{ $t('a_vat.adjustment_amount') }}</th><th class="text-end px-3 py-2">{{ $t('a_vat.vat_amount') }}</th></tr>
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
                    <tr class="border-t-2 border-slate-200"><td></td><td class="px-3 py-2">{{ $t('a_vat.total_output_tax_due') }}</td><td></td><td></td><td class="px-3 py-2 text-end font-mono">{{ money(report.output_vat) }}</td></tr>
                    <tr><td></td><td class="px-3 py-2">{{ $t('a_vat.total_recoverable_input_tax') }}</td><td></td><td></td><td class="px-3 py-2 text-end font-mono">{{ money(report.input_vat) }}</td></tr>
                    <tr :class="report.net_vat >= 0 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'">
                        <td></td><td class="px-3 py-3">{{ report.net_vat >= 0 ? $t('a_vat.net_vat_due') : $t('a_vat.net_vat_refund') }}</td><td></td><td></td>
                        <td class="px-3 py-3 text-end font-mono text-base">{{ amount(report.net_vat) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="saudi" :class="[report.ledger_matches ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700', 'mt-4 max-w-5xl border rounded-xl px-4 py-3 text-xs']">
            <p class="font-bold">{{ report.ledger_matches ? $t('a_vat.matches') : $t('a_vat.differs') }}</p>
            <p class="mt-1">{{ $t('a_vat.output_tax_per_the_books') }} <span class="font-mono">{{ money(report.ledger_output_vat) }}</span> {{ $t('a_vat.input_tax_per_the_books') }} <span class="font-mono">{{ money(report.ledger_input_vat) }}</span></p>
        </div>
    </AppLayout>
</template>
