<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ returns: Object, filters: Object });
const columns = [
    { key: 'return_number', label: 'الرقم', type: 'mono' },
    { key: 'supplier.name', label: 'المورد' },
    { key: 'purchase_invoice.invoice_number', label: 'الفاتورة', type: 'mono' },
    { key: 'return_date', label: 'التاريخ', type: 'date' },
    { key: 'reason', label: 'السبب' },
    { key: 'total', label: 'الإجمالي', type: 'money' },
];
</script>

<template>
    <AppLayout>
        <Head title="مرتجعات المشتريات" />
        <PageHeader title="مرتجعات المشتريات" :crumbs="[{ label: 'المشتريات' }, { label: 'المرتجعات' }]">
            <template #actions><Link v-if="$can('purchases.returns.create')" :href="route('purchases.returns.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> مرتجع مشتريات</Link></template>
        </PageHeader>
        <DocumentTable :paginator="returns" :columns="columns" :filters="filters" search-route="purchases.returns.index" :link="(r) => route('purchases.returns.show', r.id)" />
    </AppLayout>
</template>
