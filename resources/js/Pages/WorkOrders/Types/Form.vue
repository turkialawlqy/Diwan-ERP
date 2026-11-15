<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ type: Object, products: Array, defaultTax: Number });
const t = props.type;
const form = useForm({ name: t?.name || '', hourly_rate: t ? Number(t.hourly_rate) : 0, tax_rate: t ? Number(t.tax_rate) : props.defaultTax, product_id: t?.product_id || '', min_billable_minutes: t?.min_billable_minutes || 0, is_active: t ? !!t.is_active : true });
useUnsavedGuard(form);
const submit = () => (t ? form.put(route('work-orders.types.update', t.id)) : form.post(route('work-orders.types.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="t ? $t('wo2_workorders_types_form.edit_n', { n0: t.name }) : $t('wo2_workorders_types_form.new_job_type')" />
        <PageHeader :title="t ? $t('wo2_workorders_types_form.edit_n_2', { n0: t.name }) : $t('wo2_workorders_types_form.new_job_type')" :crumbs="[{ label: $t('wo2_workorders_types_form.work_orders'), href: route('work-orders.index') }, { label: $t('wo2_workorders_types_form.types'), href: route('work-orders.types.index') }, { label: t ? t.name : $t('wo2_workorders_types_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field :label="$t('wo2_workorders_types_form.name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" data-type-name /></Field>
            <Field :label="$t('wo2_workorders_types_form.hourly_rate_for_the_customer')" :error="form.errors.hourly_rate"><input v-model.number="form.hourly_rate" type="text" inputmode="decimal" data-num :class="input" data-rate /></Field>
            <Field :label="$t('wo2_workorders_types_form.tax_rate')" :error="form.errors.tax_rate"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('wo2_workorders_types_form.minimum_billed_duration_min')" :error="form.errors.min_billable_minutes" :hint="$t('wo2_workorders_types_form.e_g_60_any_shorter_visit_is_bill')"><input v-model.number="form.min_billable_minutes" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('wo2_workorders_types_form.revenue_item_optional')" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :label="(p) => p.name_ar" :placeholder="$t('wo2_workorders_types_form.none')" /></Field>
            <label class="flex items-center gap-2 pt-5"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> {{ $t('wo2_workorders_types_form.active') }}</label>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-type>{{ $t('wo2_workorders_types_form.save') }}</button><Link :href="route('work-orders.types.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('wo2_workorders_types_form.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>
