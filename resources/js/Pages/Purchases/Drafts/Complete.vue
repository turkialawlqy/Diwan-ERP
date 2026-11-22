<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money, today } from '@/utils/format';

/**
 * Accounting completes a keeper's stock-in: the supplier, the date and the real prices turn the goods receipt into a supplier
 * invoice. The stock is already in; price differences from the provisional cost go to the stock value.
 */
const props = defineProps({ draft: Object, items: Array, bankAccounts: Array });

const form = useForm({
    supplier_id: '',
    supplier_invoice_number: '',
    invoice_date: today(),
    due_date: '',
    payment_method: 'credit',
    payment_status: 'unpaid',
    paid_amount: 0,
    bank_account_id: '',
    notes: '',
    attachment_ids: [],
    lines: props.items.map((i) => ({ goods_receipt_item_id: i.id, name: i.name, received: i.quantity, quantity: i.quantity, unit_price: i.provisional_cost, provisional: i.provisional_cost, discount: 0, tax_rate: i.tax_rate })),
});
useUnsavedGuard(form);

const net = (l) => Math.max(0, (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount) || 0));
const lineTotal = (l) => net(l) * (1 + (Number(l.tax_rate) || 0) / 100);
const subtotal = computed(() => form.lines.reduce((s, l) => s + net(l), 0));
const total = computed(() => form.lines.reduce((s, l) => s + lineTotal(l), 0));
const err = (i, f) => form.errors[`lines.${i}.${f}`];

const submit = () => form.transform((d) => ({
    ...d,
    lines: d.lines.map(({ goods_receipt_item_id, quantity, unit_price, discount, tax_rate }) => ({ goods_receipt_item_id, quantity, unit_price, discount, tax_rate })),
})).post(route('purchases.drafts.complete', props.draft.id));

const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('pdraft.complete_title', { number: draft.number })" />
        <PageHeader :title="$t('pdraft.complete_title', { number: draft.number })" :subtitle="$t('pdraft.complete_subtitle', { warehouse: draft.warehouse, date: draft.date })" :crumbs="[{ label: 'المشتريات' }, { label: $t('pdraft.title'), href: route('purchases.drafts.index') }, { label: draft.number }]" />

        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <p v-if="form.errors.draft" class="rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-700">{{ form.errors.draft }}</p>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-4 gap-4">
                <Field :label="$t('pdraft.supplier')" :error="form.errors.supplier_id" class="md:col-span-2"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field :label="$t('pdraft.supplier_invoice_number')" :error="form.errors.supplier_invoice_number"><input v-model="form.supplier_invoice_number" :class="input" data-draft-number /></Field>
                <Field :label="$t('pdraft.invoice_date')" :error="form.errors.invoice_date"><DatePicker v-model="form.invoice_date" :class="input" /></Field>
                <Field :label="$t('pdraft.due_date')" :error="form.errors.due_date"><DatePicker v-model="form.due_date" :class="input" /></Field>
                <Field :label="$t('pdraft.payment_method')"><select v-model="form.payment_method" :class="input"><option value="credit">{{ $t('p_invoices_fromreceipts.credit') }}</option><option value="cash">{{ $t('s_payments.cash') }}</option><option value="bank_transfer">{{ $t('s_payments.bank_transfer') }}</option><option value="card">{{ $t('p_invoices_fromreceipts.card') }}</option></select></Field>
                <Field :label="$t('p_invoices_fromreceipts.payment_status')"><select v-model="form.payment_status" :class="input"><option value="unpaid">{{ $t('s_invoices.unpaid') }}</option><option value="partially_paid">{{ $t('s_invoices.partially_paid') }}</option><option value="paid">{{ $t('s_invoices.paid') }}</option></select></Field>
                <Field v-if="form.payment_status === 'partially_paid'" :label="$t('p_invoices_fromreceipts.amount_paid')" :error="form.errors.paid_amount"><input v-model.number="form.paid_amount" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
                <Field v-if="form.payment_status !== 'unpaid'" :label="$t('p_invoices_fromreceipts.bank_account_cash_box')" :error="form.errors.bank_account_id"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" label="name" entity="bank" /></Field>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-draft-lines>
                <p v-if="form.errors.lines" class="m-3 rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-700">{{ form.errors.lines }}</p>
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ $t('p_invoices_fromreceipts.item') }}</th>
                            <th class="px-3 py-2 text-start w-28">{{ $t('pdraft.received') }}</th>
                            <th class="px-3 py-2 text-start w-28">{{ $t('pdraft.invoice_qty') }}</th>
                            <th class="px-3 py-2 text-start w-32">{{ $t('pdraft.unit_price') }}</th>
                            <th class="px-3 py-2 text-start w-28">{{ $t('pdraft.discount') }}</th>
                            <th class="px-3 py-2 text-start w-24">{{ $t('pdraft.tax') }}</th>
                            <th class="px-3 py-2 text-end w-32">{{ $t('pdraft.line_total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="l.goods_receipt_item_id" class="border-t border-slate-100 align-top" data-draft-line>
                            <td class="px-3 py-2 font-bold text-slate-800">{{ l.name }}<p class="text-slate-400 font-normal">{{ $t('pdraft.provisional', { cost: money(l.provisional) }) }}</p></td>
                            <td class="px-3 py-2 font-mono">{{ l.received }}</td>
                            <td class="px-3 py-2"><input v-model.number="l.quantity" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /><p v-if="err(i, 'quantity')" class="text-rose-600">{{ err(i, 'quantity') }}</p></td>
                            <td class="px-3 py-2"><input v-model.number="l.unit_price" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-draft-price /><p v-if="err(i, 'unit_price')" class="text-rose-600">{{ err(i, 'unit_price') }}</p></td>
                            <td class="px-3 py-2"><input v-model.number="l.discount" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></td>
                            <td class="px-3 py-2"><input v-model.number="l.tax_rate" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></td>
                            <td class="px-3 py-2 text-end font-mono font-bold">{{ money(lineTotal(l)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold">
                        <tr><td colspan="6" class="px-3 py-2 text-end">{{ $t('pdraft.subtotal') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(subtotal) }}</td></tr>
                        <tr><td colspan="6" class="px-3 py-2 text-end">{{ $t('pdraft.total') }}</td><td class="px-3 py-2 text-end font-mono text-indigo-700">{{ money(total) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            <Field :label="$t('stockin.notes')" :error="form.errors.notes"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <AttachmentsPanel kind="purchase-invoice" v-model="form.attachment_ids" />

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-draft-save>{{ $t('pdraft.issue') }}</button>
                <Link :href="route('purchases.drafts.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('ui.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
