<script setup>
import { onMounted } from 'vue';
import ShowInvoice from './Show.vue';

const props = defineProps({
    invoice: Object,
    company: Object,
    template: Object,
    templates: Array,
});

onMounted(() => {
    // Wait for all images (logo, QR code) to complete loading before launching print dialog
    const waitForImagesAndPrint = () => {
        const images = Array.from(document.querySelectorAll('img'));
        const pendingImages = images.filter(img => !img.complete);

        if (pendingImages.length === 0) {
            setTimeout(() => window.print(), 350);
            return;
        }

        let loadedCount = 0;
        let isPrinted = false;

        const onDone = () => {
            loadedCount++;
            if (!isPrinted && loadedCount >= pendingImages.length) {
                isPrinted = true;
                setTimeout(() => window.print(), 250);
            }
        };

        pendingImages.forEach(img => {
            img.addEventListener('load', onDone, { once: true });
            img.addEventListener('error', onDone, { once: true });
        });

        // Safety fallback timeout
        setTimeout(() => {
            if (!isPrinted) {
                isPrinted = true;
                window.print();
            }
        }, 1500);
    };

    setTimeout(waitForImagesAndPrint, 200);
});
</script>

<template>
    <ShowInvoice :invoice="invoice" :company="company" :template="template" :templates="templates" />
</template>
