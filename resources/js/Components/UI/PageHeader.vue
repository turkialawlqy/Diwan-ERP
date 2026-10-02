<script setup>
import { Link } from '@inertiajs/vue3';
import { HomeIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    // [{ label, href? }]
    crumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
        <div>
            <div class="flex items-center text-xs text-slate-500 mb-1">
                <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600"><HomeIcon class="w-4 h-4" /></Link>
                <template v-for="(crumb, i) in crumbs" :key="i">
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                    <Link v-if="crumb.href" :href="crumb.href" class="hover:text-indigo-600">{{ crumb.label }}</Link>
                    <span v-else class="text-slate-600">{{ crumb.label }}</span>
                </template>
            </div>
            <h1 class="text-lg font-black text-slate-800">{{ title }}</h1>
            <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5">{{ subtitle }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <slot name="actions" />
        </div>
    </div>
</template>
