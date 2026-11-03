<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, invoices: Object, status: String });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
const filter = (e) => router.get(r('customer-portal.invoices.index'), { status: e.target.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="الفواتير" />
    <PortalLayout :portal="portal" active="invoices">
        <div class="flex items-center justify-between mb-3"><h1 class="text-lg font-black text-slate-900">الفواتير</h1>
            <select :value="status" @change="filter" class="rounded-xl border-slate-200 text-xs"><option value="all">الكل</option><option value="open">المستحقة</option><option value="paid">المسددة</option></select></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الفاتورة</th><th class="px-4 py-2 text-start">التاريخ</th><th class="px-4 py-2 text-start">الاستحقاق</th><th class="px-4 py-2 text-start">الإجمالي</th><th class="px-4 py-2 text-start">المتبقي</th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices.data" :key="i.id" class="border-t border-slate-100" :data-invoice="i.invoice_number">
                        <td class="px-4 py-2"><Link :href="r('customer-portal.invoices.show', { invoice: i.id })" class="font-mono font-bold text-indigo-600">{{ i.invoice_number }}</Link></td>
                        <td class="px-4 py-2 font-mono">{{ i.invoice_date?.slice(0, 10) }}</td><td class="px-4 py-2 font-mono">{{ i.due_date?.slice(0, 10) || '—' }}</td>
                        <td class="px-4 py-2 font-mono">{{ money(i.total) }}</td>
                        <td class="px-4 py-2 font-mono" :class="Number(i.remaining_amount) > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">{{ money(i.remaining_amount) }}</td>
                    </tr>
                    <tr v-if="!invoices.data.length"><td colspan="5" class="py-8 text-center text-slate-400">لا فواتير.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="invoices" />
    </PortalLayout>
</template>
