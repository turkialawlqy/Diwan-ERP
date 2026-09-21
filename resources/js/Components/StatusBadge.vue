<script setup>
const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: null,
    }
});

const getBadgeStyle = (status) => {
    switch (status) {
        case 'paid':
        case 'active':
        case 'approved':
        case 'completed':
            return 'bg-emerald-50 text-emerald-700 border-emerald-200/80 ring-emerald-600/20';
        case 'partially_paid':
        case 'pending':
        case 'trial':
            return 'bg-blue-50 text-blue-700 border-blue-200/80 ring-blue-600/20';
        case 'unpaid':
        case 'draft':
            return 'bg-amber-50 text-amber-700 border-amber-200/80 ring-amber-600/20';
        case 'overdue':
        case 'suspended':
        case 'cancelled':
        case 'rejected':
            return 'bg-red-50 text-red-700 border-red-200/80 ring-red-600/20';
        default:
            return 'bg-slate-50 text-slate-700 border-slate-200/80 ring-slate-600/20';
    }
};

const getStatusLabel = (status) => {
    if (props.label) return props.label;

    switch (status) {
        case 'paid': return 'مدفوعة';
        case 'partially_paid': return 'مدفوعة جزئياً';
        case 'unpaid': return 'غير مدفوعة';
        case 'overdue': return 'متأخرة';
        case 'draft': return 'مسودة';
        case 'active': return 'نشط';
        case 'trial': return 'فترة تجريبية';
        case 'suspended': return 'معلق';
        case 'completed': return 'مكتمل';
        case 'cancelled': return 'ملغاة';
        default: return status;
    }
};
</script>

<template>
    <span 
        :class="[
            getBadgeStyle(status),
            'inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border shadow-xs'
        ]"
    >
        <span class="w-1.5 h-1.5 rounded-full me-1.5 bg-current opacity-70"></span>
        <span>{{ getStatusLabel(status) }}</span>
    </span>
</template>
