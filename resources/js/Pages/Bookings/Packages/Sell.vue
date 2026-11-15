<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today, money } from '@/utils/format';

const props = defineProps({ templates: Array, customerId: Number, customer: Object });
const form = useForm({ customer_id: props.customerId || null, visit_package_id: '', paid: false, date: today() });
useUnsavedGuard(form);
const chosen = computed(() => props.templates.find((t) => t.id === form.visit_package_id));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('bk2_bookings_packages_sell.sell_a_visit_package')" />
        <PageHeader :title="$t('bk2_bookings_packages_sell.sell_a_visit_package')" :crumbs="[{ label: $t('bk2_bookings_packages_sell.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_packages_sell.packages'), href: route('bookings.packages.index') }, { label: $t('bk2_bookings_packages_sell.sell') }]" />
        <form @submit.prevent="form.post(route('bookings.customer-packages.store'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('bk2_bookings_packages_sell.customer')" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" :initial="customer" /></Field>
            <Field :label="$t('bk2_bookings_packages_sell.package')" :error="form.errors.visit_package_id"><SelectCreate v-model="form.visit_package_id" required :options="templates" entity="visit_package" :label="(t) => $t('bk2_bookings_packages_sell.n_n_visits_n', { n0: t.name, n1: t.visits, n2: money(t.price) })" :placeholder="$t('bk2_bookings_packages_sell.choose_the_package')" data-package-select /></Field>
            <Field :label="$t('bk2_bookings_packages_sell.sale_date')" :error="form.errors.date"><DatePicker v-model="form.date" /></Field>
            <label class="flex items-center gap-2 pt-5"><input v-model="form.paid" type="checkbox" class="rounded border-slate-300" data-paid /> {{ $t('bk2_bookings_packages_sell.paid_in_cash_now_otherwise_a_cre') }}</label>
            <p v-if="chosen" class="md:col-span-2 text-slate-500">{{ $t('bk2_bookings_packages_sell.an_invoice_is_issued_for') }} <span class="font-mono font-bold">{{ money(chosen.price) }}</span> {{ $t('bk2_bookings_packages_sell.n_tax', { n0: chosen.tax_rate }) }}<span v-if="chosen.valid_days"> {{ $t('bk2_bookings_packages_sell.and_the_package_expires_after_n', { n0: chosen.valid_days }) }}</span>.</p>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-confirm-sell>{{ $t('bk2_bookings_packages_sell.sell_the_package') }}</button><Link :href="route('bookings.packages.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('bk2_bookings_packages_sell.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>
