<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import ProductImageField from '@/Components/Products/ProductImageField.vue';
import VatFields from '@/Components/Products/VatFields.vue';

const props = defineProps({ product: Object, imageUrl: String, categories: Array, units: Array, vatCategories: Object, exemptionReasons: Object });
const p = props.product;
const form = useForm({
    name_ar: p.name_ar, name_en: p.name_en || '', category_id: p.category_id || '', unit_id: p.unit_id || '', sku: p.sku || '', barcode: p.barcode || '',
    description: p.description || '', purchase_price: p.purchase_price, selling_price: p.selling_price, tax_rate: p.tax_rate, min_stock_alert: p.min_stock_alert,
    track_inventory: !!p.track_inventory, type: p.type, tracking: p.tracking || 'none', reorder_level: p.reorder_level, reorder_quantity: p.reorder_quantity,
    vat_category: p.vat_category || 'S', vat_exemption_code: p.vat_exemption_code || '',
});
useUnsavedGuard(form);
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('i_products_edit.title')} ${product.name_ar}`" />
        <PageHeader :title="`تعديل: ${product.name_ar}`" :crumbs="[{ label: 'المستودعات' }, { label: 'الأصناف', href: route('inventory.products.index') }, { label: product.name_ar, href: route('inventory.products.show', product.id) }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('i_products_edit.name_in_arabic')" :error="form.errors.name_ar" class="md:col-span-2"><input v-model="form.name_ar" :class="inp" /></Field>
                <Field :label="$t('i_products_edit.name_in_english')" class="md:col-span-2"><input v-model="form.name_en" :class="inp" /></Field>
                <Field :label="$t('i_products_edit.code_sku')"><input v-model="form.sku" :class="inp" class="font-mono" /></Field>
                <Field :label="$t('i_products_edit.barcode')"><input v-model="form.barcode" :class="inp" class="font-mono" /></Field>
                <Field :label="$t('i_products_edit.category')"><SelectCreate v-model="form.category_id" :options="categories" entity="category" label="name_ar" placeholder="—" /></Field>
                <Field :label="$t('i_products_edit.base_unit')"><SelectCreate v-model="form.unit_id" :options="units" entity="unit" label="name_ar" placeholder="—" /></Field>
                <Field :label="$t('i_products_edit.type')"><select v-model="form.type" :class="inp"><option value="product">{{ $t('i_products_edit.stock_item') }}</option><option value="service">{{ $t('i_products_edit.service') }}</option></select></Field>
                <Field :label="$t('i_products_edit.purchase_price')" :error="form.errors.purchase_price"><input v-model.number="form.purchase_price" type="text" inputmode="decimal" data-num step="0.01" :class="inp" /></Field>
                <Field :label="$t('i_products_edit.selling_price')" :error="form.errors.selling_price"><input v-model.number="form.selling_price" type="text" inputmode="decimal" data-num step="0.01" :class="inp" /></Field>
                <Field :label="$t('i_products_edit.tax_rate')"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num step="0.01" :disabled="form.vat_category !== 'S'" :class="inp" /></Field>
                <Field :label="$t('i_products_edit.tracking')" :error="form.errors.tracking"><select v-model="form.tracking" :class="inp"><option value="none">{{ $t('i_products_edit.none') }}</option><option value="batch">{{ $t('i_products_edit.batches_and_expiry_dates') }}</option><option value="serial">{{ $t('i_products_edit.serial_numbers') }}</option></select></Field>
                <Field :label="$t('i_products_edit.low_quantity_alert')"><input v-model.number="form.min_stock_alert" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <Field :label="$t('i_products_edit.reorder_level')"><input v-model.number="form.reorder_level" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <Field :label="$t('i_products_edit.reorder_quantity')"><input v-model.number="form.reorder_quantity" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <label class="flex items-center gap-2 pt-5"><input v-model="form.track_inventory" type="checkbox" class="rounded" /> {{ $t('i_products_edit.track_inventory') }}</label>
            </div>
            <Field :label="$t('i_products_edit.item_image_shown_at_the_cashier')"><ProductImageField :product-id="product.id" :current="imageUrl" /></Field>
            <VatFields v-model:category="form.vat_category" v-model:code="form.vat_exemption_code" v-model:tax-rate="form.tax_rate" :categories="vatCategories" :reasons="exemptionReasons" :errors="form.errors" />
            <Field :label="$t('i_products_edit.description')"><textarea v-model="form.description" rows="2" :class="inp"></textarea></Field>
            <div class="flex justify-end gap-2">
                <Link :href="route('inventory.products.show', product.id)" class="px-5 py-2 rounded-xl border border-slate-200 font-bold">{{ $t('i_products_edit.cancel') }}</Link>
                <button @click="form.put(route('inventory.products.update', product.id))" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('ui.save') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
