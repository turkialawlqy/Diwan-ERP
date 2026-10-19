<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Product name and tagline come from config (APP_NAME), shared as page.props.brand.
const brand = computed(() => usePage().props.brand || {});

defineProps({
    size: {
        type: String,
        default: 'md', // sm, md, lg
    },
    showSubtitle: {
        type: Boolean,
        default: true,
    },
    white: {
        type: Boolean,
        default: false,
    }
});
</script>

<template>
    <div class="flex items-center gap-3 select-none">
        <!-- Logo Icon: Geometric Indigo Badge with Modern Ledger & Star Symbol -->
        <div 
            :class="[
                size === 'sm' ? 'w-8 h-8 rounded-lg' : size === 'lg' ? 'w-14 h-14 rounded-2xl' : 'w-10 h-10 rounded-xl',
                'bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 shadow-md flex items-center justify-center text-white flex-shrink-0 relative overflow-hidden group'
            ]"
        >
            <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <!-- Letter 'أ' or Quill/Ledger Icon in SVG -->
            <svg class="w-3/5 h-3/5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                <path d="M2 17l10 5 10-5"></path>
                <path d="M2 12l10 5 10-5"></path>
            </svg>
        </div>

        <div class="flex flex-col leading-tight">
            <span 
                :class="[
                    size === 'sm' ? 'text-base font-bold' : size === 'lg' ? 'text-2xl font-extrabold' : 'text-xl font-bold',
                    white ? 'text-white' : 'text-slate-900',
                    'tracking-tight font-sans'
                ]"
            >
                {{ brand.name || 'الديوان' }}
            </span>
            <span 
                v-if="showSubtitle" 
                :class="[
                    size === 'sm' ? 'text-[10px]' : size === 'lg' ? 'text-xs' : 'text-[11px]',
                    white ? 'text-indigo-200' : 'text-indigo-600',
                    'font-medium tracking-wide'
                ]"
            >
                {{ brand.subtitle || 'نظام إدارة الأعمال المتكامل' }}
            </span>
        </div>
    </div>
</template>
