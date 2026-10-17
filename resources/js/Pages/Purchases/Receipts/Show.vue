<script setup>
import CancelDocument from '@/Components/CancelDocument.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, qty } from '@/utils/format';

defineProps({ receipt: Object });
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="`استلام ${receipt.receipt_number}`" />
        <PageHeader :title="`استلام بضاعة ${receipt.receipt_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'الاستلامات', href: route('purchases.receipts.index') }, { label: receipt.receipt_number }]">
            <template #actions>
                <StatusBadge :status="receipt.status" />
                <Link v-if="receipt.status === 'received' && $can('purchases.invoices.create')" :href="route('purchases.invoices.from-receipts', { supplier_id: receipt.supplier_id })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">فاتورة المورد</Link>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">طباعة</button>
                <CancelDocument v-if="receipt.status === 'received' && $can('purchases.receipts.cancel')" :url="route('purchases.receipts.cancel', receipt.id)" label="إذن الاستلام" />
                <p v-if="receipt.cancel_reason" class="text-3xs text-rose-600">سبب الإلغاء: {{ receipt.cancel_reason }}</p>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
            <div><div class="text-slate-400">المورد</div><b>{{ receipt.supplier?.name }}</b></div>
            <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(receipt.receipt_date) }}</b></div>
            <div><div class="text-slate-400">المستودع</div><b>{{ receipt.warehouse?.name_ar }}</b></div>
            <div><div class="text-slate-400">أمر الشراء</div><Link v-if="receipt.purchase_order" :href="route('purchases.orders.show', receipt.purchase_order.id)" class="text-indigo-600 font-mono">{{ receipt.purchase_order.order_number }}</Link><b v-else>—</b></div>
            <div><div class="text-slate-400">إشعار المورد</div><b class="font-mono">{{ receipt.supplier_delivery_number || '—' }}</b></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2">المفوتر</th><th class="px-3 py-2">التشغيلة</th><th class="px-3 py-2 text-end">التكلفة</th></tr></thead>
                <tbody>
                    <tr v-for="i in receipt.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.item_name }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.invoiced_quantity) }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ i.batch_number || '—' }} <span v-if="i.expiry_date" class="text-slate-400">{{ date(i.expiry_date) }}</span></td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_cost) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold"><tr><td colspan="4" class="px-3 py-2 text-end">القيمة</td><td class="px-3 py-2 text-end font-mono">{{ money(receipt.total_value) }}</td></tr></tfoot>
            </table>
        </div>
    </AppLayout>
</template>
