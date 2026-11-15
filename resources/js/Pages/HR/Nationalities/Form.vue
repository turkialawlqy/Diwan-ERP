<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ nationality: Object });
const editing = props.nationality?.id;
const form = useForm({
    code: props.nationality?.code || '',
    name_ar: props.nationality?.name_ar || '',
    name_en: props.nationality?.name_en || '',
    is_active: props.nationality ? !!props.nationality.is_active : true,
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('hr.nationalities.update', editing)) : form.post(route('hr.nationalities.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('hr2_hr_nationalities_form.edit_nationality') : $t('hr2_hr_nationalities_form.new_nationality')" />
        <PageHeader :title="editing ? $t('hr2_hr_nationalities_form.edit_nationality') : $t('hr2_hr_nationalities_form.new_nationality')" :crumbs="[{ label: $t('hr2_hr_nationalities_form.human_resources') }, { label: $t('hr2_hr_nationalities_form.nationalities'), href: route('hr.nationalities.index') }, { label: editing ? $t('hr2_hr_nationalities_form.edit') : $t('hr2_hr_nationalities_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('hr_nationalities_form.nationality_in_arabic')" :error="form.errors.name_ar"><input v-model="form.name_ar" required :class="input" :placeholder="$t('hr_nationalities_form.egyptian')" /></Field>
            <Field :label="$t('hr_nationalities_form.in_english')" :error="form.errors.name_en"><input v-model="form.name_en" :class="input" placeholder="Egyptian" /></Field>
            <Field :label="$t('hr_nationalities_form.country_code_iso2')" :error="form.errors.code" :hint="$t('hr_nationalities_form.two_letters_like_eg')"><input v-model="form.code" required maxlength="2" :class="input" class="uppercase font-mono" /></Field>
            <label class="flex items-center gap-2 font-bold text-slate-700 pt-6"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('hr_nationalities_form.active_shown_in_pick_lists') }}</label>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ editing ? $t('hr2_hr_nationalities_form.save') : $t('hr2_hr_nationalities_form.add') }}</button>
                <Link :href="route('hr.nationalities.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('hr_nationalities_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
