<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money, today } from '@/utils/format';

const props = defineProps({ employee: Object, branches: Array, departments: Array, positions: Array, shifts: Array, costCenters: Array, managers: Array, users: Array, nationalities: { type: Array, default: () => [] } });
const e = props.employee || {};
const d = (v) => (v ? String(v).substring(0, 10) : '');
const form = useForm({
    employee_number: e.employee_number || '', name: e.name || '', nationality: e.nationality || 'SA', gender: e.gender || '', birth_date: d(e.birth_date), marital_status: e.marital_status || '',
    id_type: e.id_type || 'national_id', national_id: e.national_id || '', id_expiry: d(e.id_expiry), passport_number: e.passport_number || '', passport_expiry: d(e.passport_expiry),
    phone: e.phone || '', email: e.email || '', job_title: e.job_title || '', profession: e.profession || '', department_id: e.department_id || '', position_id: e.position_id || '',
    manager_id: e.manager_id || '', branch_id: e.branch_id || '', shift_id: e.shift_id || '', cost_center_id: e.cost_center_id || '', user_id: e.user_id || '',
    hire_date: d(e.hire_date), contract_type: e.contract_type || 'unlimited', contract_end: d(e.contract_end), probation_end: d(e.probation_end),
    labour_cost_rate: e.labour_cost_rate ?? '', basic_salary: e.basic_salary ?? 0, housing_allowance: e.housing_allowance ?? 0, transport_allowance: e.transport_allowance ?? 0, other_allowances: e.other_allowances ?? 0,
    gosi_registered: e.gosi_registered ?? true, gosi_number: e.gosi_number || '', gosi_join_date: d(e.gosi_join_date), annual_leave_days: e.annual_leave_days || 21, leave_opening_balance: e.leave_opening_balance ?? 0,
    bank_name: e.bank_name || '', bank_code: e.bank_code || '', iban: e.iban || '', status: e.status || 'active', notes: e.notes || '',
    salary_effective_date: today(), salary_change_reason: '',
});
useUnsavedGuard(form);
// A pay change on an existing employee is recorded with its effective date.
const payFields = ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances'];
const payChanged = () => props.employee && payFields.some((f) => Number(form[f] || 0) !== Number(e[f] || 0));
const save = () => (props.employee ? form.put(route('hr.employees.update', e.id)) : form.post(route('hr.employees.store')));
const total = () => Number(form.basic_salary || 0) + Number(form.housing_allowance || 0) + Number(form.transport_allowance || 0) + Number(form.other_allowances || 0);
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="employee ? employee.name : $t('hr2_hr_employees_form.new_employee')" />
        <PageHeader :title="employee ? $t('hr2_hr_employees_form.edit_n', { n0: employee.name }) : $t('hr2_hr_employees_form.new_employee')" :crumbs="[{ label: $t('hr2_hr_employees_form.human_resources') }, { label: $t('hr2_hr_employees_form.employees'), href: route('hr.employees.index') }, { label: employee ? employee.name : $t('hr2_hr_employees_form.new') }]" />
        <div class="space-y-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">{{ $t('hr_employees_form.personal_data_and_id') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field :label="$t('hr_employees_form.full_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.employee_number')" :error="form.errors.employee_number" :hint="$t('hr_employees_form.generated_automatically_if_left')"><input v-model="form.employee_number" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.nationality')" :error="form.errors.nationality"><SelectCreate v-model="form.nationality" :options="nationalities" entity="nationality" value-key="code" label="name_ar" :allow-empty="false" required /></Field>
                    <Field :label="$t('hr_employees_form.id_type')"><select v-model="form.id_type" :class="input"><option value="national_id">{{ $t('hr_employees_form.national_id') }}</option><option value="iqama">{{ $t('hr_employees_form.residence_permit_iqama') }}</option><option value="border_number">{{ $t('hr_employees_form.border_number') }}</option></select></Field>
                    <Field :label="$t('hr_employees_form.id_residence_number')"><input v-model="form.national_id" :class="input" class="font-mono" /></Field>
                    <Field :label="$t('hr_employees_form.id_residence_expiry')"><DatePicker v-model="form.id_expiry" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.gender')"><select v-model="form.gender" :class="input"><option value="">—</option><option value="M">{{ $t('hr_employees_form.male') }}</option><option value="F">{{ $t('hr_employees_form.female') }}</option></select></Field>
                    <Field :label="$t('hr_employees_form.passport_number')"><input v-model="form.passport_number" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.passport_expiry')"><DatePicker v-model="form.passport_expiry" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.date_of_birth')"><DatePicker v-model="form.birth_date" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.mobile')"><input v-model="form.phone" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.email')" :error="form.errors.email" class="md:col-span-2"><input v-model="form.email" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.user_account_for_self_service')" class="md:col-span-2"><select v-model="form.user_id" :class="input"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option></select></Field>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">{{ $t('hr_employees_form.job_and_contract') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field :label="$t('hr_employees_form.job_title')" :error="form.errors.job_title"><input v-model="form.job_title" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.occupation_on_the_residence_perm')"><input v-model="form.profession" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.department')"><SelectCreate v-model="form.department_id" :options="departments" entity="department" label="name" placeholder="—" /></Field>
                    <Field :label="$t('hr_employees_form.grade_position')"><SelectCreate v-model="form.position_id" :options="positions" entity="position" label="title" placeholder="—" /></Field>
                    <Field :label="$t('hr_employees_form.direct_manager')"><select v-model="form.manager_id" :class="input"><option value="">—</option><option v-for="x in managers" :key="x.id" :value="x.id">{{ x.name }}</option></select></Field>
                    <Field :label="$t('hr_employees_form.branch')"><select v-model="form.branch_id" :class="input"><option value="">—</option><option v-for="x in branches" :key="x.id" :value="x.id">{{ x.name_ar }}</option></select></Field>
                    <Field :label="$t('hr_employees_form.shift')"><SelectCreate v-model="form.shift_id" :options="shifts" entity="shift" label="name" :placeholder="$t('hr_employees_form.default')" /></Field>
                    <Field :label="$t('hr_employees_form.cost_center')" :hint="$t('hr_employees_form.to_charge_the_salary_to_a_projec')"><SelectCreate v-model="form.cost_center_id" :options="costCenters" entity="cost_center" :label="(x) => `${x.code} ${x.name_ar}`" placeholder="—" /></Field>
                    <Field :label="$t('hr_employees_form.start_date')" :error="form.errors.hire_date"><DatePicker v-model="form.hire_date" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.contract_type')"><select v-model="form.contract_type" :class="input"><option value="unlimited">{{ $t('hr_employees_form.open_ended') }}</option><option value="limited">{{ $t('hr_employees_form.fixed_term') }}</option></select></Field>
                    <Field :label="$t('hr_employees_form.contract_end')" :error="form.errors.contract_end"><DatePicker v-model="form.contract_end" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.probation_end')"><DatePicker v-model="form.probation_end" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.status')"><select v-model="form.status" :class="input"><option value="active">{{ $t('hr_employees_form.active') }}</option><option value="on_leave">{{ $t('hr_employees_form.on_leave') }}</option><option value="suspended">{{ $t('hr_employees_form.suspended') }}</option><option value="terminated">{{ $t('hr_employees_form.service_ended') }}</option></select></Field>
                </div>
            </div>
            <div v-if="$can('hr.salaries.view')" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">{{ $t('hr_employees_form.salary_social_insurance_and_bank') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field :label="$t('hr_employees_form.basic_salary')" :error="form.errors.basic_salary"><input v-model.number="form.basic_salary" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field :label="$t('hr_employees_form.hourly_labor_cost_work_orders')" :error="form.errors.labour_cost_rate" :hint="$t('hr_employees_form.internal_cost_for_profit_margin')"><input v-model="form.labour_cost_rate" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-labour-rate /></Field>
                    <Field :label="$t('hr_employees_form.housing_allowance')"><input v-model.number="form.housing_allowance" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field :label="$t('hr_employees_form.transport_allowance')"><input v-model.number="form.transport_allowance" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field :label="$t('hr_employees_form.other_allowances')" :hint="$t('hr2_hr_employees_form.total_n', { n0: money(total()) })"><input v-model.number="form.other_allowances" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <template v-if="payChanged()">
                        <Field :label="$t('hr_employees_form.salary_change_effective_date')" :error="form.errors.salary_effective_date" :hint="$t('hr_employees_form.the_payroll_run_is_split_if_the')"><DatePicker v-model="form.salary_effective_date" :class="input" /></Field>
                        <Field :label="$t('hr_employees_form.reason_for_the_change')" class="md:col-span-3"><input v-model="form.salary_change_reason" :placeholder="$t('hr_employees_form.annual_raise_promotion')" :class="input" /></Field>
                    </template>
                    <label v-if="$feature('gosi')" class="text-xs flex items-center gap-2"><input v-model="form.gosi_registered" type="checkbox" class="rounded" /> {{ $t('hr_employees_form.registered_for_social_insurance') }}</label>
                    <Field v-if="$feature('gosi')" :label="$t('hr_employees_form.social_insurance_number')"><input v-model="form.gosi_number" :class="input" /></Field>
                    <Field v-if="$feature('gosi')" :label="$t('hr_employees_form.first_registration_date')" :hint="$t('hr_employees_form.from_3_july_2024_the_new_system')"><DatePicker v-model="form.gosi_join_date" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.annual_leave_days')"><input v-model.number="form.annual_leave_days" type="text" inputmode="decimal" data-num min="21" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.opening_leave_balance')"><input v-model.number="form.leave_opening_balance" type="text" inputmode="decimal" data-num step="0.5" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.bank')"><input v-model="form.bank_name" :class="input" /></Field>
                    <Field v-if="$feature('wps')" :label="$t('hr_employees_form.bank_code_wps')"><input v-model="form.bank_code" :class="input" /></Field>
                    <Field :label="$t('hr_employees_form.iban')" :error="form.errors.iban"><input v-model="form.iban" :class="input" class="font-mono uppercase" placeholder="SA..." /></Field>
                </div>
            </div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></div>
        </div>
    </AppLayout>
</template>
