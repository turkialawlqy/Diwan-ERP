<script setup>
import { computed } from 'vue';

const props = defineProps({ status: String });

const map = {
    draft: ['مسودة', 'bg-slate-100 text-slate-600'],
    pending: ['قيد الانتظار', 'bg-amber-50 text-amber-700'],
    pending_approval: ['بانتظار الاعتماد', 'bg-amber-50 text-amber-700'],
    approved: ['معتمد', 'bg-emerald-50 text-emerald-700'],
    confirmed: ['مؤكد', 'bg-emerald-50 text-emerald-700'],
    rejected: ['مرفوض', 'bg-rose-50 text-rose-700'],
    cancelled: ['ملغى', 'bg-slate-100 text-slate-400'],
    partially_delivered: ['مسلّم جزئياً', 'bg-sky-50 text-sky-700'],
    delivered: ['مسلّم', 'bg-sky-50 text-sky-700'],
    partially_received: ['مستلم جزئياً', 'bg-sky-50 text-sky-700'],
    received: ['مستلم', 'bg-sky-50 text-sky-700'],
    invoiced: ['مفوتر', 'bg-indigo-50 text-indigo-700'],
    converted_to_invoice: ['مفوتر', 'bg-indigo-50 text-indigo-700'],
    ordered: ['تم الطلب', 'bg-indigo-50 text-indigo-700'],
    refunded: ['مسترد', 'bg-indigo-50 text-indigo-700'],
    paid: ['مدفوع', 'bg-emerald-50 text-emerald-700'],
    partially_paid: ['مدفوع جزئياً', 'bg-amber-50 text-amber-700'],
    unpaid: ['غير مدفوع', 'bg-rose-50 text-rose-700'],
};
const entry = computed(() => map[props.status] || [props.status, 'bg-slate-100 text-slate-600']);
</script>

<template>
    <span :class="entry[1]" class="inline-block px-2 py-0.5 rounded-lg text-[11px] font-bold">{{ entry[0] }}</span>
</template>
