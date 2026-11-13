<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ orders: Object, filters: Object });
const columns = [
    { key: 'order_number', label: 'الرقم', type: 'mono' },
    { key: 'customer.name', label: 'العميل' },
    { key: 'order_date', label: 'التاريخ', type: 'date' },
    { key: 'delivery_date', label: 'تاريخ التسليم', type: 'date' },
    { key: 'total', label: 'الإجمالي', type: 'money' },
    { key: 'status', label: 'الحالة', type: 'status' },
];
const statuses = [
    { value: 'pending_approval', label: 'بانتظار الاعتماد' }, { value: 'confirmed', label: 'مؤكد' },
    { value: 'partially_delivered', label: 'مسلّم جزئياً' }, { value: 'delivered', label: 'مسلّم' },
    { value: 'invoiced', label: 'مفوتر' }, { value: 'cancelled', label: 'ملغى' },
];
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_orders.sales_orders')" />
        <PageHeader year-scoped :title="$t('s_orders.sales_orders')" :subtitle="$t('s_orders.sales_order_delivery_note_invoic')" :crumbs="[{ label: 'المبيعات' }, { label: 'أوامر البيع' }]">
            <template #actions>
                <Link v-if="$can('sales.orders.create')" :href="route('sales.orders.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_orders.sales_order') }}</Link>
            </template>
        </PageHeader>
        <DocumentTable :paginator="orders" :columns="columns" :filters="filters" :statuses="statuses" search-route="sales.orders.index" :link="(r) => route('sales.orders.show', r.id)" :delete-link="$can('sales.orders.cancel') ? (r) => (['draft', 'pending_approval', 'confirmed', 'rejected', 'cancelled'].includes(r.status) ? route('sales.orders.destroy', r.id) : null) : null" :delete-confirm="(r) => `نقل أمر البيع ${r.order_number} إلى سلة المحذوفات؟`" />
    </AppLayout>
</template>
