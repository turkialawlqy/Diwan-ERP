<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DimensionFields from '@/Components/Documents/DimensionFields.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { taxRate } from '@/utils/tax';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ expenseAccounts: Array, bankAccounts: Array, costCenters: Array, projects: Array, dimensionPolicy: Object, expense: { type: Object, default: null }, prefill: { type: Object, default: null } });
const editing = props.expense;

const form = useForm({
    title: '',
    category: t('fin_finance_expenses_form.general_expenses'),
    account_id: props.expenseAccounts?.[0]?.id || '',
    cost_center_id: '',
    project_id: '',
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    amount: '',
    tax_amount: '',
    taxable: false,
    vendor_tax_number: '',
    expense_date: today(),
    payment_method: 'bank_transfer',
    reference_number: '',
    vendor_name: '',
    notes: '',
    payment_request_id: '',
});
// Carrying out an approved payment request: its data fills the voucher and the request is tied to it.
if (props.prefill) {
    const p = props.prefill;
    Object.assign(form, { title: p.title || '', account_id: p.account_id || form.account_id, amount: p.amount, cost_center_id: p.cost_center_id || '', project_id: p.project_id || '',
        bank_account_id: p.bank_account_id || form.bank_account_id, payment_method: p.payment_method || form.payment_method, vendor_name: p.vendor_name || '', notes: p.notes || '', payment_request_id: p.payment_request_id });
    form.defaults();
}
// Editing: the voucher as saved (same number; saving reverses and re-posts it).
if (editing) {
    Object.assign(form, {
        title: editing.title, category: editing.category, account_id: editing.account_id || '', cost_center_id: editing.cost_center_id || '',
        project_id: editing.project_id || '', bank_account_id: editing.bank_account_id || '', amount: Number(editing.amount),
        tax_amount: Number(editing.tax_amount) || '', taxable: Number(editing.tax_amount) > 0,
        vendor_tax_number: editing.vendor_tax_number || '',
        expense_date: String(editing.expense_date).substring(0, 10), payment_method: editing.payment_method,
        reference_number: editing.reference_number || '', vendor_name: editing.vendor_name || '', notes: editing.notes || '',
    });
    form.defaults();
}
useUnsavedGuard(form);

const onAmountChange = () => {
    if (form.amount && form.taxable) {
        form.tax_amount = (parseFloat(form.amount) * taxRate() / 100).toFixed(2);
    } else {
        form.tax_amount = '0.00';
    }
};

const formatCurrency = (val) => Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const submitExpense = () => (editing ? form.put(route('finance.expenses.update', editing.id)) : form.post(route('finance.expenses.store')));
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('fin_finance_expenses_form.edit_voucher_n', { n0: editing.expense_number }) : $t('fin_finance_expenses_form.new_expense_voucher')" />
        <PageHeader :title="editing ? $t('fin_finance_expenses_form.edit_voucher_n', { n0: editing.expense_number }) : $t('fin_finance_expenses_form.new_expense_voucher')" :crumbs="[{ label: $t('fin_finance_expenses_form.finance') }, { label: $t('fin_finance_expenses_form.expenses'), href: route('finance.expenses.index') }, { label: editing ? $t('fin_finance_expenses_form.edit') : $t('fin_finance_expenses_form.new') }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitExpense" class="form-grid text-xs">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.expense_description_title') }} <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.title" 
                                    type="text" 
                                    required 
                                    :placeholder="$t('fin_finance_expenses_form.example_paying_the_september_ele')"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                />
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.category') }} <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model="form.category" 
                                        type="text" 
                                        required 
                                        :placeholder="$t('fin_finance_expenses_form.rent_salaries_bills')"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.payment_date') }} <span class="text-rose-500">*</span></label>
                                    <DatePicker 
                                        v-model="form.expense_date" 
                                        required 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" />
                                </div>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.amount_excluding_tax') }} <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model.number="form.amount" 
                                        @input="onAmountChange"
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        required 
                                        placeholder="0.00"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-rose-500 focus:border-rose-500 text-end" 
                                    />
                                </div>
                                <div>
                                    <label class="flex items-center gap-2 font-medium text-slate-700 mb-1"><input v-model="form.taxable" type="checkbox" @change="onAmountChange" class="rounded border-slate-300" /> {{ $t('fin_finance_expenses_form.subject_to_n', { n0: $taxLabel() }) }}</label>
                                    <input 
                                        v-model.number="form.tax_amount" 
                                        :disabled="!form.taxable"
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        placeholder="0.00"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-rose-500 focus:border-rose-500 text-end" 
                                    />
                                </div>
                            </div>
            
                            <div class="p-3 bg-rose-50/50 rounded-xl flex items-center justify-between text-xs border border-rose-100">
                                <span class="font-bold text-rose-900">{{ $t('fin_finance_expenses_form.grand_total_including_tax') }}</span>
                                <span class="font-mono font-black text-sm text-rose-600">
                                    {{ formatCurrency((parseFloat(form.amount || 0) + parseFloat(form.tax_amount || 0))) }} ﷼
                                </span>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.paid_from_cash_box_or_bank') }}</label>
                                    <SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" :label="(bank) => $t('fin_finance_expenses_form.n_balance_n_sar', { n0: bank.name, n1: formatCurrency(bank.current_balance) })" :allow-empty="false" />
                                    <p v-if="form.errors.bank_account_id" class="text-rose-600 text-3xs mt-1">{{ form.errors.bank_account_id }}</p>
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.expense_account_in_the_chart') }}</label>
                                    <SelectCreate v-model="form.account_id" :options="expenseAccounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar}`" :allow-empty="false" />
                                    <p v-if="form.errors.account_id" class="text-rose-600 text-3xs mt-1">{{ form.errors.account_id }}</p>
                                </div>
                            </div>
                            <DimensionFields v-model:cost-center="form.cost_center_id" v-model:project="form.project_id" :account="{ type: 'expense', requires_cost_center: expenseAccounts.find((a) => a.id === form.account_id)?.requires_cost_center }" :policy="dimensionPolicy" :cost-centers="costCenters" :projects="projects" :errors="form.errors" />

            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.payee') }}</label>
                                    <input 
                                        v-model="form.vendor_name" 
                                        type="text" 
                                        :placeholder="$t('fin_finance_expenses_form.company_or_person_name')"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                    />
                                    <template v-if="form.taxable">
                                        <label class="block font-medium text-slate-700 mt-2 mb-1" for="vendor-vat">{{ $t('fin_finance_expenses_form.supplier_tax_number') }}</label>
                                        <input id="vendor-vat" v-model="form.vendor_tax_number" dir="ltr" maxlength="15" placeholder="3xxxxxxxxxxxxx3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" />
                                        <p v-if="form.errors.vendor_tax_number" class="text-rose-600 text-3xs mt-1">{{ form.errors.vendor_tax_number }}</p>
                                    </template>
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.payment_method') }}</label>
                                    <select 
                                        v-model="form.payment_method" 
                                        class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                    >
                                        <option value="bank_transfer">{{ $t('fin_finance_expenses_form.bank_transfer') }}</option>
                                        <option value="cash">{{ $t('fin_finance_expenses_form.cash') }}</option>
                                        <option value="card">{{ $t('fin_finance_expenses_form.mada_credit_card') }}</option>
                                    </select>
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_expenses_form.notes_or_details') }}</label>
                                <textarea 
                                    v-model="form.notes" 
                                    rows="2" 
                                    :placeholder="$t('fin_finance_expenses_form.any_additional_details_about_the')"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                ></textarea>
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <Link :href="route('finance.expenses.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('fin_finance_expenses_form.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing"
                                    class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-bold shadow-xs hover:shadow-rose-500/20 disabled:opacity-50"
                                >
                                    {{ $t('fin_finance_expenses_form.save_voucher') }}
                                </button>
                            </div>
                        </form>
        </div>
        <AttachmentsPanel v-if="expense?.id" kind="expense" :id="expense.id" class="mt-4" />
    </AppLayout>
</template>
