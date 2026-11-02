<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, invoice: Object, pdfUrl: String });
</script>

<template>
    <Head :title="`فاتورة ${invoice.invoice_number}`" />
    <PortalLayout :portal="portal" active="invoices">
        <div class="flex items-center justify-between mb-3">
            <h1 class="text-lg font-black text-slate-900">فاتورة {{ invoice.invoice_number }}</h1>
            <a :href="pdfUrl" target="_blank" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-invoice-pdf>تنزيل PDF</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">التاريخ</p><p class="font-mono font-bold">{{ invoice.invoice_date?.slice(0, 10) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">الاستحقاق</p><p class="font-mono font-bold">{{ invoice.due_date?.slice(0, 10) || '—' }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">الإجمالي</p><p class="font-mono font-bold">{{ money(invoice.total) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">المتبقي</p><p class="font-mono font-bold" :class="Number(invoice.remaining_amount) > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ money(invoice.remaining_amount) }}</p></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">البند</th><th class="px-4 py-2 text-start">الكمية</th><th class="px-4 py-2 text-start">السعر</th><th class="px-4 py-2 text-start">الضريبة %</th><th class="px-4 py-2 text-start">الإجمالي</th></tr></thead>
                <tbody>
                    <tr v-for="(it, i) in invoice.items" :key="i" class="border-t border-slate-100"><td class="px-4 py-2 font-bold">{{ it.item_name }}</td><td class="px-4 py-2 font-mono">{{ Number(it.quantity) }}</td><td class="px-4 py-2 font-mono">{{ money(it.unit_price) }}</td><td class="px-4 py-2 font-mono">{{ Number(it.tax_rate) }}</td><td class="px-4 py-2 font-mono">{{ money(it.total) }}</td></tr>
                </tbody>
                <tfoot class="border-t border-slate-200 font-mono"><tr><td colspan="4" class="px-4 py-2 text-start font-sans">قبل الضريبة</td><td class="px-4 py-2">{{ money(invoice.subtotal) }}</td></tr><tr><td colspan="4" class="px-4 py-2 text-start font-sans">الضريبة</td><td class="px-4 py-2">{{ money(invoice.tax_amount) }}</td></tr><tr class="font-bold"><td colspan="4" class="px-4 py-2 text-start font-sans">الإجمالي</td><td class="px-4 py-2">{{ money(invoice.total) }}</td></tr></tfoot>
            </table>
        </div>
        <Link :href="route('customer-portal.invoices.index', { company: portal.company.id })" class="inline-block mt-3 text-xs text-indigo-600 font-bold">← كل الفواتير</Link>
    </PortalLayout>
</template>
