<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { today } from '@/utils/format';

defineProps({ costs: Object, invoices: Array, bankAccounts: Array });
const columns = [
    { key: 'landed_number', label: 'الرقم', type: 'mono' },
    { key: 'purchase_invoice.invoice_number', label: 'فاتورة الشراء', type: 'mono' },
    { key: 'date', label: 'التاريخ', type: 'date' },
    { key: 'description', label: 'البيان' },
    { key: 'supplier.name', label: 'الجهة' },
    { key: 'amount', label: 'المبلغ', type: 'money' },
];
const open = ref(false);
const form = useForm({ purchase_invoice_id: '', date: today(), description: '', amount: 0, tax_amount: 0, paid_from: 'payables', supplier_id: null, bank_account_id: '' });
const save = () => form.post(route('purchases.landed-costs.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout>
        <Head :title="$t('p_landedcosts_index.landed_costs')" />
        <PageHeader :title="$t('p_landedcosts_index.landed_costs_on_purchases')" :subtitle="$t('p_landedcosts_index.freight_customs_and_clearance_ar')" :crumbs="[{ label: 'المشتريات' }, { label: 'التكاليف الإضافية' }]">
            <template #actions><button v-if="$can('purchases.landed_costs.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('p_landedcosts_index.additional_cost') }}</button></template>
        </PageHeader>
        <DocumentTable :paginator="costs" :columns="columns" />
        <Dialog :show="open" :title="$t('p_landedcosts_index.additional_cost_on_a_purchase_in')" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('p_landedcosts_index.purchase_invoice')" :error="form.errors.purchase_invoice_id" class="col-span-2">
                    <select v-model="form.purchase_invoice_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">{{ $t('p_landedcosts_index.choose') }}</option><option v-for="i in invoices" :key="i.id" :value="i.id">{{ i.invoice_number }} {{ i.supplier_invoice_number ? `(${i.supplier_invoice_number})` : '' }}</option></select>
                </Field>
                <Field :label="$t('p_landedcosts_index.description')" :error="form.errors.description" class="col-span-2"><input v-model="form.description" :placeholder="$t('p_landedcosts_index.sea_freight_customs_duties')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_landedcosts_index.date')"><DatePicker v-model="form.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_landedcosts_index.amount_before_tax')" :error="form.errors.amount"><input v-model.number="form.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('p_landedcosts_index.input_tax')"><input v-model.number="form.tax_amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('p_landedcosts_index.payment')"><select v-model="form.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="payables">{{ $t('p_landedcosts_index.on_credit_to_the_service_supplie') }}</option><option value="cash">{{ $t('p_landedcosts_index.cash') }}</option><option value="bank_transfer">{{ $t('p_landedcosts_index.bank') }}</option></select></Field>
                <Field v-if="form.paid_from === 'payables'" :label="$t('p_landedcosts_index.service_supplier')" :error="form.errors.supplier_id" class="col-span-2"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field v-else :label="$t('p_landedcosts_index.account')" class="col-span-2"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('p_landedcosts_index.default')" /></Field>
            </div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
