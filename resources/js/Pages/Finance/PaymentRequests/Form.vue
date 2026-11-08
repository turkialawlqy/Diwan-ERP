<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';

const props = defineProps({ suppliers: Array, employees: Array, expenseAccounts: Array, bankAccounts: Array, costCenters: Array, projects: Array, unpaidInvoices: Array, paymentRequest: { type: Object, default: null } });
const r = props.paymentRequest;

const form = useForm({
    request_date: r ? String(r.request_date).slice(0, 10) : today(),
    due_date: r?.due_date ? String(r.due_date).slice(0, 10) : '',
    kind: r?.kind || 'expense',
    payee_type: r?.payee_type || 'supplier',
    supplier_id: r?.supplier_id || '',
    employee_id: r?.employee_id || '',
    payee_name: r?.payee_name || '',
    purpose: r?.purpose || '',
    account_id: r?.account_id || '',
    purchase_invoice_id: r?.purchase_invoice_id || '',
    amount: r ? Number(r.amount) : '',
    payment_method: r?.payment_method || 'bank_transfer',
    bank_account_id: r?.bank_account_id || '',
    cost_center_id: r?.cost_center_id || '',
    project_id: r?.project_id || '',
    notes: r?.notes || '',
    send_for_approval: false,
});
useUnsavedGuard(form);

const invoices = computed(() => props.unpaidInvoices.filter((i) => !form.supplier_id || i.supplier_id === form.supplier_id));
const isSupplierPayment = computed(() => form.kind === 'supplier_payment');
const showSupplier = computed(() => isSupplierPayment.value || form.payee_type === 'supplier');
const save = (submit) => {
    form.send_for_approval = submit;
    if (isSupplierPayment.value) form.payee_type = 'supplier';
    r ? form.put(route('finance.payment-requests.update', r.id)) : form.post(route('finance.payment-requests.store'));
};
</script>

<template>
    <AppLayout>
        <Head :title="r ? $t('prq.edit') : $t('prq.new')" />
        <PageHeader :title="r ? `${$t('prq.edit')} ${r.request_number}` : $t('prq.new')" :crumbs="[{ label: $t('nav.finance') }, { label: $t('prq.title'), href: route('finance.payment-requests.index') }, { label: r ? r.request_number : $t('prq.new') }]" />

        <form @submit.prevent="save(false)" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs" data-payment-request-form>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('prq.date')" :error="form.errors.request_date"><DatePicker v-model="form.request_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prq.due')" :error="form.errors.due_date"><DatePicker v-model="form.due_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prq.kind')">
                    <select v-model="form.kind" class="w-full rounded-xl border-slate-200 text-xs"><option value="expense">{{ $t('prq.k_expense') }}</option><option value="supplier_payment">{{ $t('prq.k_supplier_payment') }}</option></select>
                </Field>
                <Field v-if="!isSupplierPayment" :label="$t('prq.payee_type')">
                    <select v-model="form.payee_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="supplier">{{ $t('prq.p_supplier') }}</option><option value="employee">{{ $t('prq.p_employee') }}</option><option value="other">{{ $t('prq.p_other') }}</option></select>
                </Field>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field v-if="showSupplier" :label="$t('prq.supplier')" :error="form.errors.supplier_id"><SelectCreate v-model="form.supplier_id" :options="suppliers" entity="supplier" label="name" /></Field>
                <Field v-if="!isSupplierPayment && form.payee_type === 'employee'" :label="$t('prq.employee')" :error="form.errors.employee_id"><SelectCreate v-model="form.employee_id" :options="employees" entity="employee" label="name" /></Field>
                <Field v-if="!isSupplierPayment && form.payee_type === 'other'" :label="$t('prq.payee_name')" :error="form.errors.payee_name"><input v-model="form.payee_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="!isSupplierPayment" :label="$t('prq.account')" :error="form.errors.account_id"><SelectCreate v-model="form.account_id" :options="expenseAccounts" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" /></Field>
                <Field v-if="isSupplierPayment" :label="$t('prq.invoice')" :error="form.errors.purchase_invoice_id"><SelectCreate v-model="form.purchase_invoice_id" :options="invoices" :label="(i) => `${i.invoice_number} (${i.remaining_amount})`" :placeholder="$t('prq.none')" /></Field>
                <Field :label="$t('prq.amount')" :error="form.errors.amount"><input v-model="form.amount" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('prq.method')">
                    <select v-model="form.payment_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">{{ $t('prq.m_bank_transfer') }}</option><option value="cash">{{ $t('prq.m_cash') }}</option><option value="card">{{ $t('prq.m_card') }}</option><option value="cheque">{{ $t('prq.m_cheque') }}</option></select>
                </Field>
                <Field :label="$t('prq.bank')"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('prq.none')" /></Field>
                <Field :label="$t('prq.cost_center')"><SelectCreate v-model="form.cost_center_id" :options="costCenters" entity="cost_center" :label="(c) => `${c.code} - ${c.name_ar}`" :placeholder="$t('prq.none')" /></Field>
                <Field :label="$t('prq.project')"><SelectCreate v-model="form.project_id" :options="projects" label="name" :placeholder="$t('prq.none')" /></Field>
            </div>
            <Field :label="$t('prq.purpose')" :error="form.errors.purpose"><input v-model="form.purpose" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('prq.notes')"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            <p v-for="(msg, key) in form.errors" :key="key" v-show="!['request_date', 'due_date', 'supplier_id', 'employee_id', 'payee_name', 'account_id', 'purchase_invoice_id', 'amount', 'purpose'].includes(key)" class="text-rose-600">{{ msg }}</p>
            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold" data-prq-save>{{ $t('prq.save_draft') }}</button>
                <button type="button" :disabled="form.processing" @click="save(true)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-prq-save-submit>{{ $t('prq.save_submit') }}</button>
            </div>
        </form>
    </AppLayout>
</template>
