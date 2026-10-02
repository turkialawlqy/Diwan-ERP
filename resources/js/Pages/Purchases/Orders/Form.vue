<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import LineItemsEditor from '@/Components/Documents/LineItemsEditor.vue';

const props = defineProps({ order: Object, warehouses: Array, suggestedNumber: String });
const o = props.order;
const form = useForm({
    supplier_id: o?.supplier_id ?? null,
    warehouse_id: o?.warehouse_id ?? '',
    order_date: o?.order_date?.substring(0, 10) ?? new Date().toISOString().substring(0, 10),
    expected_delivery_date: o?.expected_delivery_date?.substring(0, 10) ?? '',
    notes: o?.notes ?? '',
    terms: o?.terms ?? '',
    submit: false,
    items: (o?.items ?? []).map((i) => ({ product_id: i.product_id, item_name: i.item_name, quantity: Number(i.quantity), unit_price: Number(i.unit_price), discount: Number(i.discount), tax_rate: Number(i.tax_rate) })),
});
const save = (submit) => {
    form.submit = submit;
    o ? form.put(route('purchases.orders.update', o.id)) : form.post(route('purchases.orders.store'));
};
</script>

<template>
    <AppLayout>
        <Head :title="o ? `تعديل ${o.order_number}` : 'أمر شراء جديد'" />
        <PageHeader :title="o ? `تعديل أمر الشراء ${o.order_number}` : 'أمر شراء جديد'" :subtitle="o ? '' : `الرقم المتوقع: ${suggestedNumber}`" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء', href: route('purchases.orders.index') }, { label: o ? o.order_number : 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="المورد" :error="form.errors.supplier_id" class="md:col-span-2"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" :initial="o?.supplier" /></Field>
                <Field label="تاريخ الأمر"><input v-model="form.order_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="التوريد المتوقع"><input v-model="form.expected_delivery_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مستودع الاستلام"><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="ملاحظات" class="md:col-span-3"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الشروط والأحكام" class="md:col-span-4"><textarea v-model="form.terms" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <LineItemsEditor v-model="form.items" price-field="purchase_price" />
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-end gap-2">
                <button @click="save(false)" :disabled="form.processing || !form.items.length || !form.supplier_id" class="px-5 py-2 rounded-xl border border-slate-200 text-xs font-bold disabled:opacity-40">حفظ كمسودة</button>
                <button @click="save(true)" :disabled="form.processing || !form.items.length || !form.supplier_id" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">حفظ وإرسال للاعتماد</button>
            </div>
        </div>
    </AppLayout>
</template>
