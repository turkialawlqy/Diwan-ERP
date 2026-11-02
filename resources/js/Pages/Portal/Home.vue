<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, balance: Number, openInvoices: Object, openTickets: Number, toAccept: Number, recentInvoices: Array });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
</script>

<template>
    <Head title="الرئيسية" />
    <PortalLayout :portal="portal" active="home">
        <h1 class="text-lg font-black text-slate-900 mb-4">أهلًا {{ portal.user.name }}</h1>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5 text-xs" data-portal-home>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">رصيدك الحالي</p><p class="text-lg font-black font-mono" :class="balance > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ money(balance) }}</p></div>
            <Link :href="r('customer-portal.invoices.index', { status: 'open' })" class="bg-white border border-slate-200 rounded-2xl p-4 block"><p class="text-slate-500">فواتير مستحقة</p><p class="text-lg font-black font-mono">{{ openInvoices.count }}</p><p v-if="openInvoices.overdue" class="text-rose-600 font-bold">{{ openInvoices.overdue }} متأخرة</p></Link>
            <Link v-if="openTickets !== null" :href="r('customer-portal.tickets.index')" class="bg-white border border-slate-200 rounded-2xl p-4 block"><p class="text-slate-500">تذاكر مفتوحة</p><p class="text-lg font-black font-mono">{{ openTickets }}</p></Link>
            <Link v-if="toAccept !== null" :href="r('customer-portal.contracts.index')" class="bg-white border border-slate-200 rounded-2xl p-4 block"><p class="text-slate-500">عقود بانتظار قبولك</p><p class="text-lg font-black font-mono" :class="toAccept ? 'text-amber-600' : ''">{{ toAccept }}</p></Link>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">آخر الفواتير</h2>
            <table class="w-full text-xs"><tbody>
                <tr v-for="i in recentInvoices" :key="i.id" class="border-t border-slate-100">
                    <td class="px-4 py-2"><Link :href="r('customer-portal.invoices.show', { invoice: i.id })" class="font-mono font-bold text-indigo-600">{{ i.invoice_number }}</Link></td>
                    <td class="px-4 py-2 font-mono">{{ i.invoice_date?.slice(0, 10) }}</td><td class="px-4 py-2 font-mono">{{ money(i.total) }}</td>
                    <td class="px-4 py-2 font-mono" :class="Number(i.remaining_amount) > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">{{ Number(i.remaining_amount) > 0 ? `متبقٍ ${money(i.remaining_amount)}` : 'مسددة' }}</td>
                </tr>
                <tr v-if="!recentInvoices.length"><td class="py-6 text-center text-slate-400">لا فواتير بعد.</td></tr>
            </tbody></table>
        </div>
    </PortalLayout>
</template>
