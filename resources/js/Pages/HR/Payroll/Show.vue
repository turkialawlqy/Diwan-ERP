<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { confirmAction } from '@/composables/useDialog';
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ attendanceGaps: { type: Array, default: () => [] }, payroll: Object, adjustments: Array, employees: Array, bankAccounts: Array, errors: { type: Object, default: () => ({}) } });
const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.payroll.id), {}, { preserveScroll: true }); };
const adjForm = useForm({ employee_id: '', type: 'bonus', amount: 0, description: '' });
const payOpen = ref(false);
const unapprove = async () => {
    if (await confirmAction(t('hr2_hr_payroll_show.the_accrual_entry_is_reversed_ad'), { title: t('hr2_hr_payroll_show.cancel_the_run_s_approval'), danger: true, confirmText: t('hr2_hr_payroll_show.cancel_approval') })) {
        router.post(route('hr.payroll.unapprove', props.payroll.id), {}, { preserveScroll: true });
    }
};
const payForm = useForm({ date: today(), bank_account_id: '' });
const typeLabel = computed(() => ({ bonus: t('hr2_hr_payroll_show.bonus'), commission: t('hr2_hr_payroll_show.commission'), overtime_hours: t('hr2_hr_payroll_show.overtime_hours'), penalty: t('hr2_hr_payroll_show.penalty'), deduction: t('hr2_hr_payroll_show.deduction') }));
const sum = (key) => props.payroll.items.reduce((s, i) => s + Number(i[key] || 0), 0);
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: t('hr2_hr_payroll_show.delete') })) router.delete(url, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr2_hr_payroll_show.payroll_run_n', { n0: payroll.payroll_number })" />
        <PageHeader :title="$t('hr2_hr_payroll_show.payroll_run_n_n', { n0: payroll.month, n1: payroll.year })" :subtitle="payroll.payroll_number" :crumbs="[{ label: $t('hr2_hr_payroll_show.payroll'), href: route('hr.payroll.index') }, { label: payroll.payroll_number }]">
            <template #actions>
                <StatusBadge :status="payroll.status" />
                <template v-if="payroll.status === 'draft'">
                    <button v-if="$can('hr.payroll.create')" @click="act('hr.payroll.recalculate')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_payroll_show.recalculate') }}</button>
                    <button v-if="$can('hr.payroll.approve')" @click="act('hr.payroll.approve', $t('hr2_hr_payroll_show.approve_the_run_and_post_it_to_t'))" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('hr_payroll_show.approve') }}</button>
                </template>
                <button v-if="payroll.status === 'approved' && $can('hr.payroll.approve')" @click="unapprove" class="px-3 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">{{ $t('hr_payroll_show.cancel_approval') }}</button>
                <button v-if="payroll.status === 'approved' && $can('hr.payroll.post')" @click="payOpen = true" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('hr_payroll_show.pay_salaries') }}</button>
                <template v-if="$can('hr.payroll.export')">
                    <a v-if="$feature('wps')" :href="route('hr.payroll.wps', { payroll: payroll.id, format: 'mudad' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_payroll_show.mudad_wps_file') }}</a>
                    <a :href="route('hr.payroll.wps', { payroll: payroll.id, format: 'bank' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_payroll_show.bank_file') }}</a>
                </template>
            </template>
        </PageHeader>
        <div v-if="attendanceGaps.length" class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-800" data-attendance-gaps>
            <div class="font-bold mb-1">{{ $t('hr2_hr_payroll_show.incomplete_attendance_n_employee', { n0: attendanceGaps.length }) }}</div>
            <p class="mb-1">{{ $t('hr_payroll_show.they_will_be_paid_in_full_unless') }}</p>
            <p class="font-bold">{{ attendanceGaps.map((e) => e.name).join($t('hr2_hr_payroll_show.text')) }}</p>
        </div>


        <div v-if="errors.wps" class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-700">
            <p class="font-bold mb-1">{{ $t('hr_payroll_show.the_salary_file_cannot_be_create') }}</p>
            <ul class="list-disc ps-5 space-y-0.5"><li v-for="(e, i) in String(errors.wps).split('\n')" :key="i">{{ e }}</li></ul>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <table class="w-full text-xs min-w-[1100px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-2 py-2">{{ $t('hr_payroll_show.employee') }}</th><th class="px-2 py-2">{{ $t('hr_payroll_show.days') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.basic') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.allowances') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.overtime') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.bonuses') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.absence') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.total') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.insurance') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.advances') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.penalties') }}</th><th class="px-2 py-2 text-end">{{ $t('hr_payroll_show.net') }}</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="i in payroll.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-2 py-1.5">{{ i.employee?.name }} <span class="text-slate-400 font-mono">{{ i.employee?.employee_number }}</span></td>
                        <td class="px-2 py-1.5 text-center">{{ i.days_worked }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.basic_salary) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.allowances) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.overtime) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.bonuses) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono text-rose-600">{{ Number(i.absence_deduction) ? money(i.absence_deduction) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.gross_salary) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono" :title="$t('hr2_hr_payroll_show.company_share_n', { n0: money(i.gosi_employer) })">{{ money(i.gosi_employee) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ Number(i.loan_deduction) ? money(i.loan_deduction) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ Number(i.other_deductions) ? money(i.other_deductions) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono font-bold">{{ money(i.net_salary) }}</td>
                        <td class="px-2 py-1.5 text-end"><Link :href="route('hr.payroll.payslip', [payroll.id, i.id])" class="text-indigo-600">{{ $t('hr_payroll_show.payslip') }}</Link></td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold">
                    <tr><td class="px-2 py-2" colspan="2">{{ $t('hr_payroll_show.total') }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('basic_salary')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('allowances')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('overtime')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('bonuses')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('absence_deduction')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('gross_salary')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.total_gosi_employee) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.total_loans) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('other_deductions')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.net_total) }}</td><td></td></tr>
                </tfoot>
            </table>
            <p v-if="$feature('gosi')" class="p-3 text-xs text-slate-500">{{ $t('hr_payroll_show.company_share_of_social_insuranc') }} <b class="font-mono">{{ money(payroll.total_gosi_employer) }}</b> {{ $t('hr_payroll_show.total_insurance_payment') }} <b class="font-mono">{{ money(Number(payroll.total_gosi_employee) + Number(payroll.total_gosi_employer)) }}</b></p>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
            <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_payroll_show.month_adjustments_bonuses_commis') }}</h3>
            <div v-for="a in adjustments" :key="a.id" class="flex justify-between py-1 border-b border-slate-50">
                <span>{{ a.employee?.name }} — {{ typeLabel[a.type] }} <span class="text-slate-400">{{ a.description }}</span></span>
                <span><b class="font-mono">{{ a.type === 'overtime_hours' ? a.amount + $t('hr2_hr_payroll_show.h') : money(a.amount) }}</b> <button v-if="payroll.status === 'draft' && $can('hr.payroll.create')" @click="remove(route('hr.payroll.adjustments.destroy', [payroll.id, a.id]), $t('hr2_hr_payroll_show.delete_this_adjustment_and_recal'))" class="text-rose-600 ms-2">✕</button></span>
            </div>
            <div v-if="payroll.status === 'draft' && $can('hr.payroll.create')" class="grid grid-cols-6 gap-2 mt-3">
                <SelectCreate v-model="adjForm.employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('hr_payroll_show.employee_2')" />
                <select v-model="adjForm.type" class="rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in typeLabel" :key="k" :value="k">{{ l }}</option></select>
                <input v-model.number="adjForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="rounded-xl border-slate-200 text-xs font-mono" />
                <input v-model="adjForm.description" :placeholder="$t('hr_payroll_show.note')" class="rounded-xl border-slate-200 text-xs" />
                <button @click="adjForm.post(route('hr.payroll.adjustments.store', payroll.id), { preserveScroll: true, onSuccess: () => adjForm.reset('amount', 'description') })" class="rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_payroll_show.add') }}</button>
            </div>
            <p v-for="(msg, key) in adjForm.errors" :key="key" class="text-rose-600 mt-1">{{ msg }}</p>
        </div>

        <Dialog :show="payOpen" :title="$t('hr_payroll_show.pay_salaries')" @close="payOpen = false">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('hr_payroll_show.payment_date')"><DatePicker v-model="payForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('hr_payroll_show.bank_account')"><SelectCreate v-model="payForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('hr_payroll_show.default')" /></Field>
            </div>
            <p class="text-xs mt-2">{{ $t('hr_payroll_show.amount') }} <b class="font-mono">{{ money(payroll.net_total) }}</b></p>
            <template #footer><button @click="payForm.post(route('hr.payroll.pay', payroll.id), { onSuccess: () => (payOpen = false) })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('hr_payroll_show.confirm_payment') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
