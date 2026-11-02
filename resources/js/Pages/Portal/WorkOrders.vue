<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';

const props = defineProps({ portal: Object, orders: Object, statuses: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
</script>

<template>
    <Head title="زيارات الصيانة" />
    <PortalLayout :portal="portal" active="work_orders">
        <h1 class="text-lg font-black text-slate-900 mb-3">زيارات الصيانة وأوامر العمل</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">العمل</th><th class="px-4 py-2 text-start">الموعد</th><th class="px-4 py-2 text-start">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100" :data-work-order="o.number">
                        <td class="px-4 py-2"><Link :href="r('customer-portal.work-orders.show', { order: o.id })" class="font-mono font-bold text-indigo-600">{{ o.number }}</Link></td>
                        <td class="px-4 py-2 font-bold">{{ o.title }}</td><td class="px-4 py-2 font-mono">{{ when(o.scheduled_start) }}</td><td class="px-4 py-2">{{ statuses[o.status] }}</td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="4" class="py-8 text-center text-slate-400">لا زيارات.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="orders" />
    </PortalLayout>
</template>
