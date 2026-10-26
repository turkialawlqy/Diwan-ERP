<script setup>
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
import { money } from '@/utils/format';
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
const form = useForm({ customer_id: null, invoice_id: '', debit_note_date: new Date().toISOString().substring(0, 10), reason: '', subtotal: 0, tax_rate: taxRate() });
const save = () => form.post(route('sales.debit-notes.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout>
        <Head title="الإشعارات المدينة" />
        <PageHeader title="الإشعارات المدينة" subtitle="مبالغ إضافية على العميل (فرق سعر، رسوم...) تزيد مديونيته وضريبة المخرجات" :crumbs="[{ label: 'المبيعات' }, { label: 'الإشعارات المدينة' }]">
            <template #actions><button v-if="$can('sales.debit_notes.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> إشعار مدين</button></template>
        </PageHeader>
        <DocumentTable :paginator="notes" :columns="columns" :filters="filters" search-route="sales.debit-notes.index">
            <template #actions="{ row }"><ShareDocument v-if="$can('sales.debit_notes.print')" type="debit_note" :id="row.id" compact /></template>
        </DocumentTable>
        <Dialog :show="open" title="إشعار مدين" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="العميل" :error="form.errors.customer_id" class="col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
                <Field label="التاريخ"><input v-model="form.debit_note_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="رقم الفاتورة المرتبطة (اختياري)" :error="form.errors.invoice_id"><input v-model="form.invoice_id" type="number" placeholder="معرّف الفاتورة" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ قبل الضريبة" :error="form.errors.subtotal"><input v-model="form.subtotal" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="نسبة الضريبة"><input v-model="form.tax_rate" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="السبب" :error="form.errors.reason" class="col-span-2"><input v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <p class="text-xs mt-3">الإجمالي: <b class="font-mono">{{ money(Number(form.subtotal) * (1 + Number(form.tax_rate) / 100)) }}</b></p>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
