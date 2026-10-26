<script setup>
defineProps({
    title: { type: String, default: '' },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <div v-if="title || $slots.header" class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
            <h3 class="font-bold text-sm text-slate-800">{{ title }}</h3>
            <slot name="header" />
        </div>
        <div :class="padded ? 'p-4 overflow-x-auto' : 'overflow-x-auto'">
            <slot />
        </div>
    </div>
</template>
