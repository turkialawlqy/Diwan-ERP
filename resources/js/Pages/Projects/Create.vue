<script setup>
import { useI18n } from 'vue-i18n';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import BoqEditor from '@/Components/Projects/BoqEditor.vue';
const { t } = useI18n();

const props = defineProps({ managers: Array, warehouses: Array, suggestedCode: String, branches: Array, defaultBranchId: Number });
const form = useForm({ customer_id: null, branch_id: props.defaultBranchId || props.branches?.[0]?.id || '', name: '', contract_number: '', manager_id: '', warehouse_id: '', start_date: '', end_date: '', contract_value: 0, advance_percent: 0, retention_percent: 5, location: '', description: '', boq: [] });
useUnsavedGuard(form);
const save = () => {
    form.clearErrors();
    if (!form.name) form.setError('name', t('prj_projects_create.enter_the_project_name'));
    if (!form.customer_id) form.setError('customer_id', t('prj_projects_create.choose_the_customer_owner_from_t'));
    if (form.hasErrors) return;
    form.post(route('projects.store'), { preserveScroll: true });
};
</script>

<template>
    <AppLayout own-errors>
        <Head :title="$t('prj_projects_create.new_project')" />
        <PageHeader :title="$t('prj_projects_create.new_project_contract')" :subtitle="$t('prj_projects_create.expected_code_n', { n0: suggestedCode })" :crumbs="[{ label: $t('prj_projects_create.contracting') }, { label: $t('prj_projects_create.projects'), href: route('projects.index') }, { label: $t('prj_projects_create.new') }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('prj_projects_create.project_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_create.customer_owner')" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
                <Field :label="$t('prj_projects_create.contract_number')"><input v-model="form.contract_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_create.project_manager')"><select v-model="form.manager_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option></select></Field>
                <Field :label="$t('prj_projects_create.site_warehouse')"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('prj_projects_create.default')" /></Field>
                <Field :label="$t('prj_projects_create.location')"><input v-model="form.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_create.start_date')"><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_create.expiry_date')" :error="form.errors.end_date"><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_create.advance_recovery')" :hint="$t('prj_projects_create.deducted_from_every_certificate')"><input v-model.number="form.advance_percent" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('prj_projects_create.retention')"><input v-model.number="form.retention_percent" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field v-if="!form.boq.length" :label="$t('prj_projects_create.contract_value_without_a_bill_of')"><input v-model.number="form.contract_value" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <div>
                <h3 class="text-xs font-black text-slate-700 mb-2">{{ $t('prj_projects_create.bill_of_quantities_boq') }}</h3>
                <BoqEditor v-model="form.boq" />
            </div>
            <div v-if="form.hasErrors" class="rounded-xl bg-rose-50 border border-rose-200 p-3 space-y-1"><p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-700">{{ msg }}</p></div>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('prj_projects_create.save_the_project') }}</button></div>
        </div>
    </AppLayout>
</template>
