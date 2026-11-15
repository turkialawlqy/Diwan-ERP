<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ register: Object, warehouses: Array, boxes: Array });

const editing = !!props.register?.id;
const form = useForm({
    name: props.register?.name || '',
    code: props.register?.code || '',
    warehouse_id: props.register?.warehouse_id || props.warehouses?.[0]?.id || '',
    bank_account_id: props.register?.bank_account_id || '',
    is_active: props.register?.is_active ?? true,
});
useUnsavedGuard(form);

const submit = () => (editing ? form.put(route('pos.registers.update', props.register.id)) : form.post(route('pos.registers.store')));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('pos_registers_form.edit') : $t('pos_registers_index.add_device')" />
        <PageHeader :title="editing ? 'تعديل جهاز كاشير' : 'إضافة جهاز كاشير'" :crumbs="[{ label: 'نقاط البيع' }, { label: 'الأجهزة', href: route('pos.registers.index') }, { label: editing ? 'تعديل' : 'إضافة' }]" />

        <form @submit.prevent="submit" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field :label="$t('pos_registers_form.device_name')" :error="form.errors.name"><input v-model="form.name" :class="input" :placeholder="$t('pos_registers_form.cashier_1')" required /></Field>
                <Field :label="$t('pos_registers_form.code')" :error="form.errors.code"><input v-model="form.code" :class="input" class="font-mono" placeholder="POS-01" /></Field>
                <Field :label="$t('pos_registers_form.warehouse_stock_is_deducted_from')" :error="form.errors.warehouse_id">
                    <SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required />
                </Field>
                <Field :label="$t('pos_registers_form.cash_box')" :hint="$t('pos_registers_form.the_cash_box_that_receives_cash')" :error="form.errors.bank_account_id">
                    <SelectCreate v-model="form.bank_account_id" :options="boxes" entity="bank" label="name" :placeholder="$t('pos_registers_form.default_cash_box')" />
                </Field>
            </div>
            <label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded" /> {{ $t('pos_registers_form.the_device_is_active') }}</label>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('pos_registers_form.save') }}</button>
                <Link :href="route('pos.registers.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('pos_registers_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
