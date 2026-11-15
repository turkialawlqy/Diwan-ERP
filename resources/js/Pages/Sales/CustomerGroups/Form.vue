<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ group: Object, accounts: Array });
const editing = props.group?.id;
const form = useForm({
    code: props.group?.code || '',
    name: props.group?.name || '',
    description: props.group?.description || '',
    account_id: props.group?.account_id || '',
    is_active: props.group ? !!props.group.is_active : true,
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('sales.customer-groups.update', editing)) : form.post(route('sales.customer-groups.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('s_group_form.edit') : $t('s_group_form.new')" />
        <PageHeader :title="editing ? $t('s_group_form.edit') : $t('s_group_form.new')" :crumbs="[{ label: 'المبيعات' }, { label: 'مجموعات العملاء', href: route('sales.customer-groups.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('s_group_form.group_code')" :error="form.errors.code"><input v-model="form.code" required maxlength="30" :class="input" class="font-mono" placeholder="CG001" /></Field>
            <Field :label="$t('s_group_form.group_name')" :error="form.errors.name"><input v-model="form.name" required :class="input" /></Field>
            <Field :label="$t('s_group_form.linked_ledger_account')" :error="form.errors.account_id" :hint="$t('s_group_form.receivables_of_this_group_s_cust')">
                <SelectCreate v-model="form.account_id" :options="accounts" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :placeholder="$t('s_group_form.general_receivables')" />
            </Field>
            <label class="flex items-center gap-2 font-bold text-slate-700 pt-6"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('s_group_form.the_group_is_active') }}</label>
            <Field :label="$t('s_group_form.description')" :error="form.errors.description" class="md:col-span-2"><input v-model="form.description" maxlength="500" :class="input" /></Field>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ editing ? $t('s_common.save') : $t('s_common.add') }}</button>
                <Link :href="route('sales.customer-groups.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('s_group_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
