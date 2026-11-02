<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, subscriptions: Object, statuses: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
</script>

<template>
    <Head title="الاشتراكات" />
    <PortalLayout :portal="portal" active="subscriptions">
        <h1 class="text-lg font-black text-slate-900 mb-3">الاشتراكات</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">الخطة</th><th class="px-4 py-2 text-start">السعر</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 text-start">التجديد</th></tr></thead>
                <tbody>
                    <tr v-for="s in subscriptions.data" :key="s.id" class="border-t border-slate-100">
                        <td class="px-4 py-2"><Link :href="r('customer-portal.subscriptions.show', { subscription: s.id })" class="font-mono font-bold text-indigo-600">{{ s.number }}</Link></td>
                        <td class="px-4 py-2">{{ s.plan?.name }}</td><td class="px-4 py-2 font-mono">{{ money(s.unit_price) }}</td>
                        <td class="px-4 py-2">{{ statuses[s.status] || s.status }}</td><td class="px-4 py-2 font-mono">{{ s.recurring?.next_run_date?.slice(0, 10) || '—' }}</td>
                    </tr>
                    <tr v-if="!subscriptions.data.length"><td colspan="5" class="py-8 text-center text-slate-400">لا اشتراكات.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="subscriptions" />
    </PortalLayout>
</template>
