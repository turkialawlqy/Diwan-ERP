<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ promotion: Object, types: Object, documents: Object, products: Array, categories: Array, groups: Array });
const editing = props.promotion?.id;
const p = props.promotion;
const form = useForm({
    name: p?.name || '',
    type: p?.type || 'percent',
    value: p?.value ?? '',
    tiers: (p?.tiers || []).map((t) => ({ ...t })),
    buy_qty: p?.buy_qty ?? '',
    get_qty: p?.get_qty ?? '',
    product_id: p?.product_id || '',
    category_id: p?.category_id || '',
    customer_group_id: p?.customer_group_id || '',
    documents: p?.documents || ['invoice', 'quotation', 'order'],
    valid_from: p?.valid_from ? String(p.valid_from).substring(0, 10) : '',
    valid_to: p?.valid_to ? String(p.valid_to).substring(0, 10) : '',
    priority: p?.priority ?? 0,
    is_active: p ? !!p.is_active : true,
});
useUnsavedGuard(form);
const submit = () => form.transform((d) => ({
    ...d,
    value: d.value === '' ? null : Number(d.value),
    buy_qty: d.buy_qty === '' ? null : Number(d.buy_qty),
    get_qty: d.get_qty === '' ? null : Number(d.get_qty),
    tiers: d.tiers.map((t) => ({ min_qty: Number(t.min_qty) || 0, value: Number(t.value) || 0 })),
    product_id: d.product_id || null,
    category_id: d.category_id || null,
    customer_group_id: d.customer_group_id || null,
    valid_from: d.valid_from || null,
    valid_to: d.valid_to || null,
})).submit(editing ? 'put' : 'post', editing ? route('sales.promotions.update', editing) : route('sales.promotions.store'));
const toggleDoc = (key) => { form.documents = form.documents.includes(key) ? form.documents.filter((d) => d !== key) : [...form.documents, key]; };
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('s_promo_form.edit') : $t('s_promo_form.new')" />
        <PageHeader :title="editing ? $t('s_promo_form.edit') : $t('s_promo_form.new')" :crumbs="[{ label: 'المبيعات' }, { label: 'العروض والخصومات', href: route('sales.promotions.index') }, { label: editing ? 'تعديل' : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field :label="$t('s_promo_form.promotion_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" /></Field>
            <Field :label="$t('s_promo_form.priority')" :hint="$t('s_promo_form.highest_first_on_a_tie')"><input v-model="form.priority" type="text" inputmode="decimal" data-num :class="input" /></Field>

            <Field :label="$t('s_promo_form.promotion_type')" :error="form.errors.type">
                <select v-model="form.type" :class="input"><option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select>
            </Field>
            <template v-if="form.type !== 'bxgy'">
                <Field :label="form.type === 'percent' ? $t('s_promo_form.pct_no_tiers') : $t('s_promo_form.amt_no_tiers')" :error="form.errors.value"><input v-model="form.value" type="text" inputmode="decimal" data-num :class="input" /></Field>
            </template>
            <template v-else>
                <Field :label="$t('s_promo_form.buy_quantity')" :error="form.errors.buy_qty"><input v-model="form.buy_qty" type="text" inputmode="decimal" data-num :class="input" /></Field>
                <Field :label="$t('s_promo_form.get_free_quantity')" :error="form.errors.get_qty"><input v-model="form.get_qty" type="text" inputmode="decimal" data-num :class="input" /></Field>
            </template>

            <div v-if="form.type !== 'bxgy'" class="md:col-span-3 border border-slate-100 rounded-xl p-3 space-y-2">
                <div class="flex items-center justify-between"><b>{{ $t('s_promo_form.quantity_tiers_optional_the_larg') }}</b><button type="button" @click="form.tiers.push({ min_qty: '', value: '' })" class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">{{ $t('s_promo_form.tier') }}</button></div>
                <div v-for="(t, i) in form.tiers" :key="i" class="grid grid-cols-12 gap-2 items-center">
                    <span class="col-span-2 text-slate-500">{{ $t('s_promo_form.from_quantity') }}</span>
                    <input v-model="t.min_qty" type="text" inputmode="decimal" data-num :class="input" class="col-span-3 font-mono" />
                    <span class="col-span-2 text-slate-500 text-center">{{ $t('s_promo_form.discount') }}</span>
                    <input v-model="t.value" type="text" inputmode="decimal" data-num :class="input" class="col-span-3 font-mono" />
                    <button type="button" @click="form.tiers.splice(i, 1)" class="col-span-2 text-rose-500 font-bold">{{ $t('s_promo_form.delete') }}</button>
                </div>
            </div>

            <Field :label="$t('s_promo_form.specific_item')"><SelectCreate v-model="form.product_id" :options="products" entity="product" :label="(o) => o.name_ar" :placeholder="$t('s_promo_form.all_items')" /></Field>
            <Field :label="$t('s_promo_form.specific_category')"><SelectCreate v-model="form.category_id" :options="categories" entity="category" label="name_ar" :placeholder="$t('s_promo_form.all_categories')" /></Field>
            <Field :label="$t('s_promo_form.customer_group')"><SelectCreate v-model="form.customer_group_id" :options="groups" entity="customer_group" label="name" :placeholder="$t('s_promo_form.all_customers')" /></Field>

            <Field :label="$t('s_promo_form.from_date')"><DatePicker v-model="form.valid_from" :class="input" /></Field>
            <Field :label="$t('s_promo_form.to_date')" :error="form.errors.valid_to"><DatePicker v-model="form.valid_to" :class="input" /></Field>
            <div>
                <div class="font-bold text-slate-700 mb-1.5">{{ $t('s_promo_form.applies_to') }}</div>
                <div class="flex flex-wrap gap-3">
                    <label v-for="(label, key) in documents" :key="key" class="flex items-center gap-1.5"><input type="checkbox" :checked="form.documents.includes(key)" @change="toggleDoc(key)" class="rounded" /> {{ label }}</label>
                </div>
                <p v-if="form.errors.documents" class="text-rose-600 mt-1">{{ form.errors.documents }}</p>
            </div>

            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('s_promo_form.the_promotion_is_active') }}</label>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ editing ? $t('s_common.save') : $t('s_common.add') }}</button>
                <Link :href="route('sales.promotions.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('s_promo_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
