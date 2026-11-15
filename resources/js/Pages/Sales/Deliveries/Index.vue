<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
const { t } = useI18n();

defineProps({ notes: Object, filters: Object });
const columns = computed(() => ([
    { key: 'delivery_number', label: t('z_sales_deliveries_index.number'), type: 'mono' },
    { key: 'customer.name', label: t('z_sales_deliveries_index.customer') },
    { key: 'sales_order.order_number', label: t('z_sales_deliveries_index.sales_order'), type: 'mono' },
    { key: 'warehouse.name_ar', label: t('z_sales_deliveries_index.warehouse') },
    { key: 'delivery_date', label: t('z_sales_deliveries_index.date'), type: 'date' },
    { key: 'status', label: t('z_sales_deliveries_index.status'), type: 'status' },
]));
const statuses = computed(() => ([{ value: 'delivered', label: t('z_sales_deliveries_index.delivered_not_billed') }, { value: 'invoiced', label: t('z_sales_deliveries_index.billed') }]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_deliveries.delivery_notes')" />
        <PageHeader year-scoped :title="$t('s_deliveries.delivery_notes')" :subtitle="$t('s_deliveries.delivered_goods_are_issued_from')" :crumbs="[{ label: $t('z_sales_deliveries_index.sales') }, { label: $t('z_sales_deliveries_index.delivery_notes') }]" />
        <DocumentTable :paginator="notes" :columns="columns" :filters="filters" :statuses="statuses" search-route="sales.deliveries.index" :link="(r) => route('sales.deliveries.show', r.id)" />
    </AppLayout>
</template>
