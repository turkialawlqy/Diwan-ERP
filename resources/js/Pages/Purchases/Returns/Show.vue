<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money, date, qty } from '@/utils/format';

defineProps({ purchaseReturn: Object });
const settlement = { credit: 'تخفيض رصيد المورد', cash: 'استرداد نقدي', bank_transfer: 'استرداد بنكي' };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="`مرتجع ${purchaseReturn.return_number}`" />
        <PageHeader :title="`مرتجع مشتريات ${purchaseReturn.return_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'المرتجعات', href: route('purchases.returns.index') }, { label: purchaseReturn.return_number }]">
            <template #actions><button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">طباعة</button></template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
            <div><div class="text-slate-400">المورد</div><b>{{ purchaseReturn.supplier?.name }}</b></div>
            <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(purchaseReturn.return_date) }}</b></div>
            <div><div class="text-slate-400">الفاتورة</div><Link v-if="purchaseReturn.purchase_invoice" :href="route('purchases.invoices.show', purchaseReturn.purchase_invoice.id)" class="text-indigo-600 font-mono">{{ purchaseReturn.purchase_invoice.invoice_number }}</Link></div>
            <div><div class="text-slate-400">التسوية</div><b>{{ settlement[purchaseReturn.settlement] }}</b></div>
            <div><div class="text-slate-400">المستودع</div><b>{{ purchaseReturn.warehouse?.name_ar }}</b></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2 text-end">السعر</th><th class="px-3 py-2 text-end">الضريبة</th><th class="px-3 py-2 text-end">الإجمالي</th></tr></thead>
                <tbody>
                    <tr v-for="i in purchaseReturn.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.item_name }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_price) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.tax_amount) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold"><tr><td colspan="4" class="px-3 py-2 text-end">الإجمالي</td><td class="px-3 py-2 text-end font-mono">{{ money(purchaseReturn.total) }}</td></tr></tfoot>
            </table>
            <p v-if="purchaseReturn.reason" class="p-3 text-xs text-slate-500">السبب: {{ purchaseReturn.reason }}</p>
        </div>
    </AppLayout>
</template>
