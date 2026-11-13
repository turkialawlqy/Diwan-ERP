<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
const { t } = useI18n();

const props = defineProps({ status: String });

const map = {
    draft: ['st_draft', 'bg-slate-100 text-slate-600'],
    released: ['st_released', 'bg-sky-50 text-sky-700'],
    in_progress: ['st_in_progress', 'bg-amber-50 text-amber-700'],
    completed: ['st_completed', 'bg-emerald-50 text-emerald-700'],
    closed: ['st_closed', 'bg-slate-200 text-slate-700'],
    cancelled: ['st_cancelled', 'bg-rose-50 text-rose-600'],
    sent: ['st_materials_sent', 'bg-sky-50 text-sky-700'],
    partially_received: ['st_partially_received', 'bg-amber-50 text-amber-700'],
    pending: ['st_awaiting_inspection', 'bg-amber-50 text-amber-700'],
    pass: ['st_pass', 'bg-emerald-50 text-emerald-700'],
    fail: ['st_fail', 'bg-rose-50 text-rose-600'],
    partial: ['st_partial_pass', 'bg-amber-50 text-amber-700'],
    open: ['st_open', 'bg-sky-50 text-sky-700'],
    closed_period: ['st_closed_2', 'bg-slate-200 text-slate-700'],
    posted: ['st_posted', 'bg-emerald-50 text-emerald-700'],
    pending_qc: ['st_awaiting_quality', 'bg-amber-50 text-amber-700'],
    rejected: ['st_rejected', 'bg-rose-50 text-rose-600'],
    new: ['st_new', 'bg-sky-50 text-sky-700'],
    converted: ['st_converted', 'bg-emerald-50 text-emerald-700'],
    dismissed: ['st_dismissed', 'bg-slate-100 text-slate-500'],
    done: ['st_done', 'bg-emerald-50 text-emerald-700'],
};
const item = computed(() => { const m = map[props.status]; return m ? [t(m[0]), m[1]] : [props.status, 'bg-slate-100 text-slate-600']; });
</script>

<template>
    <span :class="item[1]" class="inline-block px-2.5 py-0.5 rounded-full text-3xs font-bold whitespace-nowrap">{{ item[0] }}</span>
</template>
