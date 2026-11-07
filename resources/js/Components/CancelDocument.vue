<script setup>
import { useI18n } from 'vue-i18n';
import { ask } from '@/composables/useDialog';
import { router } from '@inertiajs/vue3';

// Cancels a posted document (reversal entry) after asking for the reason.
const props = defineProps({
    url: { type: String, required: true },
    label: { type: String, default: null },
    status: { type: String, default: null },
});

const { t } = useI18n();
const cancel = async () => {
    const reason = await ask(t('shared.cancel_doc_prompt'), { title: t('shared.cancel_doc_title', { label: props.label || t('shared.document') }), type: 'textarea', required: true, minLength: 3, confirmText: t('shared.cancel_doc_btn'), cancelText: t('shared.go_back'), danger: true });
    if (!reason) return;
    router.post(props.url, { reason }, { preserveScroll: true });
};
</script>

<template>
    <span v-if="status === 'cancelled'" class="inline-flex px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-3xs font-bold">{{ $t('shared.cancelled_badge') }}</span>
    <button v-else type="button" @click="cancel" class="text-3xs font-bold text-rose-600 hover:text-rose-800">{{ $t('shared.cancel_short') }}</button>
</template>
