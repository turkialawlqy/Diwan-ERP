<script setup>
import { useI18n } from 'vue-i18n';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Documents/ApprovalTimeline.vue';
import { money, date, qty, today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ order: Object, receipts: Array, invoices: Array, approvals: Array, warehouses: Array });
const canReceive = computed(() => ['approved', 'pending', 'partially_received', 'partially_invoiced'].includes(props.order.status));
const remove = () => { if (confirm(t('p_orders_show.confirm_trash'))) router.delete(route('purchases.orders.destroy', props.order.id)); };
const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.order.id)); };
const printPage = () => window.print();

const open = ref(false);
const form = useForm({
    receipt_date: today(),
    warehouse_id: props.order.warehouse_id || '',
    supplier_delivery_number: '',
    lines: props.order.items.filter((i) => i.product_id).map((i) => {
        const remaining = Number(i.quantity) - Number(i.received_quantity);
        return { purchase_order_item_id: i.id, name: i.item_name, open: remaining, quantity: remaining, batch_number: '', expiry_date: '' };
    }),
});
const receive = () => form.transform((d) => ({ ...d, lines: d.lines.map(({ name, open, ...rest }) => rest) }))
    .post(route('purchases.orders.receive', props.order.id), { onSuccess: () => (open.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('p_orders_index.purchase_order')} ${order.order_number}`" />
        <PageHeader :title="`${$t('p_orders_index.purchase_order')} ${order.order_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء', href: route('purchases.orders.index') }, { label: order.order_number }]">
            <template #actions>
                <ShareDocument v-if="$can('purchases.orders.print')" type="purchase_order" :id="order.id" />
                <StatusBadge :status="order.status" />
                <template v-if="['draft', 'rejected'].includes(order.status)">
                    <Link v-if="$can('purchases.orders.edit')" :href="route('purchases.orders.edit', order.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('p_orders_show.edit') }}</Link>
                    <button @click="act('purchases.orders.submit')" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_orders_show.send_for_approval') }}</button>
                </template>
                <template v-if="order.status === 'pending_approval' && $can('purchases.orders.approve')">
                    <button @click="act('purchases.orders.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('p_orders_show.approve') }}</button>
                    <button @click="act('purchases.orders.reject', t('p_orders_show.confirm_reject'))" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">{{ $t('p_orders_show.reject') }}</button>
                </template>
                <button v-if="canReceive && $can('purchases.receipts.create')" @click="open = true" class="px-3 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('p_orders_show.receive_goods') }}</button>
                <Link v-if="['pending', 'approved', 'partially_invoiced'].includes(order.status) && $can('purchases.orders.convert')" :href="route('purchases.orders.invoice-form', order.id)" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-stage-invoice>{{ $t('p_orders_show.invoice_the_order') }}</Link>
                <Link v-if="['partially_received', 'received'].includes(order.status) && $can('purchases.invoices.create')" :href="route('purchases.invoices.from-receipts', { supplier_id: order.supplier_id })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_orders_show.supplier_invoice') }}</Link>
                <button v-if="['draft', 'pending_approval', 'approved', 'rejected'].includes(order.status) && $can('purchases.orders.cancel')" @click="act('purchases.orders.cancel', t('p_orders_show.confirm_cancel'))" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('p_orders_show.cancel') }}</button>
                <button v-if="['draft', 'pending_approval', 'approved', 'rejected', 'cancelled'].includes(order.status) && $can('purchases.orders.cancel')" @click="remove" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">{{ $t('p_orders_show.delete') }}</button>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('p_orders_show.print') }}</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                    <div><div class="text-slate-400">{{ $t('p_orders_show.supplier') }}</div><b>{{ order.supplier?.name }}</b></div>
                    <div><div class="text-slate-400">{{ $t('p_orders_show.date') }}</div><b class="font-mono">{{ date(order.order_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('p_orders_show.expected_supply') }}</div><b class="font-mono">{{ date(order.expected_delivery_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('p_orders_show.warehouse') }}</div><b>{{ order.warehouse?.name_ar || '—' }}</b></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('p_orders_show.item') }}</th><th class="px-3 py-2">{{ $t('p_orders_show.quantity') }}</th><th class="px-3 py-2">{{ $t('p_orders_show.receiver') }}</th><th class="px-3 py-2">{{ $t('p_orders_show.invoiced') }}</th><th class="px-3 py-2 text-end">{{ $t('p_orders_show.price') }}</th><th class="px-3 py-2 text-end">{{ $t('p_orders_show.total') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="i in order.items" :key="i.id" class="border-t border-slate-100">
                                <td class="px-3 py-2">{{ i.item_name }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.received_quantity) }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.invoiced_quantity) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_price) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 font-bold"><tr><td colspan="5" class="px-3 py-2 text-end">{{ $t('p_orders_show.total_including_tax') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(order.total) }}</td></tr></tfoot>
                    </table>
                    <p v-if="order.terms" class="p-3 text-xs text-slate-500 whitespace-pre-line">{{ order.terms }}</p>
                </div>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">{{ $t('p_orders_show.receipts') }}</h3>
                    <div v-for="r in receipts" :key="r.id" class="flex justify-between py-1"><Link :href="route('purchases.receipts.show', r.id)" class="text-indigo-600 font-mono">{{ r.receipt_number }}</Link><StatusBadge :status="r.status" /></div>
                    <p v-if="!receipts.length" class="text-slate-400">{{ $t('p_orders_show.none') }}</p>
                    <h3 class="font-black text-slate-700 mt-4 mb-2">{{ $t('p_orders_show.supplier_invoices') }}</h3>
                    <div v-for="inv in invoices" :key="inv.id" class="flex justify-between py-1"><Link :href="route('purchases.invoices.show', inv.id)" class="text-indigo-600 font-mono">{{ inv.invoice_number }}</Link><span class="font-mono">{{ money(inv.total) }}</span></div>
                    <p v-if="!invoices.length" class="text-slate-400">{{ $t('p_orders_show.none') }}</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>
        <Dialog :show="open" :title="$t('p_orders_show.goods_receipt_grn')" size="xl" @close="open = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field :label="$t('p_orders_show.receipt_date')"><DatePicker v-model="form.receipt_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_orders_show.warehouse')"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('p_orders_show.default')" /></Field>
                <Field :label="$t('p_orders_show.supplier_delivery_note_number')"><input v-model="form.supplier_delivery_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">{{ $t('p_orders_show.item') }}</th><th>{{ $t('p_orders_show.remaining') }}</th><th class="w-28">{{ $t('p_orders_show.receiver') }}</th><th class="w-32">{{ $t('p_orders_show.batch_number') }}</th><th class="w-36">{{ $t('p_orders_show.expiry') }}</th></tr>
                <tr v-for="l in form.lines" :key="l.purchase_order_item_id" class="border-t border-slate-100">
                    <td class="py-1.5">{{ l.name }}</td>
                    <td class="text-center font-mono">{{ qty(l.open) }}</td>
                    <td><input v-model.number="l.quantity" type="text" inputmode="decimal" data-num min="0" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td><input v-model="l.batch_number" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                    <td><DatePicker v-model="l.expiry_date" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                </tr>
            </table></div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="receive" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('p_orders_show.record_the_receipt') }}</button></template>
        </Dialog>
        <AttachmentsPanel kind="purchase-order" :id="order.id" class="mt-4" />
    </AppLayout>
</template>
