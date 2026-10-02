<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
});
</script>

<template>
    <label class="block">
        <span v-if="label" class="block text-xs font-bold text-slate-700 mb-1">{{ label }}</span>
        <slot />
        <span v-if="hint && !error" class="block text-[11px] text-slate-400 mt-1">{{ hint }}</span>
        <span v-if="error" class="block text-[11px] text-rose-600 mt-1">{{ error }}</span>
    </label>
</template>
