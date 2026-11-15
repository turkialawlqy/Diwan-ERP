<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ask } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, today } from '@/utils/format';
const { t } = useI18n();

defineProps({ loans: Object, employees: Array, bankAccounts: Array });
const form = useForm({ employee_id: '', date: today(), amount: 0, monthly_installment: 0, first_deduction_month: '', reason: '' });
const bank = ref('');
const decide = async (loan, approve) => {
    let note = null;
    if (!approve) {
        note = await ask(t('hr2_hr_loans.reason_for_rejection_shown_to_th'), { title: t('hr2_hr_loans.reject_the_advance_request'), type: 'textarea', confirmText: t('hr2_hr_loans.reject'), danger: true });
        if (note === null) return;
    }
    router.post(route('hr.loans.decide', loan.id), { approve, note, bank_account_id: bank.value || null }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_loans.advances')" />
        <PageHeader :title="$t('hr_loans.employee_advances')" :subtitle="$t('hr_loans.paid_on_approval_installments_ar')" :crumbs="[{ label: $t('hr2_hr_loans.human_resources') }, { label: $t('hr2_hr_loans.advances') }]" />
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="p-3 border-b border-slate-100 flex items-center gap-2">{{ $t('hr_loans.pay_from') }} <SelectCreate v-model="bank" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('hr_loans.default_account')" /></div>
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_loans.employee') }}</th><th class="px-3 py-2">{{ $t('hr_loans.date') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_loans.amount') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_loans.installment') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_loans.repaid') }}</th><th class="px-3 py-2">{{ $t('hr_loans.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in loans.data" :key="l.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ l.employee?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.date) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.amount) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.monthly_installment) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.repaid) }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="l.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="l.status === 'pending' && $can('hr.loans.approve')"><button @click="decide(l, true)" class="text-emerald-600 font-bold me-2">{{ $t('hr_loans.approve_and_pay') }}</button><button @click="decide(l, false)" class="text-rose-600 font-bold">{{ $t('hr_loans.reject') }}</button></template></td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :paginator="loans" />
            </div>
            <div v-if="$can('hr.loans.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">{{ $t('hr_loans.new_advance') }}</h3>
                <SelectCreate v-model="form.employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('hr_loans.employee_2')" />
                <label class="block">{{ $t('hr_loans.date') }}<DatePicker v-model="form.date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">{{ $t('hr_loans.amount') }}<input v-model.number="form.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <label class="block">{{ $t('hr_loans.monthly_installment') }}<input v-model.number="form.monthly_installment" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <label class="block">{{ $t('hr_loans.first_deduction_month') }}<DatePicker v-model="form.first_deduction_month" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <input v-model="form.reason" :placeholder="$t('hr_loans.reason')" class="w-full rounded-xl border-slate-200 text-xs" />
                <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="form.post(route('hr.loans.store'), { preserveScroll: true, onSuccess: () => form.reset('amount', 'monthly_installment', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_loans.record_the_request') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
