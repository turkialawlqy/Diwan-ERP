<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ report: Object, filters: Object });
const labels = computed(() => ({ operating: t('a_cashflow.operating'), investing: t('a_cashflow.investing'), financing: t('a_cashflow.financing') }));
const indirect = computed(() => props.filters.method === 'indirect' && props.report.indirect);
const setMethod = (method) => router.get(route('accounting.reports.cash-flow'), { start_date: props.filters.start_date, end_date: props.filters.end_date, method }, { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_cashflow.cash_flow_statement')" />
        <PageHeader :title="`${$t('a_cashflow.cash_flow_statement')} (${indirect ? $t('a_cashflow.indirect_m') : $t('a_cashflow.direct_m')})`" :subtitle="$t('a_rep.range', { from: filters.start_date, to: filters.end_date })" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]">
            <template #actions>
                <div class="inline-flex rounded-xl border border-slate-200 bg-white p-0.5 text-xs font-bold print:hidden">
                    <button v-for="[key, label] in [['direct', $t('a_cashflow.direct')], ['indirect', $t('a_cashflow.indirect')]]" :key="key" @click="setMethod(key)"
                        :class="(filters.method || 'direct') === key ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="px-3 py-1.5 rounded-lg">{{ label }}</button>
                </div>
                <ExportButtons report="cash-flow" :params="{ start_date: filters.start_date, end_date: filters.end_date, method: filters.method }" permission="accounting.statements.export" />
            </template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.reports.cash-flow" :filters="filters" :extra="{ method: filters.method }" />

        <div class="bg-white border border-slate-200 rounded-2xl p-5">
            <template v-if="indirect">
                <div class="mb-4">
                    <h3 class="font-black text-sm text-slate-800 bg-slate-50 px-4 py-2 rounded-xl">{{ labels.operating }}</h3>
                    <div class="overflow-x-auto"><table class="w-full text-xs mt-1" data-indirect>
                        <tbody>
                            <tr class="border-b border-slate-50 font-bold"><td class="px-4 py-1.5">{{ $t('a_cashflow.net_profit_for_the_period') }}</td><td class="px-4 py-1.5 text-end font-mono w-40">{{ amount(indirect.net_profit) }}</td></tr>
                            <tr><td colspan="2" class="px-4 pt-2 text-slate-500 font-bold">{{ $t('a_cashflow.adjustments_for_non_cash_items') }}</td></tr>
                            <tr v-for="(r, i) in indirect.adjustments" :key="'a' + i" class="border-b border-slate-50"><td class="px-6 py-1.5">{{ r.name }}</td><td class="px-4 py-1.5 text-end font-mono">{{ amount(r.amount) }}</td></tr>
                            <tr v-if="!indirect.adjustments.length"><td colspan="2" class="px-6 py-1.5 text-slate-400">—</td></tr>
                            <tr><td colspan="2" class="px-4 pt-2 text-slate-500 font-bold">{{ $t('a_cashflow.changes_in_working_capital_an_in') }}</td></tr>
                            <tr v-for="r in indirect.working_capital" :key="'w' + r.id" class="border-b border-slate-50"><td class="px-6 py-1.5"><span class="font-mono text-slate-400">{{ r.code }}</span> {{ r.name }}</td><td class="px-4 py-1.5 text-end font-mono">{{ amount(r.amount) }}</td></tr>
                            <tr v-if="!indirect.working_capital.length"><td colspan="2" class="px-6 py-1.5 text-slate-400">—</td></tr>
                            <tr v-if="Math.abs(indirect.non_cash) >= 0.01" class="border-b border-slate-50 text-amber-700">
                                <td class="px-4 py-1.5">{{ $t('a_cashflow.non_cash_investing_and_financing') }} <span class="text-3xs">{{ $t('a_cashflow.such_as_buying_an_asset_on_credi') }}</span></td>
                                <td class="px-4 py-1.5 text-end font-mono">{{ amount(indirect.non_cash) }}</td>
                            </tr>
                        </tbody>
                        <tfoot><tr class="font-black text-slate-900"><td class="px-4 py-2">{{ $t('a_cashflow.net_operating_cash_flow') }}</td><td class="px-4 py-2 text-end font-mono border-t-2 border-slate-300">{{ amount(indirect.operating_total) }}</td></tr></tfoot>
                    </table></div>
                    <p class="px-4 text-3xs text-emerald-700">{{ $t('a_cashflow.matches_the_net_operating_cash_f') }}</p>
                </div>
                <StatementSection v-for="key in ['investing', 'financing']" :key="key" :title="labels[key]" :rows="report.sections[key].rows" :total="report.sections[key].total" :total-label="$t('a_cashflow.net_flow')" />
            </template>
            <StatementSection v-else v-for="(section, key) in report.sections" :key="key" :title="labels[key]" :rows="section.rows" :total="section.total" :total-label="$t('a_cashflow.net_flow')" />
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ $t('a_cashflow.cash_at_start_of_period') }}</p><p class="font-mono font-black mt-1">{{ amount(report.opening_cash) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ $t('a_cashflow.net_change_in_cash') }}</p><p class="font-mono font-black mt-1">{{ amount(report.net_change) }}</p></div>
                <div class="rounded-xl bg-indigo-50 text-indigo-700 p-3"><p>{{ $t('a_cashflow.cash_at_end_of_period') }}</p><p class="font-mono font-black mt-1">{{ amount(report.closing_cash) }}</p></div>
            </div>
        </div>
    </AppLayout>
</template>
