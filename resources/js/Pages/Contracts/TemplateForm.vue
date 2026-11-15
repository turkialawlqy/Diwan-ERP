<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ template: Object, kinds: Object });
const editing = props.template?.id;
const form = useForm({ name: props.template?.name || '', kind: props.template?.kind || 'service', body: props.template?.body || '', is_active: props.template ? !!props.template.is_active : true });
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('contracts.templates.update', editing)) : form.post(route('contracts.templates.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('con2_contracts_templateform.edit_a_template') : $t('con2_contracts_templateform.new_contract_template')" />
        <PageHeader :title="editing ? $t('con2_contracts_templateform.edit_a_template') : $t('con2_contracts_templateform.new_contract_template')" :crumbs="[{ label: $t('con2_contracts_templateform.contracts'), href: route('contracts.index') }, { label: $t('con2_contracts_templateform.templates'), href: route('contracts.templates.index') }, { label: editing ? $t('con2_contracts_templateform.edit') : $t('con2_contracts_templateform.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('con2_contracts_templateform.template_name')" :error="form.errors.name"><input v-model="form.name" required maxlength="255" :class="input" data-template-name /></Field>
            <Field :label="$t('con2_contracts_templateform.type')" :error="form.errors.kind"><select v-model="form.kind" :class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field :label="$t('con2_contracts_templateform.template_text')" :error="form.errors.body" class="md:col-span-2"><textarea v-model="form.body" required rows="16" :class="input" class="leading-relaxed" data-template-body></textarea></Field>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('con2_contracts_templateform.active_template') }}</label>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-template>{{ editing ? $t('con2_contracts_templateform.save') : $t('con2_contracts_templateform.add') }}</button>
                <Link :href="route('contracts.templates.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('con2_contracts_templateform.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
