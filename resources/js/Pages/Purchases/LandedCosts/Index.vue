<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

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
const form = useForm({ purchase_invoice_id: '', date: new Date().toISOString().substring(0, 10), description: '', amount: 0, tax_amount: 0, paid_from: 'payables', supplier_id: null, bank_account_id: '' });
const save = () => form.post(route('purchases.landed-costs.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout>
        <Head title="التكاليف الإضافية" />
        <PageHeader title="التكاليف الإضافية على المشتريات" subtitle="الشحن والجمارك والتخليص تُوزّع على تكلفة الأصناف بنسبة قيمتها" :crumbs="[{ label: 'المشتريات' }, { label: 'التكاليف الإضافية' }]">
            <template #actions><button v-if="$can('purchases.landed_costs.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> تكلفة إضافية</button></template>
        </PageHeader>
        <DocumentTable :paginator="costs" :columns="columns" />
        <Dialog :show="open" title="تكلفة إضافية على فاتورة شراء" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="فاتورة الشراء" :error="form.errors.purchase_invoice_id" class="col-span-2">
                    <select v-model="form.purchase_invoice_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">اختر...</option><option v-for="i in invoices" :key="i.id" :value="i.id">{{ i.invoice_number }} {{ i.supplier_invoice_number ? `(${i.supplier_invoice_number})` : '' }}</option></select>
                </Field>
                <Field label="البيان" :error="form.errors.description" class="col-span-2"><input v-model="form.description" placeholder="شحن بحري، رسوم جمركية..." class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="التاريخ"><input v-model="form.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ قبل الضريبة" :error="form.errors.amount"><input v-model="form.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="ضريبة المدخلات"><input v-model="form.tax_amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="السداد"><select v-model="form.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="payables">آجل على مورد الخدمة</option><option value="cash">نقدي</option><option value="bank_transfer">بنكي</option></select></Field>
                <Field v-if="form.paid_from === 'payables'" label="مورد الخدمة" :error="form.errors.supplier_id" class="col-span-2"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field v-else label="الحساب" class="col-span-2"><select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
