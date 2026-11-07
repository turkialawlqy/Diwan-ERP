<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { amount } from '@/utils/format';

const props = defineProps({ report: String, table: Object, filterKinds: Array, filters: Object });
const form = reactive({ start_date: props.filters.start_date, end_date: props.filters.end_date, days: props.filters.days, months: props.filters.months, k: props.filters.k, min_amount: props.filters.min_amount });
const exportParams = () => (props.filterKinds.includes('range') ? { start_date: form.start_date, end_date: form.end_date } : props.filterKinds.includes('days') ? { days: form.days } : props.filterKinds.includes('unusual') ? { months: form.months, k: form.k, min_amount: form.min_amount } : {});
const apply = () => router.get(route('accounting.controls.show', props.report), exportParams(), { preserveState: true });
const cell = (value, column) => {
    if (value === null || value === undefined || value === '') return '';
    return column.type === 'money' ? amount(value) : value;
};
</script>

<template>
    <AppLayout>
        <Head :title="table.title" />
        <PageHeader :title="table.title" :subtitle="table.subtitle" :crumbs="[{ label: $t('nav.accounting') }, { label: $t('nav.accounting_controls'), href: route('accounting.controls.index') }, { label: table.title }]">
            <template #actions>
                <ExportButtons :report="report" :params="exportParams()" permission="accounting.controls.export" />
            </template>
        </PageHeader>

        <form v-if="filterKinds.length" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-wrap items-end gap-3 text-xs mb-4" data-control-filters @submit.prevent="apply">
            <template v-if="filterKinds.includes('range')">
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('repgap.from') }}</span><DatePicker v-model="form.start_date" class="rounded-xl border-slate-200 text-xs" /></label>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('repgap.to') }}</span><DatePicker v-model="form.end_date" class="rounded-xl border-slate-200 text-xs" /></label>
            </template>
            <label v-if="filterKinds.includes('days')"><span class="block font-bold text-slate-600 mb-1">{{ $t('controls.days') }}</span><input v-model.number="form.days" type="text" inputmode="numeric" data-num class="w-28 rounded-xl border-slate-200 text-xs" /></label>
            <template v-if="filterKinds.includes('unusual')">
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.months') }}</span><input v-model="form.months" type="text" inputmode="decimal" data-num class="w-24 rounded-xl border-slate-200 text-xs" /></label>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.k') }}</span><input v-model="form.k" type="text" inputmode="decimal" data-num class="w-24 rounded-xl border-slate-200 text-xs" /></label>
                <label><span class="block font-bold text-slate-600 mb-1">{{ $t('aging3.min_amount') }}</span><input v-model="form.min_amount" type="text" inputmode="decimal" data-num class="w-28 rounded-xl border-slate-200 text-xs" /></label>
            </template>
            <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-control-apply>{{ $t('repgap.apply') }}</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden" data-control-table>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr><th v-for="c in table.columns" :key="c.key" :class="[c.type === 'text' ? 'text-start' : 'text-end', 'px-3 py-2']">{{ c.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in table.rows" :key="i" :class="['border-t border-slate-100', table.bold.includes(i) ? 'bg-slate-50 font-black text-slate-800' : 'text-slate-700']">
                            <td v-for="c in table.columns" :key="c.key" :class="[c.type === 'text' ? 'text-start' : 'text-end font-mono', 'px-3 py-2']">{{ cell(row[c.key], c) }}</td>
                        </tr>
                        <tr v-if="!table.rows.length"><td :colspan="table.columns.length" class="py-10 text-center text-slate-400">{{ $t('controls.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <Link :href="route('accounting.controls.index')" class="inline-block mt-4 text-xs font-bold text-indigo-600 hover:underline">{{ $t('controls.back') }}</Link>
    </AppLayout>
</template>
