<script setup>
import { ask } from '@/composables/useDialog';
import { router } from '@inertiajs/vue3';

// Cancels a posted document (reversal entry) after asking for the reason.
const props = defineProps({
    url: { type: String, required: true },
    label: { type: String, default: 'المستند' },
    status: { type: String, default: null },
});

const cancel = async () => {
    const reason = await ask('سيُعكس القيد ويُعاد أثر المستند. اكتب سبب الإلغاء:', { title: `إلغاء ${props.label}`, type: 'textarea', required: true, minLength: 3, confirmText: 'إلغاء المستند', cancelText: 'تراجع', danger: true });
    if (!reason) return;
    router.post(props.url, { reason }, { preserveScroll: true });
};
</script>

<template>
    <span v-if="status === 'cancelled'" class="inline-flex px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-3xs font-bold">ملغى</span>
    <button v-else type="button" @click="cancel" class="text-3xs font-bold text-rose-600 hover:text-rose-800">إلغاء</button>
</template>
