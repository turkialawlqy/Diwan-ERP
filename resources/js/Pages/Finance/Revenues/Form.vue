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

const props = defineProps({ revenueAccounts: Array, bankAccounts: Array, costCenters: Array, projects: Array, dimensionPolicy: Object, revenue: { type: Object, default: null } });
const editing = props.revenue;

const form = useForm({
    title: '',
    category: t('fin_finance_revenues_form.general_revenue'),
    account_id: props.revenueAccounts?.[0]?.id || '',
    cost_center_id: '',
    project_id: '',
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    amount: '',
    tax_amount: '',
    taxable: false,
    revenue_date: today(),
    payment_method: 'bank_transfer',
    reference_number: '',
    customer_name: '',
    notes: '',
});
// Editing: the voucher as saved (same number; saving reverses and re-posts it).
if (editing) {
    Object.assign(form, {
        title: editing.title, category: editing.category, account_id: editing.account_id || '', cost_center_id: editing.cost_center_id || '',
        project_id: editing.project_id || '', bank_account_id: editing.bank_account_id || '', amount: Number(editing.amount),
        tax_amount: Number(editing.tax_amount) || '', taxable: Number(editing.tax_amount) > 0,
        revenue_date: String(editing.revenue_date).substring(0, 10), payment_method: editing.payment_method,
        reference_number: editing.reference_number || '', customer_name: editing.customer_name || '', notes: editing.notes || '',
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

const submitRevenue = () => (editing ? form.put(route('finance.revenues.update', editing.id)) : form.post(route('finance.revenues.store')));
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('fin_finance_revenues_form.edit_voucher_n', { n0: editing.revenue_number }) : $t('fin_finance_revenues_form.new_revenue_voucher')" />
        <PageHeader :title="editing ? $t('fin_finance_revenues_form.edit_voucher_n', { n0: editing.revenue_number }) : $t('fin_finance_revenues_form.new_revenue_voucher')" :crumbs="[{ label: $t('fin_finance_revenues_form.finance') }, { label: $t('fin_finance_revenues_form.revenue'), href: route('finance.revenues.index') }, { label: editing ? $t('fin_finance_revenues_form.edit') : $t('fin_finance_revenues_form.new') }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitRevenue" class="form-grid text-xs">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.revenue_description_source') }} <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.title" 
                                    type="text" 
                                    required 
                                    :placeholder="$t('fin_finance_revenues_form.example_consulting_and_software')"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                                />
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.category') }} <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model="form.category" 
                                        type="text" 
                                        required 
                                        :placeholder="$t('fin_finance_revenues_form.consulting_services_returns')"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.receipt_date') }} <span class="text-rose-500">*</span></label>
                                    <DatePicker 
                                        v-model="form.revenue_date" 
                                        required 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" />
                                </div>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.amount_excluding_tax') }} <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model.number="form.amount" 
                                        @input="onAmountChange"
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        required 
                                        placeholder="0.00"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-emerald-500 focus:border-emerald-500 text-end" 
                                    />
                                </div>
                                <div>
                                    <label class="flex items-center gap-2 font-medium text-slate-700 mb-1"><input v-model="form.taxable" type="checkbox" @change="onAmountChange" class="rounded border-slate-300" /> {{ $t('fin_finance_revenues_form.subject_to_n', { n0: $taxLabel() }) }}</label>
                                    <input 
                                        v-model.number="form.tax_amount" 
                                        :disabled="!form.taxable"
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        placeholder="0.00"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-emerald-500 focus:border-emerald-500 text-end" 
                                    />
                                </div>
                            </div>
            
                            <div class="p-3 bg-emerald-50/50 rounded-xl flex items-center justify-between text-xs border border-emerald-100">
                                <span class="font-bold text-emerald-900">{{ $t('fin_finance_revenues_form.grand_total_including_tax') }}</span>
                                <span class="font-mono font-black text-sm text-emerald-600">
                                    {{ formatCurrency((parseFloat(form.amount || 0) + parseFloat(form.tax_amount || 0))) }} ﷼
                                </span>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.deposit_into_cash_box_or_bank') }}</label>
                                    <SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" :label="(bank) => $t('fin_finance_revenues_form.n_balance_n_sar', { n0: bank.name, n1: formatCurrency(bank.current_balance) })" :allow-empty="false" />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.revenue_account_in_the_chart') }}</label>
                                    <SelectCreate v-model="form.account_id" :options="revenueAccounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar}`" :allow-empty="false" />
                                </div>
                            </div>
                            <DimensionFields v-model:cost-center="form.cost_center_id" v-model:project="form.project_id" :account="{ type: 'revenue', requires_cost_center: revenueAccounts.find((a) => a.id === form.account_id)?.requires_cost_center }" :policy="dimensionPolicy" :cost-centers="costCenters" :projects="projects" :errors="form.errors" />

            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.customer_payer') }}</label>
                                    <input 
                                        v-model="form.customer_name" 
                                        type="text" 
                                        :placeholder="$t('fin_finance_revenues_form.company_or_customer_name')"
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.collection_method') }}</label>
                                    <select 
                                        v-model="form.payment_method" 
                                        class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                                    >
                                        <option value="bank_transfer">{{ $t('fin_finance_revenues_form.bank_transfer') }}</option>
                                        <option value="cash">{{ $t('fin_finance_revenues_form.cash') }}</option>
                                        <option value="card">{{ $t('fin_finance_revenues_form.mada_network_card') }}</option>
                                    </select>
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('fin_finance_revenues_form.notes_or_details') }}</label>
                                <textarea 
                                    v-model="form.notes" 
                                    rows="2" 
                                    :placeholder="$t('fin_finance_revenues_form.any_additional_details_about_the')"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                                ></textarea>
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <Link :href="route('finance.revenues.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('fin_finance_revenues_form.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing"
                                    class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold shadow-xs hover:shadow-emerald-500/20 disabled:opacity-50"
                                >
                                    {{ $t('fin_finance_revenues_form.save_the_revenue') }}
                                </button>
                            </div>
                        </form>
        </div>
        <AttachmentsPanel v-if="revenue?.id" kind="revenue" :id="revenue.id" class="mt-4" />
    </AppLayout>
</template>
