<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ template: Object, services: Array, defaultTax: Number });
const t = props.template;
const form = useForm({ name: t?.name || '', bookable_service_id: t?.bookable_service_id || '', visits: t?.visits || 10, price: t ? Number(t.price) : 0, tax_rate: t ? Number(t.tax_rate) : props.defaultTax, valid_days: t?.valid_days || '', is_active: t ? !!t.is_active : true });
useUnsavedGuard(form);
const submit = () => (t ? form.put(route('bookings.packages.update', t.id)) : form.post(route('bookings.packages.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="t ? $t('bk2_bookings_packages_form.edit_n', { n0: t.name }) : $t('bk2_bookings_packages_form.new_package')" />
        <PageHeader :title="t ? $t('bk2_bookings_packages_form.edit_package_n', { n0: t.name }) : $t('bk2_bookings_packages_form.new_visit_package')" :crumbs="[{ label: $t('bk2_bookings_packages_form.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_packages_form.packages'), href: route('bookings.packages.index') }, { label: t ? t.name : $t('bk2_bookings_packages_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field :label="$t('bk2_bookings_packages_form.package_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" data-package-name /></Field>
            <Field :label="$t('bk2_bookings_packages_form.service')" :error="form.errors.bookable_service_id" :hint="$t('bk2_bookings_packages_form.empty_valid_for_any_service')"><SelectCreate v-model="form.bookable_service_id" :options="services" entity="bookable_service" :label="(s) => s.name" :placeholder="$t('bk2_bookings_packages_form.any_service')" /></Field>
            <Field :label="$t('bk2_bookings_packages_form.number_of_visits')" :error="form.errors.visits"><input v-model.number="form.visits" type="text" inputmode="decimal" data-num :class="input" data-visits /></Field>
            <Field :label="$t('bk2_bookings_packages_form.price')" :error="form.errors.price"><input v-model.number="form.price" type="text" inputmode="decimal" data-num :class="input" data-package-price /></Field>
            <Field :label="$t('bk2_bookings_packages_form.tax_rate')" :error="form.errors.tax_rate"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('bk2_bookings_packages_form.validity_days')" :error="form.errors.valid_days" :hint="$t('bk2_bookings_packages_form.empty_no_expiry')"><input v-model.number="form.valid_days" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <label class="flex items-center gap-2 pt-5"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> {{ $t('bk2_bookings_packages_form.active_for_sale') }}</label>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-package>{{ $t('bk2_bookings_packages_form.save') }}</button><Link :href="route('bookings.packages.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('bk2_bookings_packages_form.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>
