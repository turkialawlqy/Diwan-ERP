<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import CustomerViewModal from './CustomerViewModal.vue';

const props = defineProps({
    client: Object,
});

const handleClose = () => router.visit(route('sales.clients.index'));
const handleEdit = () => router.visit(route('sales.clients.edit', props.client.id));
</script>

<template>
    <AppLayout>
        <Head :title="`عرض عميل - ${client.name}`" />
        <PageHeader :title="client.name" :crumbs="[{ label: 'المبيعات' }, { label: 'العملاء', href: route('sales.clients.index') }, { label: 'عرض' }]">
            <template #actions>
                <Link v-if="$canRoute('portal.users.index')" :href="route('portal.users.index', { customer: client.id })" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 text-xs font-bold" data-portal-link>بوابة العميل</Link>
            </template>
        </PageHeader>

        <CustomerViewModal :show="true" page :customer="client" @close="handleClose" @edit="handleEdit" />
    </AppLayout>
</template>
