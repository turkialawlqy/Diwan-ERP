<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ settings: Object });
const form = useForm({ ...props.settings });
useUnsavedGuard(form);
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('bk2_bookings_settings.booking_settings')" />
        <PageHeader :title="$t('bk2_bookings_settings.booking_settings')" :crumbs="[{ label: $t('bk2_bookings_settings.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_settings.settings') }]" />
        <form @submit.prevent="form.put(route('bookings.settings.update'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('bk2_bookings_settings.time_step_min')" :error="form.errors.slot_step" :hint="$t('bk2_bookings_settings.the_gap_between_the_start_times')"><input v-model.number="form.slot_step" type="text" inputmode="decimal" data-num :class="input" data-step /></Field>
            <Field :label="$t('bk2_bookings_settings.minimum_notice_before_the_appoin')" :error="form.errors.min_notice_hours" :hint="$t('bk2_bookings_settings.applies_to_customers_portal_book')"><input v-model.number="form.min_notice_hours" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('bk2_bookings_settings.maximum_advance_booking_days')" :error="form.errors.max_advance_days"><input v-model.number="form.max_advance_days" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('bk2_bookings_settings.deposit_on_no_show_or_late_cance')" :error="form.errors.deposit_on_cancel" :hint="$t('bk2_bookings_settings.it_stays_as_a_customer_credit_to')"><select v-model="form.deposit_on_cancel" :class="input" data-deposit-on-cancel><option value="credit">{{ $t('bk2_bookings_settings.stays_as_a_customer_credit') }}</option><option value="forfeit">{{ $t('bk2_bookings_settings.forfeited_other_revenue') }}</option></select></Field>
            <Field :label="$t('bk2_bookings_settings.reminder_hours_before_the_appoin')" :error="form.errors.reminder_hours" :hint="$t('bk2_bookings_settings.comma_separated_numbers_like_24')"><input v-model="form.reminder_hours" :class="input" dir="ltr" /></Field>
            <label class="md:col-span-2 flex items-center gap-2"><input v-model="form.auto_confirm" type="checkbox" class="rounded border-slate-300" /> {{ $t('bk2_bookings_settings.confirm_portal_bookings_automati') }}</label>
            <div class="md:col-span-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-settings>{{ $t('bk2_bookings_settings.save') }}</button></div>
        </form>
    </AppLayout>
</template>
