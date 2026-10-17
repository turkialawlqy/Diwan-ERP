<script setup>
import CancelDocument from '@/Components/CancelDocument.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ note: Object });
const invoice = () => { if (confirm('إصدار فاتورة ضريبية لهذا الإذن؟')) router.post(route('sales.deliveries.invoice', props.note.id)); };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="`إذن تسليم ${note.delivery_number}`" />
        <PageHeader :title="`إذن تسليم ${note.delivery_number}`" :crumbs="[{ label: 'المبيعات' }, { label: 'أذونات التسليم', href: route('sales.deliveries.index') }, { label: note.delivery_number }]">
            <template #actions>
                <StatusBadge :status="note.status" />
                <button v-if="note.status === 'delivered' && $can('sales.deliveries.convert')" @click="invoice" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إصدار فاتورة</button>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">طباعة</button>
                <CancelDocument v-if="note.status === 'delivered' && $can('sales.deliveries.cancel')" :url="route('sales.deliveries.cancel', note.id)" label="إذن التسليم" />
                <p v-if="note.cancel_reason" class="text-3xs text-rose-600">سبب الإلغاء: {{ note.cancel_reason }}</p>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
            <div><div class="text-slate-400">العميل</div><b>{{ note.customer?.name }}</b></div>
            <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(note.delivery_date) }}</b></div>
            <div><div class="text-slate-400">المستودع</div><b>{{ note.warehouse?.name_ar }}</b></div>
            <div><div class="text-slate-400">أمر البيع</div><Link v-if="note.sales_order" :href="route('sales.orders.show', note.sales_order.id)" class="text-indigo-600 font-mono">{{ note.sales_order.order_number }}</Link><b v-else>—</b></div>
            <div><div class="text-slate-400">الفاتورة</div><Link v-if="note.invoice" :href="route('sales.invoices.show', note.invoice.id)" class="text-indigo-600 font-mono">{{ note.invoice.invoice_number }}</Link><b v-else>—</b></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2 text-end">سعر البيع</th><th v-if="$can('inventory.costs.view')" class="px-3 py-2 text-end">التكلفة</th></tr></thead>
                <tbody>
                    <tr v-for="i in note.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.item_name }} <span v-if="i.serials?.length" class="text-slate-400 font-mono">({{ i.serials.join('، ') }})</span></td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_price) }}</td>
                        <td v-if="$can('inventory.costs.view')" class="px-3 py-2 text-end font-mono">{{ money(i.unit_cost) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="note.receiver_name" class="p-3 text-xs text-slate-500">المستلم: {{ note.receiver_name }}</p>
        </div>
    </AppLayout>
</template>
