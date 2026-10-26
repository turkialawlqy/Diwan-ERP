<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Documents/ApprovalTimeline.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ order: Object, invoices: Array, approvals: Array, warehouses: Array });

const open = (item) => Number(item.quantity) - Number(item.delivered_quantity);
const canDeliver = computed(() => ['confirmed', 'partially_delivered'].includes(props.order.status));
const canInvoice = computed(() => ['confirmed', 'partially_delivered', 'delivered'].includes(props.order.status) && props.order.items.some((i) => Number(i.quantity) > Math.max(Number(i.delivered_quantity), Number(i.invoiced_quantity))));

const showDeliver = ref(false);
const deliverForm = useForm({
    delivery_date: new Date().toISOString().substring(0, 10),
    warehouse_id: props.order.warehouse_id || '',
    receiver_name: '',
    lines: props.order.items.map((i) => ({ sales_order_item_id: i.id, name: i.item_name, open: open(i), quantity: open(i) })),
});
const deliver = () => deliverForm.transform((d) => ({ ...d, lines: d.lines.map(({ sales_order_item_id, quantity }) => ({ sales_order_item_id, quantity })) }))
    .post(route('sales.orders.deliver', props.order.id), { onSuccess: () => (showDeliver.value = false) });

const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.order.id)); };
</script>

<template>
    <AppLayout>
        <Head :title="`أمر بيع ${order.order_number}`" />
        <PageHeader :title="`أمر بيع ${order.order_number}`" :crumbs="[{ label: 'المبيعات' }, { label: 'أوامر البيع', href: route('sales.orders.index') }, { label: order.order_number }]">
            <template #actions>
                <StatusBadge :status="order.status" />
                <ShareDocument v-if="$can('sales.orders.print')" type="sales_order" :id="order.id" />
                <template v-if="order.status === 'pending_approval' && $can('sales.orders.approve')">
                    <button @click="act('sales.orders.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد</button>
                    <button @click="act('sales.orders.reject', 'رفض أمر البيع؟')" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">رفض</button>
                </template>
                <button v-if="canDeliver && $can('sales.deliveries.create')" @click="showDeliver = true" class="px-3 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">إذن تسليم</button>
                <button v-if="canInvoice && $can('sales.orders.convert')" @click="act('sales.orders.invoice', 'إصدار فاتورة بالكميات غير المسلمة وصرفها من المخزون؟')" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">فوترة مباشرة</button>
                <button v-if="['draft', 'pending_approval', 'confirmed', 'rejected'].includes(order.status) && $can('sales.orders.cancel')" @click="act('sales.orders.cancel', 'إلغاء أمر البيع؟')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
            </template>
        </PageHeader>

        <div class="grid lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                    <div><div class="text-slate-400">العميل</div><b>{{ order.customer?.name }}</b></div>
                    <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(order.order_date) }}</b></div>
                    <div><div class="text-slate-400">التسليم المتوقع</div><b class="font-mono">{{ date(order.delivery_date) }}</b></div>
                    <div><div class="text-slate-400">المندوب</div><b>{{ order.salesperson?.name || '—' }}</b></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2">المسلّم</th><th class="px-3 py-2">المفوتر</th><th class="px-3 py-2 text-end">السعر</th><th class="px-3 py-2 text-end">الإجمالي</th></tr></thead>
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
                            <tr><td colspan="5" class="px-3 py-2 text-end">الصافي</td><td class="px-3 py-2 text-end font-mono">{{ money(order.subtotal) }}</td></tr>
                            <tr><td colspan="5" class="px-3 py-2 text-end">الضريبة</td><td class="px-3 py-2 text-end font-mono">{{ money(order.tax_amount) }}</td></tr>
                            <tr><td colspan="5" class="px-3 py-2 text-end">الإجمالي</td><td class="px-3 py-2 text-end font-mono">{{ money(order.total) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">أذونات التسليم</h3>
                    <div v-for="d in order.deliveries" :key="d.id" class="flex justify-between py-1"><Link :href="route('sales.deliveries.show', d.id)" class="text-indigo-600 font-mono">{{ d.delivery_number }}</Link><StatusBadge :status="d.status" /></div>
                    <p v-if="!order.deliveries.length" class="text-slate-400">لا يوجد</p>
                    <h3 class="font-black text-slate-700 mt-4 mb-2">الفواتير</h3>
                    <div v-for="inv in invoices" :key="inv.id" class="flex justify-between py-1"><Link :href="route('sales.invoices.show', inv.id)" class="text-indigo-600 font-mono">{{ inv.invoice_number }}</Link><span class="font-mono">{{ money(inv.total) }}</span></div>
                    <p v-if="!invoices.length" class="text-slate-400">لا يوجد</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>

        <Dialog :show="showDeliver" title="إذن تسليم بضاعة" size="lg" @close="showDeliver = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field label="تاريخ التسليم"><input v-model="deliverForm.delivery_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المستودع"><select v-model="deliverForm.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="المستلم"><input v-model="deliverForm.receiver_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">الصنف</th><th>المتبقي</th><th class="w-32">الكمية المسلمة</th></tr>
                <tr v-for="l in deliverForm.lines" :key="l.sales_order_item_id" class="border-t border-slate-100">
                    <td class="py-1.5">{{ l.name }}</td>
                    <td class="text-center font-mono">{{ qty(l.open) }}</td>
                    <td><input v-model="l.quantity" type="number" min="0" :max="l.open" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                </tr>
            </table></div>
            <p v-for="(msg, key) in deliverForm.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="deliver" :disabled="deliverForm.processing" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">تسليم وصرف من المخزون</button></template>
        </Dialog>
    </AppLayout>
</template>
