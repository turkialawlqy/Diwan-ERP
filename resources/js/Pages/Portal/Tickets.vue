<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';

const props = defineProps({ portal: Object, tickets: Object, statuses: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
</script>

<template>
    <Head title="التذاكر" />
    <PortalLayout :portal="portal" active="tickets">
        <div class="flex items-center justify-between mb-3"><h1 class="text-lg font-black text-slate-900">تذاكر الدعم</h1>
            <Link :href="r('customer-portal.tickets.create')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-ticket>تذكرة جديدة</Link></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">الموضوع</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 text-start">التاريخ</th></tr></thead>
                <tbody>
                    <tr v-for="t in tickets.data" :key="t.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono font-bold">{{ t.number }}</td>
                        <td class="px-4 py-2"><Link :href="r('customer-portal.tickets.show', { ticket: t.id })" class="font-bold text-indigo-600">{{ t.subject }}</Link></td>
                        <td class="px-4 py-2">{{ statuses[t.status] || t.status }}</td><td class="px-4 py-2 font-mono">{{ t.created_at?.slice(0, 10) }}</td>
                    </tr>
                    <tr v-if="!tickets.data.length"><td colspan="4" class="py-8 text-center text-slate-400">لا تذاكر.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="tickets" />
    </PortalLayout>
</template>
