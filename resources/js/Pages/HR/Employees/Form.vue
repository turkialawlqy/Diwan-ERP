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
        <Head :title="employee ? employee.name : 'موظف جديد'" />
        <PageHeader :title="employee ? `تعديل: ${employee.name}` : 'موظف جديد'" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الموظفون', href: route('hr.employees.index') }, { label: employee ? employee.name : 'جديد' }]" />
        <div class="space-y-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">البيانات الشخصية والهوية</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field label="الاسم الكامل" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" :class="input" /></Field>
                    <Field label="الرقم الوظيفي" :error="form.errors.employee_number" hint="يُولد تلقائياً إن تُرك فارغاً"><input v-model="form.employee_number" :class="input" /></Field>
                    <Field label="الجنسية *" :error="form.errors.nationality"><SelectCreate v-model="form.nationality" :options="nationalities" entity="nationality" value-key="code" label="name_ar" :allow-empty="false" required /></Field>
                    <Field label="نوع الهوية"><select v-model="form.id_type" :class="input"><option value="national_id">هوية وطنية</option><option value="iqama">إقامة</option><option value="border_number">رقم حدود</option></select></Field>
                    <Field label="رقم الهوية / الإقامة"><input v-model="form.national_id" :class="input" class="font-mono" /></Field>
                    <Field label="انتهاء الهوية / الإقامة"><DatePicker v-model="form.id_expiry" :class="input" /></Field>
                    <Field label="الجنس"><select v-model="form.gender" :class="input"><option value="">—</option><option value="M">ذكر</option><option value="F">أنثى</option></select></Field>
                    <Field label="رقم الجواز"><input v-model="form.passport_number" :class="input" /></Field>
                    <Field label="انتهاء الجواز"><DatePicker v-model="form.passport_expiry" :class="input" /></Field>
                    <Field label="تاريخ الميلاد"><DatePicker v-model="form.birth_date" :class="input" /></Field>
                    <Field label="الجوال"><input v-model="form.phone" :class="input" /></Field>
                    <Field label="البريد" :error="form.errors.email" class="md:col-span-2"><input v-model="form.email" :class="input" /></Field>
                    <Field label="حساب المستخدم (للخدمة الذاتية)" class="md:col-span-2"><select v-model="form.user_id" :class="input"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option></select></Field>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">الوظيفة والعقد</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field label="المسمى الوظيفي" :error="form.errors.job_title"><input v-model="form.job_title" :class="input" /></Field>
                    <Field label="المهنة (في الإقامة / قوى)"><input v-model="form.profession" :class="input" /></Field>
                    <Field label="الإدارة"><SelectCreate v-model="form.department_id" :options="departments" entity="department" label="name" placeholder="—" /></Field>
                    <Field label="الدرجة / الوظيفة"><SelectCreate v-model="form.position_id" :options="positions" entity="position" label="title" placeholder="—" /></Field>
                    <Field label="المدير المباشر"><select v-model="form.manager_id" :class="input"><option value="">—</option><option v-for="x in managers" :key="x.id" :value="x.id">{{ x.name }}</option></select></Field>
                    <Field label="الفرع"><select v-model="form.branch_id" :class="input"><option value="">—</option><option v-for="x in branches" :key="x.id" :value="x.id">{{ x.name_ar }}</option></select></Field>
                    <Field label="الوردية"><SelectCreate v-model="form.shift_id" :options="shifts" entity="shift" label="name" placeholder="الافتراضية" /></Field>
                    <Field label="مركز التكلفة" hint="لتحميل الراتب على مشروع/إدارة"><SelectCreate v-model="form.cost_center_id" :options="costCenters" entity="cost_center" :label="(x) => `${x.code} ${x.name_ar}`" placeholder="—" /></Field>
                    <Field label="تاريخ المباشرة" :error="form.errors.hire_date"><DatePicker v-model="form.hire_date" :class="input" /></Field>
                    <Field label="نوع العقد"><select v-model="form.contract_type" :class="input"><option value="unlimited">غير محدد المدة</option><option value="limited">محدد المدة</option></select></Field>
                    <Field label="نهاية العقد" :error="form.errors.contract_end"><DatePicker v-model="form.contract_end" :class="input" /></Field>
                    <Field label="نهاية التجربة"><DatePicker v-model="form.probation_end" :class="input" /></Field>
                    <Field label="الحالة"><select v-model="form.status" :class="input"><option value="active">على رأس العمل</option><option value="on_leave">في إجازة</option><option value="suspended">موقوف</option><option value="terminated">منتهية خدمته</option></select></Field>
                </div>
            </div>
            <div v-if="$can('hr.salaries.view')" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="text-xs font-black text-slate-700 mb-3">الراتب والتأمينات والبنك</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field label="الراتب الأساسي" :error="form.errors.basic_salary"><input v-model.number="form.basic_salary" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field label="تكلفة ساعة العمل (أوامر العمل)" :error="form.errors.labour_cost_rate" hint="تكلفة داخلية لهامش الربحية؛ فارغ = الراتب ÷ 208 ساعة"><input v-model="form.labour_cost_rate" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-labour-rate /></Field>
                    <Field label="بدل السكن"><input v-model.number="form.housing_allowance" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field label="بدل النقل"><input v-model.number="form.transport_allowance" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <Field label="بدلات أخرى" :hint="`الإجمالي: ${money(total())}`"><input v-model.number="form.other_allowances" type="text" inputmode="decimal" data-num step="0.01" :class="input" class="font-mono" /></Field>
                    <template v-if="payChanged()">
                        <Field label="تاريخ سريان تعديل الراتب" :error="form.errors.salary_effective_date" hint="يُجزّأ المسير إن كان التاريخ في منتصف الشهر"><DatePicker v-model="form.salary_effective_date" :class="input" /></Field>
                        <Field label="سبب التعديل" class="md:col-span-3"><input v-model="form.salary_change_reason" placeholder="زيادة سنوية، ترقية..." :class="input" /></Field>
                    </template>
                    <label v-if="$feature('gosi')" class="text-xs flex items-center gap-2"><input v-model="form.gosi_registered" type="checkbox" class="rounded" /> مسجل في التأمينات</label>
                    <Field v-if="$feature('gosi')" label="رقم التأمينات"><input v-model="form.gosi_number" :class="input" /></Field>
                    <Field v-if="$feature('gosi')" label="تاريخ أول تسجيل" hint="من 3 يوليو 2024 تُطبّق نسب النظام الجديد"><DatePicker v-model="form.gosi_join_date" :class="input" /></Field>
                    <Field label="أيام الإجازة السنوية"><input v-model.number="form.annual_leave_days" type="text" inputmode="decimal" data-num min="21" :class="input" /></Field>
                    <Field label="رصيد إجازات افتتاحي"><input v-model.number="form.leave_opening_balance" type="text" inputmode="decimal" data-num step="0.5" :class="input" /></Field>
                    <Field label="البنك"><input v-model="form.bank_name" :class="input" /></Field>
                    <Field v-if="$feature('wps')" label="رمز البنك (WPS)"><input v-model="form.bank_code" :class="input" /></Field>
                    <Field label="الآيبان" :error="form.errors.iban"><input v-model="form.iban" :class="input" class="font-mono uppercase" placeholder="SA..." /></Field>
                </div>
            </div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></div>
        </div>
    </AppLayout>
</template>
