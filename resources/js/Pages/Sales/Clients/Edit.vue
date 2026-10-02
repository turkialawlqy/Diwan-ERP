<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CustomerModal from './CustomerModal.vue';

const props = defineProps({
    client: Object,
});

const handleClose = () => {
    router.visit(route('sales.clients.index'));
};

const handleSaved = () => {
    router.visit(route('sales.clients.index'));
};
</script>

<template>
    <AppLayout>
        <Head :title="`تعديل بيانات العميل - ${client.name}`" />

        <div class="max-w-5xl mx-auto py-4">
            <CustomerModal
                :show="true"
                :customer="client"
                @close="handleClose"
                @saved="handleSaved"
            />
        </div>
    </AppLayout>
</template>
