<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Documents/ApprovalTimeline.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ order: Object, receipts: Array, invoices: Array, approvals: Array, warehouses: Array });
const canReceive = computed(() => ['approved', 'pending', 'partially_received'].includes(props.order.status));
const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.order.id)); };
const printPage = () => window.print();

const open = ref(false);
const form = useForm({
    receipt_date: new Date().toISOString().substring(0, 10),
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
        <Head :title="`أمر شراء ${order.order_number}`" />
        <PageHeader :title="`أمر شراء ${order.order_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء', href: route('purchases.orders.index') }, { label: order.order_number }]">
            <template #actions>
                <StatusBadge :status="order.status" />
                <template v-if="['draft', 'rejected'].includes(order.status)">
                    <Link v-if="$can('purchases.orders.edit')" :href="route('purchases.orders.edit', order.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تعديل</Link>
                    <button @click="act('purchases.orders.submit')" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إرسال للاعتماد</button>
                </template>
                <template v-if="order.status === 'pending_approval' && $can('purchases.orders.approve')">
                    <button @click="act('purchases.orders.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد</button>
                    <button @click="act('purchases.orders.reject', 'رفض أمر الشراء؟')" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">رفض</button>
                </template>
                <button v-if="canReceive && $can('purchases.receipts.create')" @click="open = true" class="px-3 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">استلام بضاعة</button>
                <Link v-if="['partially_received', 'received'].includes(order.status) && $can('purchases.invoices.create')" :href="route('purchases.invoices.from-receipts', { supplier_id: order.supplier_id })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">فاتورة المورد</Link>
                <button v-if="['draft', 'pending_approval', 'approved', 'rejected'].includes(order.status) && $can('purchases.orders.cancel')" @click="act('purchases.orders.cancel', 'إلغاء أمر الشراء؟')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">طباعة</button>
            </template>
        </PageHeader>
        <div class="grid lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                    <div><div class="text-slate-400">المورد</div><b>{{ order.supplier?.name }}</b></div>
                    <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(order.order_date) }}</b></div>
                    <div><div class="text-slate-400">التوريد المتوقع</div><b class="font-mono">{{ date(order.expected_delivery_date) }}</b></div>
                    <div><div class="text-slate-400">المستودع</div><b>{{ order.warehouse?.name_ar || '—' }}</b></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2">المستلم</th><th class="px-3 py-2">المفوتر</th><th class="px-3 py-2 text-end">السعر</th><th class="px-3 py-2 text-end">الإجمالي</th></tr></thead>
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
                        <tfoot class="bg-slate-50 font-bold"><tr><td colspan="5" class="px-3 py-2 text-end">الإجمالي شامل الضريبة</td><td class="px-3 py-2 text-end font-mono">{{ money(order.total) }}</td></tr></tfoot>
                    </table>
                    <p v-if="order.terms" class="p-3 text-xs text-slate-500 whitespace-pre-line">{{ order.terms }}</p>
                </div>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">الاستلامات</h3>
                    <div v-for="r in receipts" :key="r.id" class="flex justify-between py-1"><Link :href="route('purchases.receipts.show', r.id)" class="text-indigo-600 font-mono">{{ r.receipt_number }}</Link><StatusBadge :status="r.status" /></div>
                    <p v-if="!receipts.length" class="text-slate-400">لا يوجد</p>
                    <h3 class="font-black text-slate-700 mt-4 mb-2">فواتير المورد</h3>
                    <div v-for="inv in invoices" :key="inv.id" class="flex justify-between py-1"><Link :href="route('purchases.invoices.show', inv.id)" class="text-indigo-600 font-mono">{{ inv.invoice_number }}</Link><span class="font-mono">{{ money(inv.total) }}</span></div>
                    <p v-if="!invoices.length" class="text-slate-400">لا يوجد</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>
        <Dialog :show="open" title="استلام بضاعة (GRN)" size="xl" @close="open = false">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field label="تاريخ الاستلام"><input v-model="form.receipt_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المستودع"><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="رقم إشعار تسليم المورد"><input v-model="form.supplier_delivery_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">الصنف</th><th>المتبقي</th><th class="w-28">المستلم</th><th class="w-32">رقم التشغيلة</th><th class="w-36">الانتهاء</th></tr>
                <tr v-for="l in form.lines" :key="l.purchase_order_item_id" class="border-t border-slate-100">
                    <td class="py-1.5">{{ l.name }}</td>
                    <td class="text-center font-mono">{{ qty(l.open) }}</td>
                    <td><input v-model="l.quantity" type="number" min="0" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td><input v-model="l.batch_number" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                    <td><input v-model="l.expiry_date" type="date" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                </tr>
            </table></div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="receive" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">تسجيل الاستلام</button></template>
        </Dialog>
    </AppLayout>
</template>
