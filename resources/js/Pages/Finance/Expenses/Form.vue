<script setup>
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

const props = defineProps({ expenseAccounts: Array, bankAccounts: Array, costCenters: Array, projects: Array, dimensionPolicy: Object, expense: { type: Object, default: null }, prefill: { type: Object, default: null } });
const editing = props.expense;

const form = useForm({
    title: '',
    category: 'مصروفات عامة',
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
        <Head :title="editing ? `تعديل السند ${editing.expense_number}` : 'سند مصروف جديد'" />
        <PageHeader :title="editing ? `تعديل السند ${editing.expense_number}` : 'سند مصروف جديد'" :crumbs="[{ label: 'المالية' }, { label: 'المصروفات', href: route('finance.expenses.index') }, { label: editing ? 'تعديل' : 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitExpense" class="form-grid text-xs">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">بيان / عنوان المصروف <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.title" 
                                    type="text" 
                                    required 
                                    placeholder="مثال: سداد فاتورة الكهرباء لشهر سبتمبر"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                />
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">التصنيف <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model="form.category" 
                                        type="text" 
                                        required 
                                        placeholder="إيجار، رواتب، فواتير..."
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">تاريخ الصرف <span class="text-rose-500">*</span></label>
                                    <DatePicker 
                                        v-model="form.expense_date" 
                                        required 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" />
                                </div>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">المبلغ (غير شامل الضريبة) <span class="text-rose-500">*</span></label>
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
                                    <label class="flex items-center gap-2 font-medium text-slate-700 mb-1"><input v-model="form.taxable" type="checkbox" @change="onAmountChange" class="rounded border-slate-300" /> خاضع لـ{{ $taxLabel() }}</label>
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
                                <span class="font-bold text-rose-900">المجموع الإجمالي شامل الضريبة:</span>
                                <span class="font-mono font-black text-sm text-rose-600">
                                    {{ formatCurrency((parseFloat(form.amount || 0) + parseFloat(form.tax_amount || 0))) }} ﷼
                                </span>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">مدفوع من (الخزينة أو البنك)</label>
                                    <SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" :label="(bank) => `${bank.name} (الرصيد: ${formatCurrency(bank.current_balance)} ﷼)`" :allow-empty="false" />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">حساب المصروف في الدليل</label>
                                    <SelectCreate v-model="form.account_id" :options="expenseAccounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar}`" :allow-empty="false" />
                                </div>
                            </div>
                            <DimensionFields v-model:cost-center="form.cost_center_id" v-model:project="form.project_id" :account="{ type: 'expense', requires_cost_center: expenseAccounts.find((a) => a.id === form.account_id)?.requires_cost_center }" :policy="dimensionPolicy" :cost-centers="costCenters" :projects="projects" :errors="form.errors" />

            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">الجهة المستفيدة / المدفوع له</label>
                                    <input 
                                        v-model="form.vendor_name" 
                                        type="text" 
                                        placeholder="اسم الشركة أو الشخص"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                    />
                                    <template v-if="form.taxable">
                                        <label class="block font-medium text-slate-700 mt-2 mb-1" for="vendor-vat">الرقم الضريبي للمورد</label>
                                        <input id="vendor-vat" v-model="form.vendor_tax_number" dir="ltr" maxlength="15" placeholder="3xxxxxxxxxxxxx3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" />
                                        <p v-if="form.errors.vendor_tax_number" class="text-rose-600 text-3xs mt-1">{{ form.errors.vendor_tax_number }}</p>
                                    </template>
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">طريقة الدفع</label>
                                    <select 
                                        v-model="form.payment_method" 
                                        class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                    >
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="cash">نقداً</option>
                                        <option value="card">بطاقة مدى / ائتمان</option>
                                    </select>
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">ملاحظات أو تفاصيل</label>
                                <textarea 
                                    v-model="form.notes" 
                                    rows="2" 
                                    placeholder="أي تفاصيل إضافية عن السند..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                ></textarea>
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <Link :href="route('finance.expenses.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing"
                                    class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-bold shadow-xs hover:shadow-rose-500/20 disabled:opacity-50"
                                >
                                    حفظ السند
                                </button>
                            </div>
                        </form>
        </div>
        <AttachmentsPanel v-if="expense?.id" kind="expense" :id="expense.id" class="mt-4" />
    </AppLayout>
</template>
