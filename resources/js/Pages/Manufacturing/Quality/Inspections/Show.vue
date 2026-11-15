<script setup>
import { computed, reactive } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ inspection: Object, source: String });
const i = computed(() => props.inspection);
const checks = computed(() => i.value.plan?.checks || []);
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const values = reactive({});
const form = useForm({ accepted: i.value.inspected_quantity, rejected: 0, rework: 0, disposition: 'accept', values: {}, notes: '' });
const sum = computed(() => Number(form.accepted || 0) + Number(form.rejected || 0) + Number(form.rework || 0));
const submit = () => { form.values = { ...values }; form.post(route('manufacturing.inspections.complete', i.value.id)); };
const input = 'w-full text-xs rounded-xl border-slate-200';
const card = 'bg-white border border-slate-200/80 rounded-2xl p-5';
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_quality_inspections_show.inspection_n', { n0: i.inspection_number })" />
        <PageHeader :title="$t('mfg_manufacturing_quality_inspections_show.inspection_n', { n0: i.inspection_number })" :subtitle="i.product?.name_ar" :crumbs="[{ label: $t('mfg_manufacturing_quality_inspections_show.manufacturing') }, { label: $t('mfg_manufacturing_quality_inspections_show.quality_inspections'), href: route('manufacturing.inspections.index') }, { label: i.inspection_number }]">
            <template #actions><StatusPill :status="i.result" /></template>
        </PageHeader>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_show.inspected') }}</p><p class="text-xl font-black font-mono">{{ num(i.inspected_quantity) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_show.accepted') }}</p><p class="text-xl font-black font-mono text-emerald-600">{{ num(i.accepted_quantity) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_show.rejected') }}</p><p class="text-xl font-black font-mono text-rose-600">{{ num(i.rejected_quantity) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_show.rework') }}</p><p class="text-xl font-black font-mono">{{ num(i.rework_quantity) }}</p></div>
        </div>

        <div v-if="i.result !== 'pending'" :class="card">
            <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_quality_inspections_show.item_results') }}</h2>
            <p v-if="!(i.results || []).length" class="text-xs text-slate-400">{{ $t('mfg_manufacturing_quality_inspections_show.no_items_recorded') }}</p>
            <table v-else class="w-full text-xs"><tbody><tr v-for="(r, k) in i.results" :key="k" class="border-t border-slate-100"><td class="p-2 font-bold">{{ r.name }}</td><td class="p-2 font-mono">{{ r.value }}</td><td class="p-2"><span :class="r.passed ? 'text-emerald-600' : 'text-rose-600'" class="font-bold">{{ r.passed ? $t('mfg_manufacturing_quality_inspections_show.conforming') : $t('mfg_manufacturing_quality_inspections_show.non_conforming') }}</span></td></tr></tbody></table>
            <p v-if="i.notes" class="text-xs text-slate-500 mt-3">{{ i.notes }}</p>
        </div>

        <form v-else-if="$can('manufacturing.quality.manage')" @submit.prevent="submit" :class="[card, 'space-y-5']">
            <div v-if="checks.length">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_quality_inspections_show.inspection_items_n', { n0: i.plan.name }) }}</h2>
                <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
                    <Field v-for="(c, k) in checks" :key="k" :label="c.type === 'numeric' ? `${c.name} (${c.min ?? '—'} → ${c.max ?? '—'})` : c.name">
                        <input v-if="c.type === 'numeric'" v-model.number="values[k]" type="text" inputmode="decimal" data-num step="any" :class="input" />
                        <select v-else v-model="values[k]" :class="input"><option value="">—</option><option value="pass">{{ $t('mfg_manufacturing_quality_inspections_show.good') }}</option><option value="fail">{{ $t('mfg_manufacturing_quality_inspections_show.defective') }}</option></select>
                    </Field>
                </div>
            </div>
            <div class="grid md:grid-cols-4 gap-3">
                <Field :label="$t('mfg_manufacturing_quality_inspections_show.accepted')" :error="form.errors.accepted"><input v-model.number="form.accepted" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_quality_inspections_show.rejected')"><input v-model.number="form.rejected" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_quality_inspections_show.rework')"><input v-model.number="form.rework" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_quality_inspections_show.disposition_of_rejects')"><select v-model="form.disposition" :class="input"><option value="accept">{{ $t('mfg_manufacturing_quality_inspections_show.nothing_rejected') }}</option><option value="scrap">{{ $t('mfg_manufacturing_quality_inspections_show.scrap_charged_as_a_loss') }}</option><option value="rework">{{ $t('mfg_manufacturing_quality_inspections_show.rework') }}</option><option value="return">{{ $t('mfg_manufacturing_quality_inspections_show.return_to_the_supplier') }}</option></select></Field>
            </div>
            <p class="text-xs" :class="Math.abs(sum - Number(i.inspected_quantity)) < 0.0001 ? 'text-slate-500' : 'text-rose-600'">{{ $t('mfg_manufacturing_quality_inspections_show.total_n_of_n_inspected', { n0: num(sum), n1: num(i.inspected_quantity) }) }}</p>
            <Field :label="$t('mfg_manufacturing_quality_inspections_show.notes')"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <p v-if="source === 'ProductionReceipt'" class="text-xs text-indigo-700 bg-indigo-50 rounded-xl p-3">{{ $t('mfg_manufacturing_quality_inspections_show.production_receipt_inspection_th') }}</p>
            <button type="submit" :disabled="form.processing || Math.abs(sum - Number(i.inspected_quantity)) > 0.0001" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-40">{{ $t('mfg_manufacturing_quality_inspections_show.approve_the_inspection_result') }}</button>
        </form>
    </AppLayout>
</template>
