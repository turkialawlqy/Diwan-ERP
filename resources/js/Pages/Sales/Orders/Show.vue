<script setup>
import { useI18n } from 'vue-i18n';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
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

const props = defineProps({ order: Object, invoices: Array, approvals: Array, warehouses: Array });

const open = (item) => Number(item.quantity) - Number(item.delivered_quantity);
const canDeliver = computed(() => ['confirmed', 'partially_delivered'].includes(props.order.status));
const canInvoice = computed(() => ['confirmed', 'partially_delivered', 'delivered'].includes(props.order.status) && props.order.items.some((i) => Number(i.quantity) > Math.max(Number(i.delivered_quantity), Number(i.invoiced_quantity))));

const showDeliver = ref(false);
const deliverForm = useForm({
    delivery_date: today(),
    warehouse_id: props.order.warehouse_id || '',
    receiver_name: '',
    lines: props.order.items.map((i) => ({ sales_order_item_id: i.id, name: i.item_name, open: open(i), quantity: open(i) })),
});
const deliver = () => deliverForm.transform((d) => ({ ...d, lines: d.lines.map(({ sales_order_item_id, quantity }) => ({ sales_order_item_id, quantity })) }))
    .post(route('sales.orders.deliver', props.order.id), { onSuccess: () => (showDeliver.value = false) });

const remove = () => { if (confirm(t('s_order.confirm_trash'))) router.delete(route('sales.orders.destroy', props.order.id)); };
const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.order.id)); };
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('s_order.title')} ${order.order_number}`" />
        <PageHeader :title="`${$t('s_order.title')} ${order.order_number}`" :crumbs="[{ label: 'المبيعات' }, { label: 'أوامر البيع', href: route('sales.orders.index') }, { label: order.order_number }]">
            <template #actions>
                <StatusBadge :status="order.status" />
                <ShareDocument v-if="$can('sales.orders.print')" type="sales_order" :id="order.id" />
                <template v-if="order.status === 'pending_approval' && $can('sales.orders.approve')">
                    <button @click="act('sales.orders.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('s_order.approve') }}</button>
                    <button @click="act('sales.orders.reject', t('s_order.confirm_reject'))" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">{{ $t('s_order.reject') }}</button>
                </template>
                <button v-if="canDeliver && $can('sales.deliveries.create')" @click="showDeliver = true" class="px-3 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('s_order.delivery_note') }}</button>
                <button v-if="canInvoice && $can('sales.orders.convert')" @click="act('sales.orders.invoice', t('s_order.confirm_invoice'))" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('s_order.invoice_directly') }}</button>
                <button v-if="['draft', 'pending_approval', 'confirmed', 'rejected'].includes(order.status) && $can('sales.orders.cancel')" @click="act('sales.orders.cancel', t('s_order.confirm_cancel'))" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('s_order.cancel') }}</button>
                <button v-if="['draft', 'pending_approval', 'confirmed', 'rejected', 'cancelled'].includes(order.status) && $can('sales.orders.cancel')" @click="remove" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">{{ $t('s_order.delete') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                    <div><div class="text-slate-400">{{ $t('s_order.customer') }}</div><b>{{ order.customer?.name }}</b></div>
                    <div><div class="text-slate-400">{{ $t('s_order.date') }}</div><b class="font-mono">{{ date(order.order_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('s_order.expected_delivery') }}</div><b class="font-mono">{{ date(order.delivery_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('s_order.sales_rep') }}</div><b>{{ order.salesperson?.name || '—' }}</b></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('s_order.item') }}</th><th class="px-3 py-2">{{ $t('s_order.quantity') }}</th><th class="px-3 py-2">{{ $t('s_order.delivered') }}</th><th class="px-3 py-2">{{ $t('s_order.invoiced') }}</th><th class="px-3 py-2 text-end">{{ $t('s_order.price') }}</th><th class="px-3 py-2 text-end">{{ $t('s_order.total') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="i in order.items" :key="i.id" class="border-t border-slate-100">
                                <td class="px-3 py-2">{{ i.item_name }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.delivered_quantity) }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ qty(i.invoiced_quantity) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_price) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 font-bold">
                            <tr><td colspan="5" class="px-3 py-2 text-end">{{ $t('s_order.net') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(order.subtotal) }}</td></tr>
                            <tr><td colspan="5" class="px-3 py-2 text-end">{{ $t('s_order.tax') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(order.tax_amount) }}</td></tr>
                            <tr><td colspan="5" class="px-3 py-2 text-end">{{ $t('s_order.total') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(order.total) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">{{ $t('s_order.delivery_notes') }}</h3>
                    <div v-for="d in order.deliveries" :key="d.id" class="flex justify-between py-1"><Link :href="route('sales.deliveries.show', d.id)" class="text-indigo-600 font-mono">{{ d.delivery_number }}</Link><StatusBadge :status="d.status" /></div>
                    <p v-if="!order.deliveries.length" class="text-slate-400">{{ $t('s_order.none') }}</p>
                    <h3 class="font-black text-slate-700 mt-4 mb-2">{{ $t('s_order.invoices') }}</h3>
                    <div v-for="inv in invoices" :key="inv.id" class="flex justify-between py-1"><Link :href="route('sales.invoices.show', inv.id)" class="text-indigo-600 font-mono">{{ inv.invoice_number }}</Link><span class="font-mono">{{ money(inv.total) }}</span></div>
                    <p v-if="!invoices.length" class="text-slate-400">{{ $t('s_order.none') }}</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>

        <Dialog :show="showDeliver" :title="$t('s_order.goods_delivery_note')" size="lg" @close="showDeliver = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field :label="$t('s_order.delivery_date')"><DatePicker v-model="deliverForm.delivery_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_order.warehouse')"><SelectCreate v-model="deliverForm.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('s_order.default')" /></Field>
                <Field :label="$t('s_order.receiver')"><input v-model="deliverForm.receiver_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">{{ $t('s_order.item') }}</th><th>{{ $t('s_order.remaining') }}</th><th class="w-32">{{ $t('s_order.delivered_quantity') }}</th></tr>
                <tr v-for="l in deliverForm.lines" :key="l.sales_order_item_id" class="border-t border-slate-100">
                    <td class="py-1.5">{{ l.name }}</td>
                    <td class="text-center font-mono">{{ qty(l.open) }}</td>
                    <td><input v-model.number="l.quantity" type="text" inputmode="decimal" data-num min="0" :max="l.open" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                </tr>
            </table></div>
            <p v-for="(msg, key) in deliverForm.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="deliver" :disabled="deliverForm.processing" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('s_order.deliver_and_issue_from_stock') }}</button></template>
        </Dialog>
        <AttachmentsPanel kind="sales-order" :id="order.id" class="mt-4" />
    </AppLayout>
</template>
