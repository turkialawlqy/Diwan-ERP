<script setup>
import { onMounted, ref, useId } from 'vue';

defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
});

/*
 * The wrapper is a <div>, not a <label>: a label around composite widgets
 * (search pickers, buttons, dropdown results) swallows their clicks. The
 * caption stays a real <label> tied to the first form control by id, so
 * clicking it still focuses the field and screen readers announce it.
 */
const root = ref(null);
const fallbackId = `field-${useId()}`;
const controlId = ref(fallbackId);

onMounted(() => {
    const control = root.value?.querySelector('input:not([type=hidden]), select, textarea');
    if (!control) return;
    if (control.id) controlId.value = control.id;
    else control.id = fallbackId;
});
</script>

<template>
    <div ref="root" class="block">
        <label v-if="label" :for="controlId" class="block text-xs font-bold text-slate-700 mb-1">{{ label }}</label>
        <slot />
        <span v-if="hint && !error" class="block text-[11px] text-slate-400 mt-1">{{ hint }}</span>
        <span v-if="error" class="block text-[11px] text-rose-600 mt-1">{{ error }}</span>
    </div>
</template>
