<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { amount } from '@/utils/format';

const props = defineProps({ order: Object, items: Array, bankAccounts: Array });
const form = useForm({
    lines: props.items.map((i) => ({ item_id: i.id, name: i.item_name, open: i.open_quantity, unit_price: Number(i.unit_price), discount: Number(i.discount), ordered: Number(i.quantity), tax_rate: Number(i.tax_rate), quantity: i.open_quantity })),
    supplier_invoice_number: '',
    due_date: '',
    payments: [],
});
useUnsavedGuard(form);

const lineTotal = (l) => {
    const q = Number(l.quantity) || 0;
    const net = q * l.unit_price - (l.ordered > 0 ? (l.discount * q) / l.ordered : 0);
    return net + net * (l.tax_rate / 100);
};
const total = computed(() => form.lines.reduce((s, l) => s + lineTotal(l), 0));
const paid = computed(() => form.payments.reduce((s, p) => s + (Number(p.amount) || 0), 0));
const addPayment = () => form.payments.push({ method: 'cash', bank_account_id: '', amount: Math.max(0, Math.round((total.value - paid.value) * 100) / 100) || '' });
const submit = () => form.transform((d) => ({
    lines: d.lines.map((l) => ({ item_id: l.item_id, quantity: Number(l.quantity) || 0 })),
    supplier_invoice_number: d.supplier_invoice_number || null,
    due_date: d.due_date || null,
    payments: d.payments.map((p) => ({ ...p, amount: Number(p.amount) || 0, bank_account_id: p.bank_account_id || null })),
})).post(route('purchases.orders.convert', props.order.id));
const input = 'rounded-lg border border-slate-200 px-2 py-1.5 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('p_orders_invoice.title')} ${order.order_number}`" />
        <PageHeader :title="`${$t('p_orders_invoice.title')} ${order.order_number}`" :subtitle="order.supplier?.name" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء', href: route('purchases.orders.index') }, { label: order.order_number, href: route('purchases.orders.show', order.id) }, { label: 'فوترة' }]" />
        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('p_orders_invoice.item') }}</th><th class="px-3 py-2">{{ $t('p_orders_invoice.required') }}</th><th class="px-3 py-2">{{ $t('p_orders_invoice.remaining') }}</th><th class="px-3 py-2">{{ $t('p_orders_invoice.this_invoice_quantity') }}</th><th class="text-end px-4 py-2">{{ $t('p_orders_invoice.amount_with_tax') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="l in form.lines" :key="l.item_id" class="border-t border-slate-100" data-invoice-line>
                            <td class="px-4 py-2 font-bold">{{ l.name }}</td>
                            <td class="px-3 py-2 text-center font-mono">{{ l.ordered }}</td>
                            <td class="px-3 py-2 text-center font-mono">{{ l.open }}</td>
                            <td class="px-3 py-2 text-center"><input v-model="l.quantity" type="text" inputmode="decimal" data-num :disabled="l.open <= 0" :class="input" class="w-24 text-center font-mono" /></td>
                            <td class="px-4 py-2 text-end font-mono">{{ amount(lineTotal(l)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="font-black"><tr class="border-t-2 border-slate-200"><td colspan="4" class="px-4 py-2">{{ $t('p_orders_invoice.invoice_total') }}</td><td class="px-4 py-2 text-end font-mono">{{ amount(total) }}</td></tr></tfoot>
                </table>
            </div>
            <p v-if="form.errors.lines" class="text-rose-600 font-bold">{{ form.errors.lines }}</p>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 grid sm:grid-cols-2 gap-3">
                <label class="block"><span class="block font-bold text-slate-600 mb-1">{{ $t('p_orders_invoice.supplier_invoice_number') }}</span><input v-model="form.supplier_invoice_number" type="text" data-supplier-invoice-number :class="input" class="w-full" /></label>
                <label class="block"><span class="block font-bold text-slate-600 mb-1">{{ $t('p_orders_invoice.due_date_default_30_days') }}</span><DatePicker v-model="form.due_date" :class="input" class="w-full" /></label>
                <p v-if="form.errors.due_date" class="text-rose-600 font-bold sm:col-span-2">{{ form.errors.due_date }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <b>{{ $t('p_orders_invoice.payments_at_issue_optional') }}</b>
                    <button type="button" @click="addPayment" class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">{{ $t('p_orders_invoice.payment') }}</button>
                </div>
                <div v-for="(p, i) in form.payments" :key="i" class="grid grid-cols-12 gap-2 items-center">
                    <select v-model="p.method" :class="input" class="col-span-3 pe-8"><option value="cash">{{ $t('p_orders_invoice.cash') }}</option><option value="bank_transfer">{{ $t('p_orders_invoice.bank_transfer') }}</option><option value="card">{{ $t('p_orders_invoice.card_mada') }}</option></select>
                    <div class="col-span-5"><SelectCreate v-model="p.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('p_orders_invoice.cash_box_bank')" /></div>
                    <input v-model="p.amount" type="text" inputmode="decimal" data-num :class="input" class="col-span-3 font-mono" />
                    <button type="button" @click="form.payments.splice(i, 1)" class="col-span-1 text-rose-500 font-bold">×</button>
                </div>
                <p v-if="form.errors.payments" class="text-rose-600 font-bold">{{ form.errors.payments }}</p>
            </div>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('p_orders_invoice.issue_the_invoice') }}</button>
                <Link :href="route('purchases.orders.show', order.id)" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('p_orders_invoice.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
