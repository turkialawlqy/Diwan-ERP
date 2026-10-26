<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';

defineProps({ receipts: Object, filters: Object });
const columns = [
    { key: 'receipt_number', label: 'الرقم', type: 'mono' },
    { key: 'supplier.name', label: 'المورد' },
    { key: 'purchase_order.order_number', label: 'أمر الشراء', type: 'mono' },
    { key: 'warehouse.name_ar', label: 'المستودع' },
    { key: 'receipt_date', label: 'التاريخ', type: 'date' },
    { key: 'total_value', label: 'القيمة', type: 'money' },
    { key: 'status', label: 'الحالة', type: 'status' },
];
const statuses = [{ value: 'received', label: 'غير مفوتر' }, { value: 'invoiced', label: 'مفوتر' }];
</script>

<template>
    <AppLayout>
        <Head title="استلام البضاعة" />
        <PageHeader title="استلام البضاعة (GRN)" subtitle="البضاعة المستلمة غير المفوترة تظهر في حساب «بضاعة مستلمة لم تصل فواتيرها»" :crumbs="[{ label: 'المشتريات' }, { label: 'الاستلامات' }]" />
        <DocumentTable :paginator="receipts" :columns="columns" :filters="filters" :statuses="statuses" search-route="purchases.receipts.index" :link="(r) => route('purchases.receipts.show', r.id)" />
    </AppLayout>
</template>
