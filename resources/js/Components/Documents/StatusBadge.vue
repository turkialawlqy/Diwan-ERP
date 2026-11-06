<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ status: String });

const map = {
    draft: 'bg-slate-100 text-slate-600',
    pending: 'bg-amber-50 text-amber-700',
    pending_approval: 'bg-amber-50 text-amber-700',
    approved: 'bg-emerald-50 text-emerald-700',
    confirmed: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-400',
    partially_delivered: 'bg-sky-50 text-sky-700',
    delivered: 'bg-sky-50 text-sky-700',
    partially_received: 'bg-sky-50 text-sky-700',
    received: 'bg-sky-50 text-sky-700',
    invoiced: 'bg-indigo-50 text-indigo-700',
    partially_invoiced: 'bg-indigo-50 text-indigo-700',
    converted_to_invoice: 'bg-indigo-50 text-indigo-700',
    ordered: 'bg-indigo-50 text-indigo-700',
    refunded: 'bg-indigo-50 text-indigo-700',
    paid: 'bg-emerald-50 text-emerald-700',
    partially_paid: 'bg-amber-50 text-amber-700',
    unpaid: 'bg-rose-50 text-rose-700',
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-slate-100 text-slate-500',
    on_leave: 'bg-sky-50 text-sky-700',
    suspended: 'bg-amber-50 text-amber-700',
    terminated: 'bg-slate-100 text-slate-500',
    open: 'bg-emerald-50 text-emerald-700',
    closed: 'bg-slate-100 text-slate-500',
    completed: 'bg-emerald-50 text-emerald-700',
    posted: 'bg-emerald-50 text-emerald-700',
};
const { t, te } = useI18n();
const entry = computed(() => [te('status.' + props.status) ? t('status.' + props.status) : props.status, map[props.status] || 'bg-slate-100 text-slate-600']);
</script>

<template>
    <span :class="entry[1]" class="inline-block px-2 py-0.5 rounded-lg text-[11px] font-bold">{{ entry[0] }}</span>
</template>
