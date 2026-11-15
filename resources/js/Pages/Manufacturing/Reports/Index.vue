<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const props = defineProps({ report: String, filters: Object, data: [Object, Array] });
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const tabs = computed(() => ([['summary', t('mfg_manufacturing_reports_index.production_summary')], ['wip', t('mfg_manufacturing_reports_index.work_in_progress')], ['materials', t('mfg_manufacturing_reports_index.material_consumption')], ['utilization', t('mfg_manufacturing_reports_index.work_center_utilization')]]));
const go = (report = props.report) => router.get(route('manufacturing.reports.index'), { report, from: from.value, to: to.value }, { preserveState: true, replace: true });
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_reports_index.manufacturing_reports')" />
        <PageHeader :title="$t('mfg_manufacturing_reports_index.manufacturing_reports')" :crumbs="[{ label: $t('mfg_manufacturing_reports_index.manufacturing') }, { label: $t('mfg_manufacturing_reports_index.reports') }]" />
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <button v-for="[k, l] in tabs" :key="k" type="button" @click="go(k)" :class="report === k ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200'" class="px-3 py-1.5 rounded-full text-xs font-bold">{{ l }}</button>
            <span class="ms-auto flex min-w-0 items-center gap-2 text-xs"><DatePicker v-model="from" class="text-xs rounded-xl border-slate-200" @change="go()" /> → <DatePicker v-model="to" class="text-xs rounded-xl border-slate-200" @change="go()" /></span>
        </div>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <template v-if="report === 'summary'">
                <table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.orders') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.quantity') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.scrap') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.cost') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.variances') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="r in data.by_product" :key="r.product"><td class="px-4 py-3 font-bold">{{ r.product }}</td><td class="px-4 py-3 font-mono">{{ r.orders }}</td><td class="px-4 py-3 font-mono">{{ num(r.quantity) }}</td><td class="px-4 py-3 font-mono">{{ num(r.scrap) }}</td><td class="px-4 py-3 font-mono">{{ money(r.cost) }}</td><td class="px-4 py-3 font-mono">{{ money(r.variance) }}</td></tr>
                    <tr v-if="!data.by_product.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_reports_index.no_orders_finished_in_the_period') }}</td></tr></tbody>
                    <tfoot v-if="data.by_product.length" class="bg-slate-50 font-black"><tr><td class="px-4 py-3">{{ $t('mfg_manufacturing_reports_index.total_n_orders', { n0: data.orders }) }}</td><td></td><td class="px-4 py-3 font-mono">{{ num(data.totals.quantity) }}</td><td class="px-4 py-3 font-mono">{{ num(data.totals.scrap) }}</td><td class="px-4 py-3 font-mono">{{ money(data.totals.cost) }}</td><td class="px-4 py-3 font-mono">{{ money(data.totals.variance) }}</td></tr></tfoot></table>
            </template>
            <template v-else-if="report === 'wip'">
                <table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.order') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.materials') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.wages') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.expenses') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.in_progress') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.age_days') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="r in data" :key="r.id"><td class="px-4 py-3 font-mono font-bold">{{ r.number }}</td><td class="px-4 py-3">{{ r.product }}</td><td class="px-4 py-3 font-mono">{{ money(r.material) }}</td><td class="px-4 py-3 font-mono">{{ money(r.labour) }}</td><td class="px-4 py-3 font-mono">{{ money(r.overhead) }}</td><td class="px-4 py-3 font-mono font-black">{{ money(r.wip) }}</td><td class="px-4 py-3 font-mono">{{ r.age_days }}</td></tr>
                    <tr v-if="!data.length"><td colspan="7" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_reports_index.no_outstanding_wip_cost') }}</td></tr></tbody></table>
            </template>
            <template v-else-if="report === 'materials'">
                <table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.material') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.planned') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.consumed') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.quantity_variance') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.cost') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="r in data" :key="r.product"><td class="px-4 py-3 font-bold">{{ r.product }}</td><td class="px-4 py-3 font-mono">{{ num(r.planned) }}</td><td class="px-4 py-3 font-mono">{{ num(r.used) }}</td><td class="px-4 py-3 font-mono" :class="r.variance_qty > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ num(r.variance_qty) }}</td><td class="px-4 py-3 font-mono">{{ money(r.cost) }}</td></tr>
                    <tr v-if="!data.length"><td colspan="5" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_reports_index.no_data') }}</td></tr></tbody></table>
            </template>
            <template v-else>
                <table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.work_center') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.recorded_minutes') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.capacity_min') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_reports_index.utilization') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="r in data" :key="r.code"><td class="px-4 py-3 font-bold">{{ r.name }} <span class="font-mono text-slate-400">{{ r.code }}</span></td><td class="px-4 py-3 font-mono">{{ num(r.minutes) }}</td><td class="px-4 py-3 font-mono">{{ num(r.capacity_minutes) }}</td><td class="px-4 py-3 font-mono font-black">{{ r.pct }}%</td></tr>
                    <tr v-if="!data.length"><td colspan="4" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_reports_index.no_work_centers') }}</td></tr></tbody></table>
            </template>
        </div>
    </AppLayout>
</template>
