<script setup>
import { useI18n } from 'vue-i18n';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import Field from '@/Components/UI/Field.vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ period: Object, products: Array, workCenters: Array, isFinal: Boolean });
const p = computed(() => props.period);
const open = computed(() => p.value.status === 'open');
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });

const issue = useForm({ lines: [{ product_id: '', quantity: '' }] });
const sendIssue = () => issue.post(route('manufacturing.periods.issue', p.value.id), { preserveScroll: true, onSuccess: () => issue.reset() });
const conv = useForm({ work_center_id: props.workCenters?.[0]?.id || '', minutes: '' });
const sendConv = () => conv.post(route('manufacturing.periods.conversion', p.value.id), { preserveScroll: true, onSuccess: () => conv.reset('minutes') });
const close = useForm({ units_started: p.value.units_started || '', units_completed: '', units_closing_wip: 0, closing_material_pct: 100, closing_conversion_pct: 50 });
const sendClose = () => confirm(t('mfg_manufacturing_process_periods_show.close_the_period_it_cannot_be_ed')) && close.post(route('manufacturing.periods.close', p.value.id));
const input = 'w-full text-xs rounded-xl border-slate-200';
const card = 'bg-white border border-slate-200/80 rounded-2xl p-5';
const els = computed(() => ([['ti', t('mfg_manufacturing_process_periods_show.transferred_from_a_previous_stag')], ['mat', t('mfg_manufacturing_process_periods_show.direct_materials')], ['conv', t('mfg_manufacturing_process_periods_show.conversion_wages_expenses')]]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_process_periods_show.period_n', { n0: p.stage?.name })" />
        <PageHeader :title="`${p.stage?.name} — ${String(p.date_from).slice(0, 10)} → ${String(p.date_to).slice(0, 10)}`" :crumbs="[{ label: $t('mfg_manufacturing_process_periods_show.manufacturing') }, { label: $t('mfg_manufacturing_process_periods_show.cost_periods'), href: route('manufacturing.periods.index') }, { label: p.stage?.name }]">
            <template #actions><StatusPill :status="open ? 'open' : 'closed'" /></template>
        </PageHeader>

        <div :class="[card, 'mb-6 overflow-x-auto']">
            <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_process_periods_show.cost_elements') }}</h2>
            <table class="w-full text-xs">
                <thead class="text-slate-500"><tr><th class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.element') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.opening') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.added') }}</th><th v-if="!open" class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.equivalent_units') }}</th><th v-if="!open" class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.equivalent_unit_cost') }}</th><th v-if="!open" class="text-start p-2">{{ $t('mfg_manufacturing_process_periods_show.closing_wip') }}</th></tr></thead>
                <tbody>
                    <tr v-for="[k, label] in els" :key="k" class="border-t border-slate-100"><td class="p-2 font-bold">{{ label }}</td><td class="p-2 font-mono">{{ money(p['opening_' + k]) }}</td><td class="p-2 font-mono">{{ money(p['added_' + k]) }}</td>
                        <td v-if="!open" class="p-2 font-mono">{{ num(p['eu_' + k]) }}</td><td v-if="!open" class="p-2 font-mono">{{ Number(p['cpu_' + k]).toFixed(4) }}</td><td v-if="!open" class="p-2 font-mono">{{ money(p['closing_' + k]) }}</td></tr>
                </tbody>
            </table>
            <div v-if="!open" class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                <div><p class="text-slate-400 font-bold">{{ $t('mfg_manufacturing_process_periods_show.completed_units') }}</p><p class="font-mono font-black">{{ num(p.units_completed) }}</p></div>
                <div><p class="text-slate-400 font-bold">{{ $t('mfg_manufacturing_process_periods_show.wip_at_period_end') }}</p><p class="font-mono font-black">{{ num(p.units_closing_wip) }}</p></div>
                <div><p class="text-slate-400 font-bold">{{ $t('mfg_manufacturing_process_periods_show.cost_transferred') }}</p><p class="font-mono font-black">{{ money(p.transferred_out) }}</p></div>
                <div><p class="text-slate-400 font-bold">{{ $t('mfg_manufacturing_process_periods_show.finished_unit_cost') }}</p><p class="font-mono font-black text-indigo-700">{{ Number(p.unit_cost).toFixed(4) }}</p></div>
            </div>
        </div>

        <div v-if="open && $can('manufacturing.process.post')" class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_process_periods_show.issue_direct_materials_from_the') }}</h2>
                <form @submit.prevent="sendIssue" class="space-y-2">
                    <div v-for="(l, i) in issue.lines" :key="i" class="grid grid-cols-[1fr_8rem_2rem] gap-2">
                        <SelectCreate v-model="l.product_id" :options="products" :search="{ route: 'lookup.products', params: { stockable: 1 } }" entity="product" label="name_ar" :placeholder="$t('mfg_manufacturing_process_periods_show.material')" required />
                        <input v-model.number="l.quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_process_periods_show.quantity')" :class="input" required />
                        <button type="button" :disabled="issue.lines.length <= 1" @click="issue.lines.splice(i, 1)" class="text-slate-400 hover:text-rose-600 disabled:opacity-20"><TrashIcon class="w-4 h-4" /></button>
                    </div>
                    <p v-if="issue.errors.lines || issue.errors.quantity" class="text-xs text-rose-600">{{ issue.errors.lines || issue.errors.quantity }}</p>
                    <div class="flex justify-between pt-1"><button type="button" @click="issue.lines.push({ product_id: '', quantity: '' })" class="text-xs font-bold text-indigo-600 inline-flex items-center gap-1"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_process_periods_show.line') }}</button><button type="submit" :disabled="issue.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_process_periods_show.pay') }}</button></div>
                </form>
                <h2 class="font-black text-slate-800 text-sm mt-6 mb-3">{{ $t('mfg_manufacturing_process_periods_show.conversion_cost_run_time') }}</h2>
                <form @submit.prevent="sendConv" class="grid grid-cols-[1fr_8rem_auto] gap-2">
                    <SelectCreate v-model="conv.work_center_id" :options="workCenters" entity="work_center" label="name" :allow-empty="false" />
                    <input v-model.number="conv.minutes" type="text" inputmode="decimal" data-num step="0.01" :placeholder="$t('mfg_manufacturing_process_periods_show.minutes')" :class="input" required />
                    <button type="submit" :disabled="conv.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_process_periods_show.add') }}</button>
                    <p v-if="conv.errors.work_center_id" class="col-span-3 text-xs text-rose-600">{{ conv.errors.work_center_id }}</p>
                </form>
            </div>
            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_process_periods_show.close_the_period') }}</h2>
                <form @submit.prevent="sendClose" class="grid grid-cols-2 gap-3">
                    <Field :label="$t('mfg_manufacturing_process_periods_show.units_started_this_period')"><input v-model.number="close.units_started" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                    <Field :label="$t('mfg_manufacturing_process_periods_show.completed_units_2')" :error="close.errors.units_completed"><input v-model.number="close.units_completed" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                    <Field :label="$t('mfg_manufacturing_process_periods_show.units_in_progress_at_period_end')"><input v-model.number="close.units_closing_wip" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                    <Field :label="$t('mfg_manufacturing_process_periods_show.materials_completion')"><input v-model.number="close.closing_material_pct" type="text" inputmode="decimal" data-num step="1" :class="input" /></Field>
                    <Field :label="$t('mfg_manufacturing_process_periods_show.conversion_completion')"><input v-model.number="close.closing_conversion_pct" type="text" inputmode="decimal" data-num step="1" :class="input" /></Field>
                    <p class="col-span-2 text-3xs text-slate-500">{{ isFinal ? $t('mfg_manufacturing_process_periods_show.last_stage_completed_units_are_r') : $t('mfg_manufacturing_process_periods_show.the_completed_units_cost_moves_t') }}</p>
                    <p v-if="close.errors.stage" class="col-span-2 text-xs text-rose-600">{{ close.errors.stage }}</p>
                    <button type="submit" :disabled="close.processing" class="col-span-2 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold">{{ $t('mfg_manufacturing_process_periods_show.close_and_calculate_the_cost') }}</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
