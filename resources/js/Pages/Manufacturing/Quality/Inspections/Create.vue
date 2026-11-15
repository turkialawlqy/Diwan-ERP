<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ products: Array, plans: Array });
const form = useForm({ product_id: '', qc_plan_id: '', inspected_quantity: '', notes: '' });
useUnsavedGuard(form);
const plans = computed(() => props.plans.filter((p) => !p.product_id || p.product_id === form.product_id));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_quality_inspections_create.new_quality_inspection')" />
        <PageHeader :title="$t('mfg_manufacturing_quality_inspections_create.new_quality_inspection')" :subtitle="$t('mfg_manufacturing_quality_inspections_create.a_manual_inspection_of_purchases')" :crumbs="[{ label: $t('mfg_manufacturing_quality_inspections_create.manufacturing') }, { label: $t('mfg_manufacturing_quality_inspections_create.quality_inspections'), href: route('manufacturing.inspections.index') }, { label: $t('mfg_manufacturing_quality_inspections_create.new') }]" />
        <form @submit.prevent="form.post(route('manufacturing.inspections.store'))" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-3 gap-4">
                <Field :label="$t('mfg_manufacturing_quality_inspections_create.product')" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" search="lookup.products" entity="product" label="name_ar" :placeholder="$t('mfg_manufacturing_quality_inspections_create.choose')" required /></Field>
                <Field :label="$t('mfg_manufacturing_quality_inspections_create.quality_plan')"><select v-model="form.qc_plan_id" :class="input"><option value="">{{ $t('mfg_manufacturing_quality_inspections_create.no_plan') }}</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
                <Field :label="$t('mfg_manufacturing_quality_inspections_create.inspected_quantity')" :error="form.errors.inspected_quantity"><input v-model.number="form.inspected_quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
            </div>
            <Field :label="$t('mfg_manufacturing_quality_inspections_create.notes')"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('mfg_manufacturing_quality_inspections_create.open_the_inspection') }}</button><Link :href="route('manufacturing.inspections.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('mfg_manufacturing_quality_inspections_create.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>
