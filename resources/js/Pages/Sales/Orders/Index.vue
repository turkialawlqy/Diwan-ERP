<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ orders: Object, filters: Object });
const columns = computed(() => ([
    { key: 'order_number', label: t('z_sales_orders_index.number'), type: 'mono' },
    { key: 'customer.name', label: t('z_sales_orders_index.customer') },
    { key: 'order_date', label: t('z_sales_orders_index.date'), type: 'date' },
    { key: 'delivery_date', label: t('z_sales_orders_index.delivery_date'), type: 'date' },
    { key: 'total', label: t('z_sales_orders_index.total'), type: 'money' },
    { key: 'status', label: t('z_sales_orders_index.status'), type: 'status' },
]));
const statuses = computed(() => ([
    { value: 'pending_approval', label: t('z_sales_orders_index.awaiting_approval') }, { value: 'confirmed', label: t('z_sales_orders_index.confirmed') },
    { value: 'partially_delivered', label: t('z_sales_orders_index.partially_delivered') }, { value: 'delivered', label: t('z_sales_orders_index.delivered') },
    { value: 'invoiced', label: t('z_sales_orders_index.billed') }, { value: 'cancelled', label: t('z_sales_orders_index.cancelled') },
]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_orders.sales_orders')" />
        <PageHeader year-scoped :title="$t('s_orders.sales_orders')" :subtitle="$t('s_orders.sales_order_delivery_note_invoic')" :crumbs="[{ label: $t('z_sales_orders_index.sales') }, { label: $t('z_sales_orders_index.sales_orders') }]">
            <template #actions>
                <Link v-if="$can('sales.orders.create')" :href="route('sales.orders.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_orders.sales_order') }}</Link>
            </template>
        </PageHeader>
        <DocumentTable :paginator="orders" :columns="columns" :filters="filters" :statuses="statuses" search-route="sales.orders.index" :link="(r) => route('sales.orders.show', r.id)" :delete-link="$can('sales.orders.cancel') ? (r) => (['draft', 'pending_approval', 'confirmed', 'rejected', 'cancelled'].includes(r.status) ? route('sales.orders.destroy', r.id) : null) : null" :delete-confirm="(r) => $t('z_sales_orders_index.move_sales_order_n_to_the_recycl', { n0: r.order_number })" />
    </AppLayout>
</template>
