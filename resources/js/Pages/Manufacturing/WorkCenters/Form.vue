<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ center: Object, equipment: Array, costCenters: Array });
const editing = !!props.center?.id;
const c = props.center;
const form = useForm({
    code: c.code || '', name: c.name || '', type: c.type || 'machine', equipment_id: c.equipment_id || '', cost_center_id: c.cost_center_id || '',
    capacity_hours_per_day: c.capacity_hours_per_day ?? 8, efficiency: c.efficiency ?? 100, labour_rate_per_hour: c.labour_rate_per_hour ?? 0, overhead_rate_per_hour: c.overhead_rate_per_hour ?? 0,
    is_active: c.is_active ?? true, notes: c.notes || '',
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('manufacturing.work-centers.update', c.id)) : form.post(route('manufacturing.work-centers.store')));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('mfg_manufacturing_workcenters_form.edit_a_work_center') : $t('mfg_manufacturing_workcenters_form.new_work_center')" />
        <PageHeader :title="editing ? $t('mfg_manufacturing_workcenters_form.edit_a_work_center') : $t('mfg_manufacturing_workcenters_form.new_work_center')" :crumbs="[{ label: $t('mfg_manufacturing_workcenters_form.manufacturing') }, { label: $t('mfg_manufacturing_workcenters_form.work_centers'), href: route('manufacturing.work-centers.index') }, { label: editing ? $t('mfg_manufacturing_workcenters_form.edit') : $t('mfg_manufacturing_workcenters_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field :label="$t('mfg_manufacturing_workcenters_form.code')" :error="form.errors.code"><input v-model="form.code" :class="input" class="font-mono" required /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.name')" :error="form.errors.name"><input v-model="form.name" :class="input" required /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.type')"><select v-model="form.type" :class="input"><option value="machine">{{ $t('mfg_manufacturing_workcenters_form.machine') }}</option><option value="line">{{ $t('mfg_manufacturing_workcenters_form.production_line') }}</option><option value="labour">{{ $t('mfg_manufacturing_workcenters_form.manual_labor') }}</option><option value="workshop">{{ $t('mfg_manufacturing_workcenters_form.workshop') }}</option></select></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.linked_equipment')" :hint="$t('mfg_manufacturing_workcenters_form.to_link_with_the_equipment_modul')"><select v-model="form.equipment_id" :class="input"><option value="">—</option><option v-for="e in equipment" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option></select></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.cost_center')"><SelectCreate v-model="form.cost_center_id" :options="costCenters" entity="cost_center" :label="(cc) => `${cc.code} — ${cc.name_ar}`" placeholder="—" /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.capacity_hours_day')" :error="form.errors.capacity_hours_per_day"><input v-model.number="form.capacity_hours_per_day" type="text" inputmode="decimal" data-num step="0.1" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.efficiency')" :hint="$t('mfg_manufacturing_workcenters_form.100_no_loss')" :error="form.errors.efficiency"><input v-model.number="form.efficiency" type="text" inputmode="decimal" data-num step="1" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.operating_wages_per_hour')" :error="form.errors.labour_rate_per_hour"><input v-model.number="form.labour_rate_per_hour" type="text" inputmode="decimal" data-num step="0.01" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_workcenters_form.indirect_expenses_per_hour')" :hint="$t('mfg_manufacturing_workcenters_form.electricity_depreciation_and_mai')" :error="form.errors.overhead_rate_per_hour"><input v-model.number="form.overhead_rate_per_hour" type="text" inputmode="decimal" data-num step="0.01" :class="input" /></Field>
            </div>
            <Field :label="$t('mfg_manufacturing_workcenters_form.notes')"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded" /> {{ $t('mfg_manufacturing_workcenters_form.running') }}</label>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('mfg_manufacturing_workcenters_form.save') }}</button>
                <Link :href="route('manufacturing.work-centers.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('mfg_manufacturing_workcenters_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
