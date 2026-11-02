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
        <Head :title="`تعديل ${product.name_ar}`" />
        <PageHeader :title="`تعديل: ${product.name_ar}`" :crumbs="[{ label: 'المستودعات' }, { label: 'الأصناف', href: route('inventory.products.index') }, { label: product.name_ar, href: route('inventory.products.show', product.id) }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field label="الاسم بالعربية" :error="form.errors.name_ar" class="md:col-span-2"><input v-model="form.name_ar" :class="inp" /></Field>
                <Field label="الاسم بالإنجليزية" class="md:col-span-2"><input v-model="form.name_en" :class="inp" /></Field>
                <Field label="الرمز (SKU)"><input v-model="form.sku" :class="inp" class="font-mono" /></Field>
                <Field label="الباركود"><input v-model="form.barcode" :class="inp" class="font-mono" /></Field>
                <Field label="التصنيف"><SelectCreate v-model="form.category_id" :options="categories" entity="category" label="name_ar" placeholder="—" /></Field>
                <Field label="الوحدة الأساسية"><SelectCreate v-model="form.unit_id" :options="units" entity="unit" label="name_ar" placeholder="—" /></Field>
                <Field label="النوع"><select v-model="form.type" :class="inp"><option value="product">صنف مخزني</option><option value="service">خدمة</option></select></Field>
                <Field label="سعر الشراء" :error="form.errors.purchase_price"><input v-model.number="form.purchase_price" type="text" inputmode="decimal" data-num step="0.01" :class="inp" /></Field>
                <Field label="سعر البيع" :error="form.errors.selling_price"><input v-model.number="form.selling_price" type="text" inputmode="decimal" data-num step="0.01" :class="inp" /></Field>
                <Field label="نسبة الضريبة %"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num step="0.01" :disabled="form.vat_category !== 'S'" :class="inp" /></Field>
                <Field label="التتبع" :error="form.errors.tracking"><select v-model="form.tracking" :class="inp"><option value="none">بدون</option><option value="batch">دفعات وتواريخ انتهاء</option><option value="serial">أرقام تسلسلية</option></select></Field>
                <Field label="تنبيه انخفاض الكمية"><input v-model.number="form.min_stock_alert" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <Field label="حد إعادة الطلب"><input v-model.number="form.reorder_level" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <Field label="كمية إعادة الطلب"><input v-model.number="form.reorder_quantity" type="text" inputmode="decimal" data-num :class="inp" /></Field>
                <label class="flex items-center gap-2 pt-5"><input v-model="form.track_inventory" type="checkbox" class="rounded" /> تتبع المخزون</label>
            </div>
            <Field label="صورة الصنف (تظهر في الكاشير)"><ProductImageField :product-id="product.id" :current="imageUrl" /></Field>
            <VatFields v-model:category="form.vat_category" v-model:code="form.vat_exemption_code" v-model:tax-rate="form.tax_rate" :categories="vatCategories" :reasons="exemptionReasons" :errors="form.errors" />
            <Field label="الوصف"><textarea v-model="form.description" rows="2" :class="inp"></textarea></Field>
            <div class="flex justify-end gap-2">
                <Link :href="route('inventory.products.show', product.id)" class="px-5 py-2 rounded-xl border border-slate-200 font-bold">إلغاء</Link>
                <button @click="form.put(route('inventory.products.update', product.id))" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('ui.save') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
