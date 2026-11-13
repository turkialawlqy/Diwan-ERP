<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { today } from '@/utils/format';

const props = defineProps({ suppliers: Array, products: Array, boms: Array, warehouses: Array });
const form = useForm({
    supplier_id: '', product_id: '', bom_id: '', quantity: 1, service_price: 0, source_warehouse_id: props.warehouses?.[0]?.id || '', vendor_warehouse_id: '', output_warehouse_id: props.warehouses?.[0]?.id || '',
    order_date: today(), expected_date: '', notes: '',
});
useUnsavedGuard(form);
const productBoms = computed(() => props.boms.filter((b) => b.product_id === form.product_id));
watch(() => form.product_id, () => { form.bom_id = productBoms.value.find((b) => b.is_default)?.id || productBoms.value[0]?.id || ''; });
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head title="أمر تصنيع لدى الغير" />
        <PageHeader title="أمر تصنيع لدى الغير" :crumbs="[{ label: 'التصنيع' }, { label: 'التصنيع لدى الغير', href: route('manufacturing.subcontracts.index') }, { label: 'جديد' }]" />
        <form @submit.prevent="form.post(route('manufacturing.subcontracts.store'))" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field label="المصنّع (المورد) *" :error="form.errors.supplier_id"><SelectCreate v-model="form.supplier_id" :options="suppliers" :search="{ route: 'lookup.suppliers', params: { active: 1 } }" entity="supplier" label="name" placeholder="— اختر —" required /></Field>
                <Field label="المنتج المطلوب *" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :search="{ route: 'lookup.products', params: { stockable: 1 } }" entity="product" label="name_ar" placeholder="— اختر —" required /></Field>
                <Field label="قائمة المواد (الخامات المرسلة)"><select v-model="form.bom_id" :class="input"><option value="">— بلا مواد —</option><option v-for="b in productBoms" :key="b.id" :value="b.id">{{ b.name }} (إصدار {{ b.version }})</option></select></Field>
                <Field label="الكمية *" :error="form.errors.quantity"><input v-model.number="form.quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                <Field label="أجر التصنيع للوحدة *" :error="form.errors.service_price"><input v-model.number="form.service_price" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                <Field label="مستودع صرف الخامات *"><SelectCreate v-model="form.source_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
                <Field label="مستودع «عند المصنّع» *" hint="مستودع يمثل ما في حوزة المصنّع" :error="form.errors.vendor_warehouse_id"><SelectCreate v-model="form.vendor_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" placeholder="— اختر —" required /></Field>
                <Field label="مستودع المنتج التام *"><SelectCreate v-model="form.output_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
                <Field label="تاريخ الأمر *"><DatePicker v-model="form.order_date" :class="input" /></Field>
                <Field label="موعد التسليم" :error="form.errors.expected_date"><DatePicker v-model="form.expected_date" :class="input" /></Field>
            </div>
            <Field label="ملاحظات"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">إنشاء الأمر</button><Link :href="route('manufacturing.subcontracts.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
