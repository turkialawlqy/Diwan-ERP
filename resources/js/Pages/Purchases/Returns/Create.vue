<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money, qty, today } from '@/utils/format';

const props = defineProps({ invoice: Object, recentInvoices: Array, bankAccounts: Array, suggestedNumber: String });
const pick = (e) => router.get(route('purchases.returns.create'), { invoice_id: e.target.value });

const form = useForm({
    purchase_invoice_id: props.invoice?.id ?? null,
    return_date: today(),
    reason: '',
    settlement: 'credit',
    bank_account_id: '',
    items: (props.invoice?.items ?? []).map((i) => ({ purchase_invoice_item_id: i.id, name: i.item_name, returnable: i.returnable_quantity, unit_net: Number(i.quantity) ? (Number(i.quantity) * Number(i.unit_price) - Number(i.discount)) / Number(i.quantity) : 0, tax_rate: Number(i.tax_rate), quantity: 0 })),
});
useUnsavedGuard(form);
const total = computed(() => form.items.reduce((s, i) => s + Number(i.quantity) * i.unit_net * (1 + i.tax_rate / 100), 0));
const save = () => form.transform((d) => ({ ...d, items: d.items.filter((i) => Number(i.quantity) > 0).map(({ purchase_invoice_item_id, quantity }) => ({ purchase_invoice_item_id, quantity })) }))
    .post(route('purchases.returns.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('p_returns_create.purchase_return')" />
        <PageHeader :title="$t('p_returns_create.purchase_return')" :subtitle="`الرقم المتوقع: ${suggestedNumber}`" :crumbs="[{ label: 'المشتريات' }, { label: 'المرتجعات', href: route('purchases.returns.index') }, { label: 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('p_returns_create.purchase_invoice')" class="md:col-span-2">
                    <select :value="invoice?.id" @change="pick" class="w-full rounded-xl border-slate-200 text-xs">
                        <option value="">{{ $t('p_returns_create.choose_the_invoice') }}</option>
                        <option v-for="inv in recentInvoices" :key="inv.id" :value="inv.id">{{ inv.invoice_number }} — {{ inv.supplier?.name }} ({{ money(inv.total) }})</option>
                    </select>
                </Field>
                <Field :label="$t('p_returns_create.date')"><DatePicker v-model="form.return_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_returns_create.settlement')">
                    <select v-model="form.settlement" class="w-full rounded-xl border-slate-200 text-xs"><option value="credit">{{ $t('p_returns_create.reduce_the_supplier_balance') }}</option><option value="cash">{{ $t('p_returns_create.cash_refund') }}</option><option value="bank_transfer">{{ $t('p_returns_create.bank_refund') }}</option></select>
                </Field>
                <Field v-if="form.settlement !== 'credit'" :label="$t('p_returns_create.account')"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('p_returns_create.default')" /></Field>
                <Field :label="$t('p_returns_create.reason')" class="md:col-span-3"><input v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto" v-if="invoice"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('p_returns_create.item') }}</th><th class="px-3 py-2">{{ $t('p_returns_create.returnable') }}</th><th class="px-3 py-2 text-end">{{ $t('p_returns_create.net_unit_price') }}</th><th class="px-3 py-2 w-32">{{ $t('p_returns_create.return_quantity') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in form.items" :key="i.purchase_invoice_item_id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.name }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.returnable) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_net) }}</td>
                        <td class="px-3 py-2"><input v-model.number="i.quantity" type="text" inputmode="decimal" data-num min="0" :max="i.returnable" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    </tr>
                </tbody>
            </table></div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-between items-center">
                <span class="text-xs">{{ $t('p_returns_create.total_returned_including_tax') }} <b class="font-mono">{{ money(total) }}</b></span>
                <button @click="save" :disabled="form.processing || !invoice || total <= 0" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('p_returns_create.record_the_return') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
