<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineProps({ vehicle: Object, orders: Array, statuses: Object });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
</script>

<template>
    <AppLayout>
        <Head :title="vehicle.plate" />
        <PageHeader :title="vehicle.label" :crumbs="[{ label: 'الورشة' }, { label: 'المركبات', href: route('workshop.vehicles.index') }, { label: vehicle.plate }]">
            <template #actions><Link v-if="$can('work_orders.orders.create')" :href="route('work-orders.create', { customer: vehicle.customer_id, vehicle: vehicle.id })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-order>أمر إصلاح جديد</Link>
                <Link v-if="$can('workshop.vehicles.edit')" :href="route('workshop.vehicles.edit', vehicle.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">تعديل</Link></template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">العميل</p><p class="font-bold">{{ vehicle.customer?.name }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">العداد</p><p class="font-mono font-bold" data-odometer>{{ vehicle.odometer }} كم</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">الصيانة القادمة</p><p class="font-mono font-bold" data-next-date>{{ d(vehicle.next_service_date) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">عند العداد</p><p class="font-mono font-bold">{{ vehicle.next_service_km || '—' }}</p></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><h2 class="px-5 py-3 text-xs font-bold border-b border-slate-100">سجل الإصلاحات</h2><table class="w-full text-xs"><tbody>
            <tr v-for="o in orders" :key="o.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('work-orders.show', o.id)" class="font-mono font-bold text-indigo-600">{{ o.number }}</Link></td><td class="px-4 py-2">{{ o.title }}</td><td class="px-4 py-2 font-mono">{{ o.odometer_in || '—' }}</td><td class="px-4 py-2">{{ statuses[o.status] }}</td><td class="px-4 py-2 font-mono">{{ d(o.completed_at || o.created_at) }}</td></tr>
            <tr v-if="!orders.length"><td class="py-8 text-center text-slate-400">لا إصلاحات بعد.</td></tr></tbody></table></div>
    </AppLayout>
</template>
