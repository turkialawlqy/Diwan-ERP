<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import LineItemsEditor from '@/Components/Documents/LineItemsEditor.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { today } from '@/utils/format';

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
const form = useForm({ request_date: today(), required_date: '', notes: '', items: [] });
const save = () => form.transform((d) => ({ ...d, items: d.items.map((i) => ({ product_id: i.product_id, item_name: i.item_name, quantity: i.quantity, estimated_price: i.estimated_price })) }))
    .post(route('purchases.requests.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('p_requests_index.purchase_requests')" />
        <PageHeader year-scoped :title="$t('p_requests_index.purchase_requests')" :subtitle="$t('p_requests_index.internal_request_approval_purcha')" :crumbs="[{ label: 'المشتريات' }, { label: 'طلبات الشراء' }]">
            <template #actions><button v-if="$can('purchases.requests.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('p_requests_index.purchase_request') }}</button></template>
        </PageHeader>
        <DocumentTable :paginator="requests" :columns="columns" :filters="filters" :statuses="statuses" search-route="purchases.requests.index" :link="(r) => route('purchases.requests.show', r.id)" />
        <Dialog :show="open" :title="$t('p_requests_index.purchase_request')" size="xl" @close="open = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field :label="$t('p_requests_index.date')"><DatePicker v-model="form.request_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_requests_index.required_by')"><DatePicker v-model="form.required_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_requests_index.notes')"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <LineItemsEditor v-model="form.items" price-field="purchase_price" price-key="estimated_price" :price-label="$t('p_requests_show.estimated_price')" :allow-discount="false" />
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="save" :disabled="form.processing || !form.items.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_requests_index.submit_the_request') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
