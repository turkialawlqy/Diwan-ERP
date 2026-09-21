<script setup>
import { onMounted } from 'vue';
import ShowInvoice from './Show.vue';

const props = defineProps({
    invoice: Object,
    company: Object,
});

onMounted(() => {
    setTimeout(() => {
        window.print();
    }, 500);
});
</script>

<template>
    <ShowInvoice :invoice="invoice" :company="company" />
</template>
