<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { confirmAction } from '@/composables/useDialog';
import HijriDate from '@/Components/UI/HijriDate.vue';
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ employee: Object, leaveBalance: Object, attendance: Array, month: String, payslips: Array, gratuity: Object });
const tab = ref('profile');
const docForm = useForm({ type: 'iqama', number: '', issue_date: '', expiry_date: '', notes: '', file: null });
const payForm = useForm({ kind: 'allowance', name: '', amount: 0, gosi_applicable: false, start_date: '', end_date: '' });
const docLabel = computed(() => ({ iqama: t('hr2_hr_employees_show.residence_permit_iqama'), passport: t('hr2_hr_employees_show.passport'), contract: t('hr2_hr_employees_show.employment_contract'), medical_insurance: t('hr2_hr_employees_show.medical_insurance'), driving_license: t('hr2_hr_employees_show.driving_license'), certificate: t('hr2_hr_employees_show.certificate'), other: t('hr2_hr_employees_show.other') }));
const changeMonth = (e) => router.get(route('hr.employees.show', props.employee.id), { month: e.target.value + '-01' }, { preserveState: true, preserveScroll: true });
const minutes = (m) => (m ? `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}` : '');
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: t('hr2_hr_employees_show.delete') })) router.delete(url, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="employee.name" />
        <PageHeader :title="employee.name" :subtitle="`${employee.employee_number || ''} · ${employee.job_title} · ${employee.department_ref?.name || employee.department || ''}`" :crumbs="[{ label: $t('hr2_hr_employees_show.human_resources') }, { label: $t('hr2_hr_employees_show.employees'), href: route('hr.employees.index') }, { label: employee.name }]">
            <template #actions>
                <Link v-if="$can('hr.employees.edit')" :href="route('hr.employees.edit', employee.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_employees_show.edit') }}</Link>
            </template>
        </PageHeader>
        <div class="flex flex-wrap gap-1 mb-3 border-b border-slate-200">
            <button v-for="[k, l] in [['profile', $t('hr2_hr_employees_show.file')], ['documents', $t('hr2_hr_employees_show.documents')], ['pay', $t('hr2_hr_employees_show.salary')], ['leaves', $t('hr2_hr_employees_show.leave')], ['attendance', $t('hr2_hr_employees_show.attendance')], ['payslips', $t('hr2_hr_employees_show.payslips')]]" :key="k" @click="tab = k" :class="tab === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'" class="px-3 py-2 text-xs font-bold border-b-2">{{ l }}</button>
        </div>

        <div v-if="tab === 'profile'" class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.nationality') }}</span><b>{{ employee.nationality }}</b></div>
                <div class="flex justify-between"><span>{{ employee.id_type === 'iqama' ? $t('hr2_hr_employees_show.residence_permit') : $t('hr2_hr_employees_show.id') }}</span><b class="font-mono">{{ employee.national_id }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.id_expiry') }}</span><b><HijriDate :value="employee.id_expiry" inline /></b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.passport') }}</span><b class="font-mono">{{ employee.passport_number || '—' }} </b></div><div v-if="employee.passport_expiry" class="flex justify-between"><span>{{ $t('hr_employees_show.passport_expiry') }}</span><b><HijriDate :value="employee.passport_expiry" inline /></b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.mobile') }}</span><b>{{ employee.phone }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.user') }}</span><b>{{ employee.user?.email || '—' }}</b></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.start') }}</span><b class="font-mono">{{ date(employee.hire_date) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.contract') }}</span><b>{{ employee.contract_type === 'limited' ? $t('hr2_hr_employees_show.fixed_until') + date(employee.contract_end) : $t('hr2_hr_employees_show.open_ended') }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.manager') }}</span><b>{{ employee.manager?.name || '—' }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.shift') }}</span><b>{{ employee.shift?.name || $t('hr2_hr_employees_show.default') }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.status') }}</span><StatusBadge :status="employee.status" /></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.annual_leave_balance') }}</span><b class="font-mono">{{ $t('hr2_hr_employees_show.n_days', { n0: leaveBalance.balance }) }}</b></div>
                <div class="flex justify-between text-slate-500"><span>{{ $t('hr_employees_show.entitled_used') }}</span><span class="font-mono">{{ leaveBalance.accrued }} / {{ leaveBalance.used }}</span></div>
                <template v-if="gratuity">
                    <div class="flex justify-between"><span>{{ $t('hr_employees_show.length_of_service') }}</span><b class="font-mono">{{ $t('hr2_hr_employees_show.n_years', { n0: gratuity.years.toFixed(2) }) }}</b></div>
                    <div class="flex justify-between"><span>{{ $t('hr_employees_show.end_of_service_award_to_date') }}</span><b class="font-mono">{{ money(gratuity.full) }}</b></div>
                </template>
            </div>
        </div>

        <div v-if="tab === 'documents'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_employees_show.type') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.number') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.expiry') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.file') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="d in employee.documents" :key="d.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ docLabel[d.type] }}</td><td class="px-3 py-2 text-center font-mono">{{ d.number }}</td><td class="px-3 py-2 text-center"><HijriDate :value="d.expiry_date" /></td>
                            <td class="px-3 py-2 text-center"><a v-if="d.url" :href="d.url" target="_blank" class="text-indigo-600 font-bold">{{ $t('hr_employees_show.view') }}</a></td>
                            <td class="px-3 py-2 text-end"><button v-if="$can('hr.employees.edit')" @click="remove(route('hr.employees.documents.destroy', [employee.id, d.id]), $t('hr2_hr_employees_show.delete_this_document'))" class="text-rose-600">{{ $t('hr_employees_show.delete') }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="$can('hr.employees.edit')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <Field :label="$t('hr_employees_show.type')"><select v-model="docForm.type" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in docLabel" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('hr_employees_show.number')"><input v-model="docForm.number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-2"><Field :label="$t('hr_employees_show.issue')"><DatePicker v-model="docForm.issue_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field><Field :label="$t('hr_employees_show.expiry')"><DatePicker v-model="docForm.expiry_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field></div>
                <Field :label="$t('hr_employees_show.file_pdf_image')" :error="docForm.errors.file"><input type="file" @input="docForm.file = $event.target.files[0]" class="text-xs" /></Field>
                <button @click="docForm.post(route('hr.employees.documents.store', employee.id), { preserveScroll: true, forceFormData: true, onSuccess: () => docForm.reset() })" class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('hr_employees_show.add') }}</button>
            </div>
        </div>

        <div v-if="tab === 'pay'" class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div v-if="$can('hr.salaries.view')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.basic') }}</span><b class="font-mono">{{ money(employee.basic_salary) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.housing') }}</span><b class="font-mono">{{ money(employee.housing_allowance) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.transport') }}</span><b class="font-mono">{{ money(employee.transport_allowance) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.other') }}</span><b class="font-mono">{{ money(employee.other_allowances) }}</b></div>
                <div class="flex justify-between border-t pt-1 font-black"><span>{{ $t('hr_employees_show.total') }}</span><span class="font-mono">{{ money(employee.total_salary) }}</span></div>
                <div class="flex justify-between"><span>{{ $t('hr_employees_show.iban') }}</span><b class="font-mono">{{ employee.iban }}</b></div>
            </div>
            <div v-if="$can('hr.salaries.view')" class="md:col-span-2 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_employees_show.additional_fixed_allowances_and') }}</h3>
                <div v-for="p in employee.pay_items" :key="p.id" class="flex justify-between py-1 border-b border-slate-50">
                    <span>{{ p.kind === 'allowance' ? '+' : '−' }} {{ p.name }} <span class="text-slate-400">{{ p.gosi_applicable ? $t('hr2_hr_employees_show.subject_to_insurance') : '' }} {{ date(p.start_date) }} → {{ date(p.end_date) }}</span></span>
                    <span><b class="font-mono">{{ money(p.amount) }}</b> <button v-if="$can('hr.employees.edit')" @click="remove(route('hr.employees.pay-items.destroy', [employee.id, p.id]), $t('hr2_hr_employees_show.delete_this_item_from_the_employ'))" class="text-rose-600 ms-2">✕</button></span>
                </div>
                <div v-if="$can('hr.employees.edit')" class="grid grid-cols-6 gap-2 mt-3 items-end">
                    <select v-model="payForm.kind" class="rounded-xl border-slate-200 text-xs"><option value="allowance">{{ $t('hr_employees_show.allowance') }}</option><option value="deduction">{{ $t('hr_employees_show.deduction') }}</option></select>
                    <input v-model="payForm.name" :placeholder="$t('hr_employees_show.name')" class="col-span-2 rounded-xl border-slate-200 text-xs" />
                    <input v-model.number="payForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="rounded-xl border-slate-200 text-xs font-mono" />
                    <label class="flex items-center gap-1"><input v-model="payForm.gosi_applicable" type="checkbox" class="rounded" /> {{ $t('hr_employees_show.insurance') }}</label>
                    <button @click="payForm.post(route('hr.employees.pay-items.store', employee.id), { preserveScroll: true, onSuccess: () => payForm.reset() })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_employees_show.add') }}</button>
                </div>
            </div>
            <div v-if="$can('hr.salaries.view') && employee.salary_changes?.length" class="md:col-span-3 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_employees_show.salary_change_log') }}</h3>
                <div class="overflow-x-auto"><table class="w-full min-w-[640px]">
                    <thead class="text-slate-500"><tr><th class="text-start py-1">{{ $t('hr_employees_show.effective') }}</th><th class="text-end">{{ $t('hr_employees_show.basic') }}</th><th class="text-end">{{ $t('hr_employees_show.housing') }}</th><th class="text-end">{{ $t('hr_employees_show.transport') }}</th><th class="text-end">{{ $t('hr_employees_show.other') }}</th><th class="text-start ps-3">{{ $t('hr_employees_show.reason') }}</th><th class="text-start">{{ $t('hr_employees_show.by') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="c in employee.salary_changes" :key="c.id" class="border-t border-slate-100">
                            <td class="py-1 font-mono">{{ date(c.effective_date) }}</td>
                            <td v-for="f in ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances']" :key="f" class="text-end font-mono">
                                {{ money(c[f]) }}<span v-if="Number(c.previous?.[f] || 0) !== Number(c[f])" class="block text-3xs text-slate-400 line-through">{{ money(c.previous?.[f]) }}</span>
                            </td>
                            <td class="ps-3">{{ c.reason || '—' }}</td><td>{{ c.creator?.name }}</td>
                        </tr>
                    </tbody>
                </table></div>
            </div>
            <div class="md:col-span-3 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_employees_show.advances') }}</h3>
                <div v-for="l in employee.loans" :key="l.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ $t('hr2_hr_employees_show.n_installment_n', { n0: date(l.date), n1: money(l.monthly_installment) }) }}</span><span class="font-mono">{{ money(l.repaid) }} / {{ money(l.amount) }} <StatusBadge :status="l.status" /></span></div>
                <p v-if="!employee.loans?.length" class="text-slate-400">{{ $t('hr_employees_show.no_advances') }}</p>
            </div>
        </div>

        <div v-if="tab === 'leaves'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_employees_show.type') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.from') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.to') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.days') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.status') }}</th></tr></thead>
                <tbody><tr v-for="l in employee.leave_requests" :key="l.id" class="border-t border-slate-100"><td class="px-3 py-2">{{ l.type?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.start_date) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.end_date) }}</td><td class="px-3 py-2 text-center">{{ l.days }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="l.status" /></td></tr></tbody>
            </table>
        </div>

        <div v-if="tab === 'attendance'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100"><DatePicker type="month" :model-value="month" @change="changeMonth" class="rounded-xl border-slate-200 text-xs" /></div>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">{{ $t('hr_employees_show.date') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.status') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.in') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.out') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.work') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.late') }}</th><th class="px-3 py-2">{{ $t('hr_employees_show.overtime') }}</th></tr></thead>
                <tbody><tr v-for="a in attendance" :key="a.id" class="border-t border-slate-100 text-center"><td class="px-3 py-1.5 font-mono">{{ date(a.date) }}</td><td>{{ { present: $t('hr2_hr_employees_show.present'), absent: $t('hr2_hr_employees_show.absent'), leave: $t('hr2_hr_employees_show.leave_2'), holiday: $t('hr2_hr_employees_show.public_holiday'), weekend: $t('hr2_hr_employees_show.weekly_rest') }[a.status] || a.status }}</td><td class="font-mono">{{ a.check_in?.substring(0, 5) }}</td><td class="font-mono">{{ a.check_out?.substring(0, 5) }}</td><td class="font-mono">{{ minutes(a.worked_minutes) }}</td><td class="font-mono text-rose-600">{{ a.late_minutes || '' }}</td><td class="font-mono text-emerald-600">{{ minutes(a.overtime_minutes) }}</td></tr></tbody>
            </table>
        </div>

        <div v-if="tab === 'payslips'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">{{ $t('hr_employees_show.month') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_employees_show.total') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_employees_show.deductions') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_employees_show.net') }}</th><th></th></tr></thead>
                <tbody><tr v-for="p in payslips" :key="p.id" class="border-t border-slate-100"><td class="px-3 py-2 text-center font-mono">{{ p.payroll.month }}/{{ p.payroll.year }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.gross_salary) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.deductions) }}</td><td class="px-3 py-2 text-end font-mono font-bold">{{ money(p.net_salary) }}</td><td class="px-3 py-2 text-end"><Link :href="route('hr.payroll.payslip', [p.payroll_id, p.id])" class="text-indigo-600 font-bold">{{ $t('hr_employees_show.payslip') }}</Link></td></tr></tbody>
            </table>
        </div>
    </AppLayout>
</template>
