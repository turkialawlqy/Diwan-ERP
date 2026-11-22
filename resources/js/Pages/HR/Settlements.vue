<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import FormErrors from '@/Components/UI/FormErrors.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, today } from '@/utils/format';
const { t } = useI18n();

defineProps({ settlements: Object, employees: Array, reasons: Object, bankAccounts: Array });
const form = useForm({ employee_id: '', termination_date: today(), reason: 'resignation', salary_days: 0, other_dues: 0, other_deductions: 0, notes: '' });
const provision = useForm({ date: today() });
const paying = ref(null);
const payForm = useForm({ date: today(), bank_account_id: '' });
const discard = (s) => { if (confirm(t('hr2_hr_settlements.delete_the_settlement_draft_noth'))) router.delete(route('hr.settlements.cancel', s.id), { preserveScroll: true }); };
const approve = (s) => { if (confirm(t('hr2_hr_settlements.approve_the_settlement_and_end_t'))) router.post(route('hr.settlements.approve', s.id), {}, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_settlements.end_of_service')" />
        <PageHeader :title="$t('hr_settlements.end_of_service_awards_and_settle')" :subtitle="$t('hr_settlements.article_84_half_a_month_per_year')" :crumbs="[{ label: $t('hr2_hr_settlements.human_resources') }, { label: $t('hr2_hr_settlements.end_of_service') }]">
            <template #actions>
                <div v-if="$can('hr.settlements.approve')" class="flex gap-2 items-center"><DatePicker v-model="provision.date" class="rounded-xl border-slate-200 text-xs" /><button @click="provision.post(route('hr.settlements.provision'))" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('hr_settlements.update_the_end_of_service_provis') }}</button></div>
            </template>
        </PageHeader>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_settlements.employee') }}</th><th class="px-3 py-2">{{ $t('hr_settlements.reason') }}</th><th class="px-3 py-2">{{ $t('hr_settlements.service') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_settlements.award') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_settlements.leave') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_settlements.net') }}</th><th class="px-3 py-2">{{ $t('hr_settlements.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in settlements.data" :key="s.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ s.employee?.name }}<div class="text-slate-400 font-mono">{{ date(s.termination_date) }}</div></td>
                            <td class="px-3 py-2 text-center">{{ reasons[s.reason] }}</td><td class="px-3 py-2 text-center font-mono">{{ Number(s.service_years).toFixed(2) }}</td>
                            <td class="px-3 py-2 text-end font-mono" :title="$t('hr2_hr_settlements.full_n', { n0: money(s.eos_full) })">{{ money(s.eos_amount) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(s.leave_encashment) }} <span class="text-slate-400">{{ $t('hr2_hr_settlements.n_days', { n0: s.leave_days }) }}</span></td>
                            <td class="px-3 py-2 text-end font-mono font-bold">{{ money(s.net_amount) }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="s.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                <button v-if="s.status === 'draft' && $can('hr.settlements.approve')" @click="approve(s)" class="text-emerald-600 font-bold">{{ $t('hr_settlements.approve') }}</button>
                                <button v-if="s.status === 'draft' && $can('hr.settlements.create')" @click="discard(s)" class="text-rose-600 font-bold" data-discard-settlement>{{ $t('hr_settlements.delete_the_draft') }}</button>
                                <button v-if="s.status === 'approved' && $can('hr.settlements.approve') && Number(s.net_amount) > 0" @click="paying = s" class="text-indigo-600 font-bold">{{ $t('hr_settlements.pay') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!settlements.data.length"><td colspan="8" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table>
                <Pagination :paginator="settlements" />
            </div>
            <div v-if="$can('hr.settlements.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">{{ $t('hr_settlements.calculate_a_settlement') }}</h3>
                <SelectCreate v-model="form.employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('hr_settlements.employee_2')" />
                <select v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in reasons" :key="k" :value="k">{{ l }}</option></select>
                <label class="block">{{ $t('hr_settlements.last_working_day') }}<DatePicker v-model="form.termination_date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">{{ $t('hr_settlements.unpaid_earned_salary_days') }}<input v-model.number="form.salary_days" type="text" inputmode="decimal" data-num step="1" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <div class="grid grid-cols-2 gap-2"><label>{{ $t('hr_settlements.other_dues') }}<input v-model.number="form.other_dues" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label><label>{{ $t('hr_settlements.other_deductions') }}<input v-model.number="form.other_deductions" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label></div>
                <p class="text-slate-500">{{ $t('hr_settlements.the_leave_balance_and_advance_ba') }}</p>
                <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="form.post(route('hr.settlements.store'), { preserveScroll: true })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_settlements.calculate') }}</button>
            </div>
        </div>
        <Dialog :show="!!paying" :title="$t('hr_settlements.pay_the_settlement')" @close="paying = null">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('hr_settlements.date')" :error="payForm.errors.date"><DatePicker v-model="payForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('hr_settlements.account')" :error="payForm.errors.bank_account_id"><SelectCreate v-model="payForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('hr_settlements.default')" /></Field>
            </div>
            <FormErrors :errors="payForm.errors" :except="['date', 'bank_account_id']" class="mt-3" />
            <template #footer><button @click="payForm.post(route('hr.settlements.pay', paying.id), { onSuccess: () => (paying = null) })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('hr2_hr_settlements.pay_n', { n0: money(paying?.net_amount) }) }}</button></template>
        </Dialog>
    </AppLayout>
</template>
