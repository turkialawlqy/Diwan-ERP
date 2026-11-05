<script setup>
import { computed } from 'vue';

/** «Excel» and «PDF» download buttons of a report, carrying the page's current filters. */
const props = defineProps({ report: { type: String, required: true }, params: { type: Object, default: () => ({}) }, permission: { type: String, default: null } });

const clean = computed(() => Object.fromEntries(Object.entries(props.params || {}).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false)));
const href = (format) => route('reports.export', { report: props.report, format, ...clean.value });
const cls = 'inline-flex items-center gap-1 px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-indigo-600 print:hidden';
</script>

<template>
    <span v-if="!permission || $can(permission)" class="inline-flex items-center gap-1.5" data-export-buttons>
        <a :href="href('xlsx')" :class="cls" data-export-xlsx>Excel</a>
        <a :href="href('pdf')" :class="cls" data-export-pdf>PDF</a>
    </span>
</template>
