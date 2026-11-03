<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({ portal: Object, order: Object, statuses: Object });
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
</script>

<template>
    <Head :title="`زيارة ${order.number}`" />
    <PortalLayout :portal="portal" active="work_orders">
        <h1 class="text-lg font-black text-slate-900 mb-3">{{ order.title }} <span class="font-mono text-sm text-slate-400">{{ order.number }}</span></h1>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3 text-xs mb-3">
            <div><p class="text-slate-500">الحالة</p><p class="font-bold" data-status>{{ statuses[order.status] }}</p></div>
            <div><p class="text-slate-500">الفني</p><p class="font-bold">{{ order.technician || '—' }}</p></div>
            <div><p class="text-slate-500">الموعد</p><p class="font-mono">{{ when(order.scheduled_start) }}</p></div>
            <div><p class="text-slate-500">اكتمل في</p><p class="font-mono">{{ when(order.completed_at) }}</p></div>
            <div v-if="order.site_address" class="col-span-2"><p class="text-slate-500">الموقع</p><p>{{ order.site_address }}</p></div>
            <div v-if="order.completion_notes" class="col-span-2"><p class="text-slate-500">ملاحظات الفني</p><p class="whitespace-pre-line">{{ order.completion_notes }}</p></div>
            <div v-if="order.signed_by" class="col-span-2 text-emerald-700 font-bold">وقّع {{ order.signed_by }} على الإنجاز ({{ when(order.signed_at) }})</div>
        </div>
        <div v-if="order.tasks.length" class="bg-white border border-slate-200 rounded-2xl p-5 text-xs mb-3"><p v-for="(t, i) in order.tasks" :key="i" :class="t.done ? 'text-emerald-700' : ''">{{ t.done ? '✓' : '○' }} {{ t.title }}</p></div>
        <p v-if="order.invoice" class="text-xs">الفاتورة: <Link :href="route('customer-portal.invoices.show', { company: portal.company.id, invoice: order.invoice.id })" class="font-mono font-bold text-indigo-600">{{ order.invoice.number }}</Link></p>
        <Link :href="route('customer-portal.work-orders.index', { company: portal.company.id })" class="inline-block mt-3 text-xs text-indigo-600 font-bold">← كل الزيارات</Link>
    </PortalLayout>
</template>
