<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { confirmAction } from '@/composables/useDialog';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, hijri } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ employee: Object, leaveBalance: Object, leaveRequests: Array, loans: Array, payslips: Array, leaveTypes: Array, adminLines: { type: Array, default: () => [] } });
const withdraw = async (name, id, message) => {
    if (await confirmAction(message, { confirmText: t('hr2_hr_selfservice.withdraw_the_request'), danger: true })) router.post(route(name, id), {}, { preserveScroll: true });
};
const leave = useForm({ leave_type_id: props.leaveTypes?.[0]?.id || '', start_date: '', end_date: '', reason: '' });
const loan = useForm({ amount: 0, monthly_installment: 0, reason: '' });
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_selfservice.my_services')" />
        <div v-if="!employee" class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-2xl p-8 text-center space-y-2">
            <h2 class="text-sm font-black text-slate-800">{{ $t('hr_selfservice.your_account_is_not_linked_to_an') }}</h2>
            <p class="text-xs text-slate-500">{{ $t('hr_selfservice.self_service_leave_advances_pays') }}</p>
        </div>
        <template v-else>
        <PageHeader :title="$t('hr2_hr_selfservice.welcome_n', { n0: employee.name })" :subtitle="`${employee.job_title} · ${employee.department || ''}`" :crumbs="[{ label: $t('hr2_hr_selfservice.self_service') }]" />
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('hr_selfservice.annual_leave_balance')" :value="$t('hr2_hr_selfservice.n_days', { n0: leaveBalance.balance })" tone="indigo" />
            <StatCard :label="$t('hr_selfservice.employee_number')" :value="employee.employee_number || '—'" />
            <StatCard :label="$t('hr_selfservice.start_date')" :value="date(employee.hire_date)" />
            <StatCard :label="$t('hr_selfservice.id_residence_expiry')" :value="employee.id_expiry ? `${date(employee.id_expiry)} — ${hijri(employee.id_expiry)}` : '—'" />
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">{{ $t('hr_selfservice.request_leave') }}</h3>
                <select v-model="leave.leave_type_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                <div class="grid grid-cols-2 gap-2"><DatePicker v-model="leave.start_date" class="rounded-xl border-slate-200 text-xs" /><DatePicker v-model="leave.end_date" class="rounded-xl border-slate-200 text-xs" /></div>
                <input v-model="leave.reason" :placeholder="$t('hr_selfservice.reason')" class="w-full rounded-xl border-slate-200 text-xs" />
                <p v-for="(m, k) in leave.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="leave.post(route('hr.me.leaves'), { preserveScroll: true, onSuccess: () => leave.reset('start_date', 'end_date', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_selfservice.send') }}</button>
                <h3 class="font-black text-slate-700 pt-3">{{ $t('hr_selfservice.request_an_advance') }}</h3>
                <div class="grid grid-cols-2 gap-2"><label>{{ $t('hr_selfservice.amount') }}<input v-model.number="loan.amount" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label><label>{{ $t('hr_selfservice.installment') }}<input v-model.number="loan.monthly_installment" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label></div>
                <p v-for="(m, k) in loan.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="loan.post(route('hr.me.loans'), { preserveScroll: true, onSuccess: () => loan.reset() })" class="w-full px-3 py-2 rounded-xl border border-slate-200 font-bold">{{ $t('hr_selfservice.send_the_advance_request') }}</button>
            </div>
            <div v-if="adminLines.length" class="bg-white border border-slate-200 rounded-2xl p-4" data-admin-lines>
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_selfservice.administrative_requests_addresse') }}</h3>
                <div v-for="a in adminLines" :key="a.id" class="py-1 border-b border-slate-50 flex justify-between gap-2"><span>{{ a.title }} <span class="font-mono text-slate-400">{{ a.attend_at ? String(a.attend_at).replace('T', ' ').slice(0, 16) : '' }}</span><span v-if="a.location" class="text-slate-400"> — {{ a.location }}</span></span><span class="font-bold text-slate-600">{{ a.status_label }}</span></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_selfservice.my_requests') }}</h3>
                <div v-for="r in leaveRequests" :key="r.id" class="py-1 border-b border-slate-50">
                    <div class="flex justify-between items-center gap-2"><span>{{ r.type?.name }} <span class="font-mono text-slate-400">{{ date(r.start_date) }} → {{ date(r.end_date) }}</span></span>
                        <span class="flex items-center gap-2"><button v-if="r.status === 'pending'" @click="withdraw('hr.me.leaves.cancel', r.id, $t('hr2_hr_selfservice.withdraw_the_leave_request'))" class="text-rose-600 font-bold">{{ $t('hr_selfservice.withdraw') }}</button><StatusBadge :status="r.status" /></span></div>
                    <p v-if="r.decision_note" class="text-slate-500 mt-0.5">{{ $t('hr2_hr_selfservice.approver_s_note_n', { n0: r.decision_note }) }}</p>
                </div>
                <div v-for="l in loans" :key="'l' + l.id" class="py-1 border-b border-slate-50">
                    <div class="flex justify-between items-center gap-2"><span>{{ $t('hr2_hr_selfservice.advance_n', { n0: money(l.amount) }) }} <span class="text-slate-400">{{ $t('hr2_hr_selfservice.repaid_n', { n0: money(l.repaid) }) }}</span></span>
                        <span class="flex items-center gap-2"><button v-if="l.status === 'pending'" @click="withdraw('hr.me.loans.cancel', l.id, $t('hr2_hr_selfservice.withdraw_the_advance_request'))" class="text-rose-600 font-bold">{{ $t('hr_selfservice.withdraw') }}</button><StatusBadge :status="l.status" /></span></div>
                    <p v-if="l.decision_note" class="text-slate-500 mt-0.5">{{ $t('hr2_hr_selfservice.approver_s_note_n', { n0: l.decision_note }) }}</p>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_selfservice.payslips') }}</h3>
                <div v-for="p in payslips" :key="p.id" class="flex justify-between py-1 border-b border-slate-50"><span class="font-mono">{{ p.payroll.month }}/{{ p.payroll.year }}</span><span><b class="font-mono">{{ money(p.net_salary) }}</b> <Link :href="route('hr.me.payslip', p.id)" class="text-indigo-600 ms-2">{{ $t('hr_selfservice.view') }}</Link></span></div>
                <p v-if="!payslips.length" class="text-slate-400">{{ $t('hr_selfservice.no_payslips_yet') }}</p>
            </div>
        </div>
        </template>
    </AppLayout>
</template>
