<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CustomerModal from './CustomerModal.vue';

const props = defineProps({
    nextCode: {
        type: String,
        default: '1-000001',
    },
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
        <Head title="إضافة عميل جديد" />

        <div class="max-w-5xl mx-auto py-4">
            <CustomerModal
                :show="true"
                :nextCode="nextCode"
                @close="handleClose"
                @saved="handleSaved"
            />
        </div>
    </AppLayout>
</template>
