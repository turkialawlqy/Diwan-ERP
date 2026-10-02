<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CustomerViewModal from './CustomerViewModal.vue';
import CustomerModal from './CustomerModal.vue';

const props = defineProps({
    client: Object,
});

const isEditModalOpen = ref(false);

const handleClose = () => {
    router.visit(route('sales.clients.index'));
};

const handleEdit = () => {
    isEditModalOpen.value = true;
};

const handleSaved = () => {
    isEditModalOpen.value = false;
    router.reload({ only: ['client'] });
};
</script>

<template>
    <AppLayout>
        <Head :title="`عرض عميل - ${client.name}`" />

        <div class="max-w-5xl mx-auto py-4">
            <CustomerViewModal
                :show="true"
                :customer="client"
                @close="handleClose"
                @edit="handleEdit"
            />

            <!-- Edit Modal when triggered -->
            <CustomerModal
                :show="isEditModalOpen"
                :customer="client"
                @close="isEditModalOpen = false"
                @saved="handleSaved"
            />
        </div>
    </AppLayout>
</template>
