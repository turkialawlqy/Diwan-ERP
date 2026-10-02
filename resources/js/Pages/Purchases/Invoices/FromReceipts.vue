<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ supplier: Object, openItems: Array, bankAccounts: Array, suggestedNumber: String });
const pickSupplier = (id) => id && router.get(route('purchases.invoices.from-receipts'), { supplier_id: id });

const form = useForm({
    supplier_id: props.supplier?.id ?? null,
    supplier_invoice_number: '',
    invoice_date: new Date().toISOString().substring(0, 10),
    due_date: '',
    payment_method: 'credit',
    payment_status: 'unpaid',
    paid_amount: 0,
    bank_account_id: '',
    accept_price_variance: false,
    notes: '',
    lines: props.openItems.map((i) => ({
        goods_receipt_item_id: i.id, name: i.item_name, receipt: i.receipt?.receipt_number, open: i.open_quantity, po_price: i.po_unit_price,
        quantity: i.open_quantity, unit_price: i.po_unit_price ?? Number(i.unit_cost), discount: 0, tax_rate: i.tax_rate, selected: true,
    })),
});
const selected = computed(() => form.lines.filter((l) => l.selected && Number(l.quantity) > 0));
const net = (l) => Number(l.quantity) * Number(l.unit_price) - Number(l.discount || 0);
const totals = computed(() => {
    const n = selected.value.reduce((s, l) => s + net(l), 0);
    const t = selected.value.reduce((s, l) => s + Math.round(net(l) * Number(l.tax_rate)) / 100, 0);
    return { net: n, tax: t, total: n + t };
});
const save = () => form.transform((d) => ({ ...d, lines: selected.value.map(({ goods_receipt_item_id, quantity, unit_price, discount, tax_rate }) => ({ goods_receipt_item_id, quantity, unit_price, discount, tax_rate })) }))
    .post(route('purchases.invoices.store-from-receipts'));
</script>

<template>
    <AppLayout>
        <Head title="فاتورة مورد من الاستلامات" />
        <PageHeader title="فاتورة مورد مقابل الاستلامات" subtitle="مطابقة ثلاثية: أمر الشراء ↔ الاستلام ↔ فاتورة المورد" :crumbs="[{ label: 'المشتريات' }, { label: 'فواتير المشتريات', href: route('purchases.invoices.index') }, { label: 'من الاستلامات' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="المورد" class="md:col-span-2"><PartyPicker :model-value="form.supplier_id" route-name="lookup.suppliers" :initial="supplier" @update:model-value="pickSupplier" /></Field>
                <Field label="رقم فاتورة المورد" :error="form.errors.supplier_invoice_number"><input v-model="form.supplier_invoice_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ الفاتورة"><input v-model="form.invoice_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ الاستحقاق"><input v-model="form.due_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="طريقة الدفع"><select v-model="form.payment_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="credit">آجل</option><option value="cash">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="card">بطاقة</option></select></Field>
                <Field label="حالة السداد"><select v-model="form.payment_status" class="w-full rounded-xl border-slate-200 text-xs"><option value="unpaid">غير مدفوعة</option><option value="partially_paid">مدفوعة جزئياً</option><option value="paid">مدفوعة</option></select></Field>
                <Field v-if="form.payment_status === 'partially_paid'" label="المبلغ المدفوع"><input v-model="form.paid_amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field v-if="form.payment_status !== 'unpaid'" label="الحساب البنكي / الصندوق"><select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <div v-if="!supplier" class="text-xs text-slate-400 py-8 text-center">اختر المورد لعرض الاستلامات غير المفوترة.</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-xs min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th></th><th class="text-start px-2 py-2">الاستلام</th><th class="text-start px-2 py-2">الصنف</th><th class="px-2 py-2">غير المفوتر</th><th class="px-2 py-2 w-24">الكمية</th><th class="px-2 py-2">سعر الأمر</th><th class="px-2 py-2 w-28">سعر الفاتورة</th><th class="px-2 py-2 w-24">الخصم</th><th class="px-2 py-2 text-end">الصافي</th></tr></thead>
                    <tbody>
                        <tr v-for="l in form.lines" :key="l.goods_receipt_item_id" class="border-t border-slate-100" :class="{ 'opacity-40': !l.selected }">
                            <td class="px-2"><input v-model="l.selected" type="checkbox" class="rounded" /></td>
                            <td class="px-2 py-1.5 font-mono">{{ l.receipt }}</td>
                            <td class="px-2 py-1.5">{{ l.name }}</td>
                            <td class="px-2 py-1.5 text-center font-mono">{{ qty(l.open) }}</td>
                            <td class="px-2 py-1.5"><input v-model="l.quantity" type="number" step="0.0001" min="0" :max="l.open" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1.5 text-center font-mono">{{ l.po_price != null ? money(l.po_price) : '—' }}</td>
                            <td class="px-2 py-1.5"><input v-model="l.unit_price" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" :class="l.po_price != null && Number(l.unit_price) > l.po_price ? 'border-amber-400 bg-amber-50' : ''" /></td>
                            <td class="px-2 py-1.5"><input v-model="l.discount" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1.5 text-end font-mono">{{ money(net(l)) }}</td>
                        </tr>
                        <tr v-if="!form.lines.length"><td colspan="9" class="py-6 text-center text-slate-400">لا توجد استلامات غير مفوترة لهذا المورد.</td></tr>
                    </tbody>
                </table>
            </div>
            <label class="text-xs flex items-center gap-2"><input v-model="form.accept_price_variance" type="checkbox" class="rounded" /> قبول فروقات السعر عن أمر الشراء (يُحمّل الفرق على تكلفة المشتريات)</label>
            <div class="flex justify-between items-end">
                <div><p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p></div>
                <dl class="text-xs w-64 space-y-1">
                    <div class="flex justify-between"><dt class="text-slate-500">الصافي</dt><dd class="font-mono">{{ money(totals.net) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="font-mono">{{ money(totals.tax) }}</dd></div>
                    <div class="flex justify-between border-t pt-1 font-black"><dt>الإجمالي</dt><dd class="font-mono">{{ money(totals.total) }}</dd></div>
                </dl>
            </div>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing || !selected.length || !form.supplier_invoice_number" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">تسجيل فاتورة المورد</button></div>
        </div>
    </AppLayout>
</template>
