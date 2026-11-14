<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { money, date, qty, today } from '@/utils/format';

const props = defineProps({ supplier: Object, openItems: Array, bankAccounts: Array, suggestedNumber: String });
const pickSupplier = (id) => id && router.get(route('purchases.invoices.from-receipts'), { supplier_id: id });

const form = useForm({
    supplier_id: props.supplier?.id ?? null,
    supplier_invoice_number: '',
    invoice_date: today(),
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
        <Head :title="$t('p_invoices_fromreceipts.supplier_invoice_from_receipts')" />
        <PageHeader :title="$t('p_invoices_fromreceipts.supplier_invoice_against_receipt')" :subtitle="$t('p_invoices_fromreceipts.three_way_match_purchase_order_r')" :crumbs="[{ label: 'المشتريات' }, { label: 'فواتير المشتريات', href: route('purchases.invoices.index') }, { label: 'من الاستلامات' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('p_invoices_fromreceipts.supplier')" class="md:col-span-2"><PartyPicker :model-value="form.supplier_id" route-name="lookup.suppliers" :initial="supplier" @update:model-value="pickSupplier" /></Field>
                <Field :label="$t('p_invoices_fromreceipts.supplier_invoice_number')" :error="form.errors.supplier_invoice_number"><input v-model="form.supplier_invoice_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_invoices_fromreceipts.invoice_date')"><DatePicker v-model="form.invoice_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_invoices_fromreceipts.due_date')"><DatePicker v-model="form.due_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_invoices_fromreceipts.payment_method')"><select v-model="form.payment_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="credit">{{ $t('p_invoices_fromreceipts.credit') }}</option><option value="cash">{{ $t('p_invoices_fromreceipts.cash') }}</option><option value="bank_transfer">{{ $t('p_invoices_fromreceipts.bank_transfer') }}</option><option value="card">{{ $t('p_invoices_fromreceipts.card') }}</option></select></Field>
                <Field :label="$t('p_invoices_fromreceipts.payment_status')"><select v-model="form.payment_status" class="w-full rounded-xl border-slate-200 text-xs"><option value="unpaid">{{ $t('p_invoices_fromreceipts.unpaid') }}</option><option value="partially_paid">{{ $t('p_invoices_fromreceipts.partially_paid') }}</option><option value="paid">{{ $t('p_invoices_fromreceipts.paid') }}</option></select></Field>
                <Field v-if="form.payment_status === 'partially_paid'" :label="$t('p_invoices_fromreceipts.amount_paid')"><input v-model.number="form.paid_amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field v-if="form.payment_status !== 'unpaid'" :label="$t('p_invoices_fromreceipts.bank_account_cash_box')"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('p_invoices_fromreceipts.default')" /></Field>
            </div>
            <div v-if="!supplier" class="text-xs text-slate-400 py-8 text-center">{{ $t('p_invoices_fromreceipts.choose_the_supplier_to_see_uninv') }}</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-xs min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th></th><th class="text-start px-2 py-2">{{ $t('p_invoices_fromreceipts.receipt') }}</th><th class="text-start px-2 py-2">{{ $t('p_invoices_fromreceipts.item') }}</th><th class="px-2 py-2">{{ $t('p_invoices_fromreceipts.uninvoiced') }}</th><th class="px-2 py-2 w-24">{{ $t('p_invoices_fromreceipts.quantity') }}</th><th class="px-2 py-2">{{ $t('p_invoices_fromreceipts.order_price') }}</th><th class="px-2 py-2 w-28">{{ $t('p_invoices_fromreceipts.invoice_price') }}</th><th class="px-2 py-2 w-24">{{ $t('p_invoices_fromreceipts.discount') }}</th><th class="px-2 py-2 text-end">{{ $t('p_invoices_fromreceipts.net') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="l in form.lines" :key="l.goods_receipt_item_id" class="border-t border-slate-100" :class="{ 'opacity-40': !l.selected }">
                            <td class="px-2"><input v-model="l.selected" type="checkbox" class="rounded" /></td>
                            <td class="px-2 py-1.5 font-mono">{{ l.receipt }}</td>
                            <td class="px-2 py-1.5">{{ l.name }}</td>
                            <td class="px-2 py-1.5 text-center font-mono">{{ qty(l.open) }}</td>
                            <td class="px-2 py-1.5"><input v-model.number="l.quantity" type="text" inputmode="decimal" data-num step="0.0001" min="0" :max="l.open" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1.5 text-center font-mono">{{ l.po_price != null ? money(l.po_price) : '—' }}</td>
                            <td class="px-2 py-1.5"><input v-model.number="l.unit_price" type="text" inputmode="decimal" data-num step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" :class="l.po_price != null && Number(l.unit_price) > l.po_price ? 'border-amber-400 bg-amber-50' : ''" /></td>
                            <td class="px-2 py-1.5"><input v-model.number="l.discount" type="text" inputmode="decimal" data-num step="0.01" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1.5 text-end font-mono">{{ money(net(l)) }}</td>
                        </tr>
                        <tr v-if="!form.lines.length"><td colspan="9" class="py-6 text-center text-slate-400">{{ $t('p_invoices_fromreceipts.no_uninvoiced_receipts_for_this') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <label class="text-xs flex items-center gap-2"><input v-model="form.accept_price_variance" type="checkbox" class="rounded" /> {{ $t('p_invoices_fromreceipts.accept_price_differences_from_th') }}</label>
            <div class="flex justify-between items-end">
                <div><p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p></div>
                <dl class="text-xs w-64 space-y-1">
                    <div class="flex justify-between"><dt class="text-slate-500">{{ $t('p_invoices_fromreceipts.net') }}</dt><dd class="font-mono">{{ money(totals.net) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ $t('p_invoices_fromreceipts.tax') }}</dt><dd class="font-mono">{{ money(totals.tax) }}</dd></div>
                    <div class="flex justify-between border-t pt-1 font-black"><dt>{{ $t('p_invoices_fromreceipts.total') }}</dt><dd class="font-mono">{{ money(totals.total) }}</dd></div>
                </dl>
            </div>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing || !selected.length || !form.supplier_invoice_number" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('p_invoices_fromreceipts.record_the_supplier_invoice') }}</button></div>
        </div>
    </AppLayout>
</template>
