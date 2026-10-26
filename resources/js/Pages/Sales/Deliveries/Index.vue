<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';

defineProps({ notes: Object, filters: Object });
const columns = [
    { key: 'delivery_number', label: 'الرقم', type: 'mono' },
    { key: 'customer.name', label: 'العميل' },
    { key: 'sales_order.order_number', label: 'أمر البيع', type: 'mono' },
    { key: 'warehouse.name_ar', label: 'المستودع' },
    { key: 'delivery_date', label: 'التاريخ', type: 'date' },
    { key: 'status', label: 'الحالة', type: 'status' },
];
const statuses = [{ value: 'delivered', label: 'مسلّم غير مفوتر' }, { value: 'invoiced', label: 'مفوتر' }];
</script>

<template>
    <AppLayout>
        <Head title="أذونات التسليم" />
        <PageHeader title="أذونات التسليم" subtitle="البضاعة المسلمة تُصرف من المخزون وتُحمّل تكلفتها عند التسليم" :crumbs="[{ label: 'المبيعات' }, { label: 'أذونات التسليم' }]" />
        <DocumentTable :paginator="notes" :columns="columns" :filters="filters" :statuses="statuses" search-route="sales.deliveries.index" :link="(r) => route('sales.deliveries.show', r.id)" />
    </AppLayout>
</template>
