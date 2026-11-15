<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money, qty } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ type: String, filters: Object, report: Object, columns: Object });
const types = computed(() => ({
    profitability: [t('eq2_equipment_reports.equipment_profitability'), t('eq2_equipment_reports.revenue_and_charges_to_projects')],
    utilization: [t('eq2_equipment_reports.utilization_rate'), t('eq2_equipment_reports.actual_working_hours_against_ava')],
    compliance: [t('eq2_equipment_reports.maintenance_compliance'), t('eq2_equipment_reports.items_done_on_time_against_late')],
    parts: [t('eq2_equipment_reports.spare_parts_consumption'), t('eq2_equipment_reports.items_issued_for_maintenance_by')],
}));
const f = reactive({ ...props.filters });
const go = (type = props.type) => router.get(route('equipment.reports.index'), { type, ...f }, { preserveState: false });
const exportUrl = computed(() => route('equipment.reports.export', { type: props.type, ...f }));

const moneyKeys = ['revenue', 'recovery', 'income', 'maintenance', 'rent_in', 'depreciation', 'costs', 'net', 'cost_per_hour', 'parts_cost', 'amount'];
const percentKeys = ['margin', 'utilization', 'on_time_rate'];
const cell = (key, value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (moneyKeys.includes(key)) return money(value);
    if (percentKeys.includes(key)) return `${value}%`;
    if (typeof value === 'number') return qty(value);
    return value;
};
const tone = (key, row) => {
    if (key === 'net' || key === 'margin') return Number(row[key]) < 0 ? 'text-rose-600 font-bold' : 'text-emerald-700 font-bold';
    if (key === 'late' || key === 'overdue_now') return Number(row[key]) > 0 ? 'text-rose-600 font-bold' : '';
    if (key === 'utilization') return Number(row[key]) < 40 ? 'text-amber-700 font-bold' : 'text-emerald-700 font-bold';
    return '';
};
const linkable = (key) => ['code', 'name', 'equipment'].includes(key);
const rowId = (row) => row.equipment_id || row.id;

// Short management readout above the table.
const insights = computed(() => {
    const rows = props.report.rows;
    if (!rows.length) return [];
    if (props.type === 'profitability') {
        const losing = rows.filter((r) => r.net < 0);
        const best = [...rows].sort((a, b) => b.net - a.net)[0];
        return [t('eq2_equipment_reports.fleet_net_profit_n', { n0: money(props.report.totals.net) }), best && t('eq2_equipment_reports.most_profitable_n_n_n', { n0: best.code, n1: best.name, n2: money(best.net) }),
            losing.length && t('eq2_equipment_reports.loss_making_equipment_n_review_t', { n0: losing.map((r) => r.code).join(t('z_equipment_reports.text')) })].filter(Boolean);
    }
    if (props.type === 'utilization') {
        const t = props.report.totals;
        const idle = rows.filter((r) => r.utilization < 40);
        return [t('eq2_equipment_reports.average_utilization_n_n_hours_da', { n0: t.available_hours ? ((t.worked_hours / t.available_hours) * 100).toFixed(1) : 0, n1: props.report.standard_hours, n2: props.report.days }),
            idle.length && t('eq2_equipment_reports.equipment_utilized_under_40_n_an', { n0: idle.map((r) => r.code).join(t('z_equipment_reports.text')) })].filter(Boolean);
    }
    if (props.type === 'compliance') {
        const t = props.report.totals;
        return [t('eq2_equipment_reports.n_items_done_n_of_them_late_n_co', { n0: t.serviced, n1: t.late, n2: t.serviced ? Math.round(((t.serviced - t.late) / t.serviced) * 100) : 100 }),
            t.overdue_now && t('eq2_equipment_reports.n_items_overdue_now', { n0: t.overdue_now }), t('eq2_equipment_reports.breakdown_repair_orders_n_agains', { n0: t.corrective, n1: t.preventive })].filter(Boolean);
    }
    const top = props.report.by_product?.[0];
    return [t('eq2_equipment_reports.total_spare_parts_n', { n0: money(props.report.totals.amount) }), top && t('eq2_equipment_reports.highest_cost_n_n', { n0: top.product, n1: money(top.amount) })].filter(Boolean);
});
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_reports.equipment_reports')" />
        <PageHeader :title="$t('eq2_equipment_reports.equipment_reports')" :subtitle="types[type][1]" :crumbs="[{ label: $t('eq2_equipment_reports.equipment'), href: route('equipment.index') }, { label: $t('eq2_equipment_reports.reports') }]">
            <template #actions>
                <a v-if="$can('equipment.reports.export')" :href="exportUrl" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_reports.export_excel_csv') }}</a>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-1 border-b border-slate-200 mb-4 text-xs" role="tablist">
            <button v-for="(t, k) in types" :key="k" role="tab" :aria-selected="type === k" @click="go(k)" :class="['px-4 py-2 font-bold border-b-2 -mb-px', type === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500']">{{ t[0] }}</button>
        </div>

        <form @submit.prevent="go()" class="flex flex-wrap items-end gap-3 mb-4 text-xs">
            <label class="font-bold text-slate-600">{{ $t('eq2_equipment_reports.from') }} <DatePicker v-model="f.from" class="block rounded-xl border-slate-200 text-xs" /></label>
            <label class="font-bold text-slate-600">{{ $t('eq2_equipment_reports.to') }} <DatePicker v-model="f.to" class="block rounded-xl border-slate-200 text-xs" /></label>
            <label v-if="type === 'utilization'" class="font-bold text-slate-600">{{ $t('eq2_equipment_reports.standard_hours_per_day') }} <input v-model.number="f.standard_hours" type="text" inputmode="decimal" data-num min="1" max="24" class="block rounded-xl border-slate-200 text-xs w-24" /></label>
            <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('eq2_equipment_reports.view') }}</button>
        </form>

        <ul v-if="insights.length" class="mb-4 rounded-2xl bg-indigo-50 border border-indigo-100 p-4 text-xs text-indigo-900 space-y-1 list-disc ps-8">
            <li v-for="(i, n) in insights" :key="n">{{ i }}</li>
        </ul>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th v-for="(l, k) in columns" :key="k" class="px-3 py-2 text-start whitespace-nowrap">{{ l }}</th></tr></thead>
                <tbody>
                    <tr v-for="(row, n) in report.rows" :key="n" class="border-t border-slate-100">
                        <td v-for="(l, k) in columns" :key="k" :class="['px-3 py-2 whitespace-nowrap', typeof row[k] === 'number' ? 'font-mono' : '', tone(k, row)]">
                            <Link v-if="linkable(k) && rowId(row)" :href="route('equipment.show', rowId(row))" class="hover:text-indigo-700">{{ cell(k, row[k]) }}</Link>
                            <template v-else-if="k === 'utilization'">
                                <div class="flex items-center gap-2"><div class="w-24 h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full bg-indigo-500" :style="{ width: `${Math.min(100, row[k])}%` }"></div></div>{{ cell(k, row[k]) }}</div>
                            </template>
                            <template v-else>{{ cell(k, row[k]) }}</template>
                        </td>
                    </tr>
                    <tr v-if="!report.rows.length"><td :colspan="Object.keys(columns).length" class="px-3 py-10 text-center text-slate-400">{{ $t('eq2_equipment_reports.no_data_in_this_period') }}</td></tr>
                </tbody>
                <tfoot v-if="report.rows.length && report.totals" class="bg-slate-50 font-bold">
                    <tr><td v-for="(l, k) in columns" :key="k" class="px-3 py-2 font-mono whitespace-nowrap">{{ k in report.totals ? cell(k, report.totals[k]) : (k === Object.keys(columns)[0] ? $t('eq2_equipment_reports.total') : '') }}</td></tr>
                </tfoot>
            </table>
        </div>

        <section v-if="type === 'parts' && report.by_product?.length" class="mt-4 text-xs">
            <h3 class="font-black text-slate-700 mb-2">{{ $t('eq2_equipment_reports.by_item') }}</h3>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_reports.item') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_reports.quantity') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_reports.cost') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_reports.number_of_equipment') }}</th></tr></thead>
                    <tbody><tr v-for="p in report.by_product" :key="p.product" class="border-t border-slate-100"><td class="px-3 py-2">{{ p.product }} <span class="text-slate-400 font-mono">{{ p.sku }}</span></td><td class="px-3 py-2 font-mono">{{ qty(p.quantity) }}</td><td class="px-3 py-2 font-mono">{{ money(p.amount) }}</td><td class="px-3 py-2">{{ p.equipment_count }}</td></tr></tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
