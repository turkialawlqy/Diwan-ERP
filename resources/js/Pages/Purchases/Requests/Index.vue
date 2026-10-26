<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import LineItemsEditor from '@/Components/Documents/LineItemsEditor.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ requests: Object, filters: Object });
const columns = [
    { key: 'request_number', label: 'الرقم', type: 'mono' },
    { key: 'request_date', label: 'التاريخ', type: 'date' },
    { key: 'required_date', label: 'مطلوب بتاريخ', type: 'date' },
    { key: 'requester.name', label: 'مقدم الطلب' },
    { key: 'items_count', label: 'الأصناف' },
    { key: 'status', label: 'الحالة', type: 'status' },
];
const statuses = [{ value: 'pending_approval', label: 'بانتظار الاعتماد' }, { value: 'approved', label: 'معتمد' }, { value: 'ordered', label: 'تم الطلب' }, { value: 'rejected', label: 'مرفوض' }];
const open = ref(false);
const form = useForm({ request_date: new Date().toISOString().substring(0, 10), required_date: '', notes: '', items: [] });
const save = () => form.transform((d) => ({ ...d, items: d.items.map((i) => ({ product_id: i.product_id, item_name: i.item_name, quantity: i.quantity, estimated_price: i.estimated_price })) }))
    .post(route('purchases.requests.store'));
</script>

<template>
    <AppLayout>
        <Head title="طلبات الشراء" />
        <PageHeader title="طلبات الشراء" subtitle="طلب داخلي ← اعتماد ← أمر شراء للمورد" :crumbs="[{ label: 'المشتريات' }, { label: 'طلبات الشراء' }]">
            <template #actions><button v-if="$can('purchases.requests.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> طلب شراء</button></template>
        </PageHeader>
        <DocumentTable :paginator="requests" :columns="columns" :filters="filters" :statuses="statuses" search-route="purchases.requests.index" :link="(r) => route('purchases.requests.show', r.id)" />
        <Dialog :show="open" title="طلب شراء" size="xl" @close="open = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field label="التاريخ"><input v-model="form.request_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مطلوب بتاريخ"><input v-model="form.required_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="ملاحظات"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <LineItemsEditor v-model="form.items" price-field="purchase_price" price-key="estimated_price" price-label="السعر التقديري" :allow-discount="false" />
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="save" :disabled="form.processing || !form.items.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إرسال الطلب</button></template>
        </Dialog>
    </AppLayout>
</template>
