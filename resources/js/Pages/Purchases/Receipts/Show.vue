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
        <Head :title="`${$t('p_receipts_show.title')} ${receipt.receipt_number}`" />
        <PageHeader :title="`استلام بضاعة ${receipt.receipt_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'الاستلامات', href: route('purchases.receipts.index') }, { label: receipt.receipt_number }]">
            <template #actions>
                <StatusBadge :status="receipt.status" />
                <Link v-if="receipt.status === 'received' && $can('purchases.invoices.create')" :href="route('purchases.invoices.from-receipts', { supplier_id: receipt.supplier_id })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_receipts_show.supplier_invoice') }}</Link>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('p_receipts_show.print') }}</button>
                <CancelDocument v-if="receipt.status === 'received' && $can('purchases.receipts.cancel')" :url="route('purchases.receipts.cancel', receipt.id)" :label="$t('p_receipts_show.goods_receipt_note')" />
                <p v-if="receipt.cancel_reason" class="text-3xs text-rose-600">{{ $t('p_receipts_show.cancel_reason') }}: {{ receipt.cancel_reason }}</p>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
            <div><div class="text-slate-400">{{ $t('p_receipts_show.supplier') }}</div><b>{{ receipt.supplier?.name }}</b></div>
            <div><div class="text-slate-400">{{ $t('p_receipts_show.date') }}</div><b class="font-mono">{{ date(receipt.receipt_date) }}</b></div>
            <div><div class="text-slate-400">{{ $t('p_receipts_show.warehouse') }}</div><b>{{ receipt.warehouse?.name_ar }}</b></div>
            <div><div class="text-slate-400">{{ $t('p_receipts_show.purchase_order') }}</div><Link v-if="receipt.purchase_order" :href="route('purchases.orders.show', receipt.purchase_order.id)" class="text-indigo-600 font-mono">{{ receipt.purchase_order.order_number }}</Link><b v-else>—</b></div>
            <div><div class="text-slate-400">{{ $t('p_receipts_show.supplier_notice') }}</div><b class="font-mono">{{ receipt.supplier_delivery_number || '—' }}</b></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('p_receipts_show.item') }}</th><th class="px-3 py-2">{{ $t('p_receipts_show.quantity') }}</th><th class="px-3 py-2">{{ $t('p_receipts_show.invoiced') }}</th><th class="px-3 py-2">{{ $t('p_receipts_show.batch') }}</th><th class="px-3 py-2 text-end">{{ $t('p_receipts_show.cost') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in receipt.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.item_name }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.invoiced_quantity) }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ i.batch_number || '—' }} <span v-if="i.expiry_date" class="text-slate-400">{{ date(i.expiry_date) }}</span></td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_cost) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold"><tr><td colspan="4" class="px-3 py-2 text-end">{{ $t('p_receipts_show.value') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(receipt.total_value) }}</td></tr></tfoot>
            </table>
        </div>
    </AppLayout>
</template>
