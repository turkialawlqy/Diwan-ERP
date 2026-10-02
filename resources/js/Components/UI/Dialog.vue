<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline';

/**
 * Simple modal dialog with a title, body slot and footer slot.
 */
defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: 'md' }, // sm | md | lg | xl
});

defineEmits(['close']);

const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl', xl: 'max-w-6xl' };
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="$emit('close')">
            <div :class="[widths[size] || widths.md, 'bg-white rounded-2xl shadow-xl w-full my-8']">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800">{{ title }}</h3>
                    <button type="button" @click="$emit('close')" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-5 h-5" /></button>
                </div>
                <div class="p-5">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="flex justify-end gap-2 px-5 py-4 border-t border-slate-100">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
