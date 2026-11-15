<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
const { t } = useI18n();

defineProps({ receipts: Object, filters: Object });
const columns = computed(() => ([
    { key: 'receipt_number', label: t('z_purchases_receipts_index.number'), type: 'mono' },
    { key: 'supplier.name', label: t('z_purchases_receipts_index.supplier') },
    { key: 'purchase_order.order_number', label: t('z_purchases_receipts_index.purchase_order'), type: 'mono' },
    { key: 'warehouse.name_ar', label: t('z_purchases_receipts_index.warehouse') },
    { key: 'receipt_date', label: t('z_purchases_receipts_index.date'), type: 'date' },
    { key: 'total_value', label: t('z_purchases_receipts_index.value'), type: 'money' },
    { key: 'status', label: t('z_purchases_receipts_index.status'), type: 'status' },
]));
const statuses = computed(() => ([{ value: 'received', label: t('z_purchases_receipts_index.not_billed') }, { value: 'invoiced', label: t('z_purchases_receipts_index.billed') }]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('p_receipts_index.goods_receipt')" />
        <PageHeader year-scoped :title="$t('p_receipts_index.goods_receipt_grn')" :subtitle="$t('p_receipts_index.received_uninvoiced_goods_appear')" :crumbs="[{ label: $t('z_purchases_receipts_index.purchases') }, { label: $t('z_purchases_receipts_index.receipts') }]" />
        <DocumentTable :paginator="receipts" :columns="columns" :filters="filters" :statuses="statuses" search-route="purchases.receipts.index" :link="(r) => route('purchases.receipts.show', r.id)" />
    </AppLayout>
</template>
