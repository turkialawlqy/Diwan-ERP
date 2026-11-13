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

const props = defineProps({ warehouses: Array, suggestedNumber: String, branches: Array, defaultBranchId: Number });
const form = useForm({ customer_id: null, branch_id: props.defaultBranchId || props.branches?.[0]?.id || '', warehouse_id: '', order_date: today(), delivery_date: '', notes: '', items: [] });
useUnsavedGuard(form);
const save = () => form.post(route('sales.orders.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_order_form.new_sales_order')" />
        <PageHeader :title="$t('s_order_form.new_sales_order')" :subtitle="$t('s_order_form.expected_number', { n: suggestedNumber })" :crumbs="[{ label: 'المبيعات' }, { label: 'أوامر البيع', href: route('sales.orders.index') }, { label: 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('s_order_form.customer')" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
                <Field :label="$t('s_order_form.order_date')" :error="form.errors.order_date"><DatePicker v-model="form.order_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_order_form.expected_delivery_date')" :error="form.errors.delivery_date"><DatePicker v-model="form.delivery_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_order_form.warehouse')"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('s_order_form.default')" /></Field>
                <Field :label="$t('s_order_form.notes')" class="md:col-span-3"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <LineItemsEditor v-model="form.items" :customer-id="form.customer_id" price-route="lookup.price" />
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing || !form.items.length || !form.customer_id" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('s_order_form.save_sales_order') }}</button></div>
        </div>
    </AppLayout>
</template>
