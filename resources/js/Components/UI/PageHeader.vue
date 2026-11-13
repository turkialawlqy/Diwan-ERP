<script setup>
import { computed } from 'vue';
import { tx } from '@/i18n';
import { Link, usePage } from '@inertiajs/vue3';
import { HomeIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    // [{ label, href? }]
    crumbs: { type: Array, default: () => [] },
    // The list below shows only the fiscal year chosen in the «سياق العمل» card: say so.
    yearScoped: { type: Boolean, default: false },
});

const page = usePage();
const activeYear = computed(() => {
    const s = page.props.fiscalSwitcher;
    return s?.items?.find((y) => y.id === s.active) || null;
});
</script>

<template>
    <div data-page-header class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 print:hidden">
        <div>
            <div class="flex items-center text-xs text-slate-500 mb-1">
                <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600"><HomeIcon class="w-4 h-4" /></Link>
                <template v-for="(crumb, i) in crumbs" :key="i">
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                    <Link v-if="crumb.href" :href="crumb.href" class="hover:text-indigo-600">{{ tx(crumb.label) }}</Link>
                    <span v-else class="text-slate-600">{{ tx(crumb.label) }}</span>
                </template>
            </div>
            <h1 class="text-lg font-black text-slate-800">{{ title }}</h1>
            <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5">{{ subtitle }}</p>
            <p v-if="yearScoped && activeYear" class="inline-flex items-center gap-1.5 mt-1 px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-[11px] font-bold" data-year-chip>
                السنة المالية {{ activeYear.name }} فقط · غيّرها من «سياق العمل» في القائمة الجانبية أو بفلتر التاريخ
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <slot name="actions" />
        </div>
    </div>
</template>
