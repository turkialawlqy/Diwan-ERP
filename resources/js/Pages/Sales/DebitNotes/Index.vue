<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { taxRate } from '@/utils/tax';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { money, today } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ notes: Object, filters: Object });
const columns = [
    { key: 'debit_note_number', label: 'الرقم', type: 'mono' },
    { key: 'customer.name', label: 'العميل' },
    { key: 'invoice.invoice_number', label: 'الفاتورة', type: 'mono' },
    { key: 'debit_note_date', label: 'التاريخ', type: 'date' },
    { key: 'reason', label: 'السبب' },
    { key: 'total', label: 'الإجمالي', type: 'money' },
];
const open = ref(false);
const form = useForm({ customer_id: null, invoice_id: '', debit_note_date: today(), reason: '', subtotal: 0, tax_rate: taxRate() });
const save = () => form.post(route('sales.debit-notes.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_debit.debit_notes')" />
        <PageHeader year-scoped :title="$t('s_debit.debit_notes')" :subtitle="$t('s_debit.additional_amounts_charged_to_th')" :crumbs="[{ label: 'المبيعات' }, { label: 'الإشعارات المدينة' }]">
            <template #actions><button v-if="$can('sales.debit_notes.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_debit.debit_note') }}</button></template>
        </PageHeader>
        <DocumentTable :paginator="notes" :columns="columns" :filters="filters" search-route="sales.debit-notes.index">
            <template #actions="{ row }"><ShareDocument v-if="$can('sales.debit_notes.print')" type="debit_note" :id="row.id" compact /></template>
        </DocumentTable>
        <Dialog :show="open" :title="$t('s_debit.debit_note')" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('s_debit.customer')" :error="form.errors.customer_id" class="col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
                <Field :label="$t('s_debit.date')"><DatePicker v-model="form.debit_note_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_debit.linked_invoice_number_optional')" :error="form.errors.invoice_id"><input v-model.number="form.invoice_id" type="text" inputmode="decimal" data-num :placeholder="$t('s_debit.invoice_id')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_debit.amount_before_tax')" :error="form.errors.subtotal"><input v-model.number="form.subtotal" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('s_debit.tax_rate')"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('s_debit.reason')" :error="form.errors.reason" class="col-span-2"><input v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <p class="text-xs mt-3">{{ $t('s_debit.total') }} <b class="font-mono">{{ money(Number(form.subtotal) * (1 + Number(form.tax_rate) / 100)) }}</b></p>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
