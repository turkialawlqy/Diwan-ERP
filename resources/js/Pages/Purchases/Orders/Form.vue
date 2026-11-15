<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import LineItemsEditor from '@/Components/Documents/LineItemsEditor.vue';
import { today } from '@/utils/format';

const props = defineProps({ order: Object, warehouses: Array, suggestedNumber: String, branches: Array, defaultBranchId: Number });
const o = props.order;
const form = useForm({
    supplier_id: o?.supplier_id ?? null,
    branch_id: o?.branch_id ?? (props.defaultBranchId || props.branches?.[0]?.id || ''),
    warehouse_id: o?.warehouse_id ?? '',
    order_date: o?.order_date?.substring(0, 10) ?? today(),
    expected_delivery_date: o?.expected_delivery_date?.substring(0, 10) ?? '',
    notes: o?.notes ?? '',
    terms: o?.terms ?? '',
    items: (o?.items ?? []).map((i) => ({ product_id: i.product_id, item_name: i.item_name, quantity: Number(i.quantity), unit_price: Number(i.unit_price), discount: Number(i.discount), tax_rate: Number(i.tax_rate) })),
});
useUnsavedGuard(form);
// "submit" is sent through transform(): as a form field it would shadow
// Inertia's own form.submit() and post()/put() would silently do nothing.
const save = (submit) => {
    const request = form.transform((data) => ({ ...data, submit }));
    o ? request.put(route('purchases.orders.update', o.id)) : request.post(route('purchases.orders.store'));
};
</script>

<template>
    <AppLayout>
        <Head :title="o ? `${$t('p_orders_form.edit')} ${o.order_number}` : $t('p_orders_form.new')" />
        <PageHeader :title="o ? `تعديل أمر الشراء ${o.order_number}` : 'أمر شراء جديد'" :subtitle="o ? '' : `الرقم المتوقع: ${suggestedNumber}`" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء', href: route('purchases.orders.index') }, { label: o ? o.order_number : 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('p_orders_form.supplier')" :error="form.errors.supplier_id" class="md:col-span-2"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" :initial="o?.supplier" /></Field>
                <Field :label="$t('p_orders_form.order_date')"><DatePicker v-model="form.order_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_orders_form.expected_supply')"><DatePicker v-model="form.expected_delivery_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_orders_form.receiving_warehouse')"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('p_orders_form.default')" /></Field>
                <Field :label="$t('p_orders_form.notes')" class="md:col-span-3"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('p_orders_form.terms_and_conditions')" class="md:col-span-4"><textarea v-model="form.terms" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <LineItemsEditor v-model="form.items" price-field="purchase_price" />
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-end gap-2">
                <button @click="save(false)" :disabled="form.processing || !form.items.length || !form.supplier_id" class="px-5 py-2 rounded-xl border border-slate-200 text-xs font-bold disabled:opacity-40">{{ $t('p_orders_form.save_as_draft') }}</button>
                <button @click="save(true)" :disabled="form.processing || !form.items.length || !form.supplier_id" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('p_orders_form.save_and_send_for_approval') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
