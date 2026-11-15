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
        <Head :title="$t('mfg_manufacturing_subcontracts_create.subcontract_manufacturing_order')" />
        <PageHeader :title="$t('mfg_manufacturing_subcontracts_create.subcontract_manufacturing_order')" :crumbs="[{ label: $t('mfg_manufacturing_subcontracts_create.manufacturing') }, { label: $t('mfg_manufacturing_subcontracts_create.subcontract_manufacturing'), href: route('manufacturing.subcontracts.index') }, { label: $t('mfg_manufacturing_subcontracts_create.new') }]" />
        <form @submit.prevent="form.post(route('manufacturing.subcontracts.store'))" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field :label="$t('mfg_manufacturing_subcontracts_create.manufacturer_supplier')" :error="form.errors.supplier_id"><SelectCreate v-model="form.supplier_id" :options="suppliers" :search="{ route: 'lookup.suppliers', params: { active: 1 } }" entity="supplier" label="name" :placeholder="$t('mfg_manufacturing_subcontracts_create.choose')" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.required_product')" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :search="{ route: 'lookup.products', params: { stockable: 1 } }" entity="product" label="name_ar" :placeholder="$t('mfg_manufacturing_subcontracts_create.choose')" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.bill_of_materials_raw_materials')"><select v-model="form.bom_id" :class="input"><option value="">{{ $t('mfg_manufacturing_subcontracts_create.no_materials') }}</option><option v-for="b in productBoms" :key="b.id" :value="b.id">{{ $t('mfg_manufacturing_subcontracts_create.n_version_n', { n0: b.name, n1: b.version }) }}</option></select></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.quantity')" :error="form.errors.quantity"><input v-model.number="form.quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.manufacturing_fee_per_unit')" :error="form.errors.service_price"><input v-model.number="form.service_price" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.raw_materials_issue_warehouse')"><SelectCreate v-model="form.source_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.at_the_manufacturer_warehouse')" :hint="$t('mfg_manufacturing_subcontracts_create.a_warehouse_representing_what_th')" :error="form.errors.vendor_warehouse_id"><SelectCreate v-model="form.vendor_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('mfg_manufacturing_subcontracts_create.choose')" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.finished_goods_warehouse')"><SelectCreate v-model="form.output_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.order_date')"><DatePicker v-model="form.order_date" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_subcontracts_create.delivery_date')" :error="form.errors.expected_date"><DatePicker v-model="form.expected_date" :class="input" /></Field>
            </div>
            <Field :label="$t('mfg_manufacturing_subcontracts_create.notes')"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('mfg_manufacturing_subcontracts_create.create_the_order') }}</button><Link :href="route('manufacturing.subcontracts.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('mfg_manufacturing_subcontracts_create.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>
