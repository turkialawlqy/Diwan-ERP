<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SlotPicker from '@/Components/Bookings/SlotPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money } from '@/utils/format';

const props = defineProps({ booking: Object, prefill: Object, services: Array, resources: Array });
const b = props.booking;
const pre = props.prefill || {};
const [preDate = '', preTime = ''] = (b ? b.starts_at.replace('T', ' ').slice(0, 16) : (pre.starts_at || '')).split(' ');

const form = useForm({
    customer_id: b?.customer_id || (pre.customer ? Number(pre.customer) : null),
    bookable_service_id: b?.bookable_service_id || (pre.service ? Number(pre.service) : ''),
    booking_resource_id: b?.booking_resource_id || (pre.resource ? Number(pre.resource) : ''),
    date: preDate, time: preTime, deposit_amount: b ? Number(b.deposit_amount) : 0, deposit_method: 'cash', notes: b?.notes || '', starts_at: '',
});
useUnsavedGuard(form);

const service = computed(() => props.services.find((s) => s.id === form.bookable_service_id));
const serviceResources = computed(() => (service.value ? props.resources.filter((r) => service.value.resource_ids.includes(r.id)) : []));
watch(() => form.bookable_service_id, () => {
    if (form.booking_resource_id && !serviceResources.value.some((r) => r.id === form.booking_resource_id)) form.booking_resource_id = '';
    form.time = '';
    if (!b && service.value) form.deposit_amount = Math.round(Number(service.value.price) * service.value.deposit_percent) / 100;
});
const onPicked = (s) => { if (!form.booking_resource_id && !b) pickedResource.value = s.resource_id; };
const pickedResource = ref(null);
const resourceName = computed(() => props.resources.find((r) => r.id === (form.booking_resource_id || pickedResource.value))?.name);

const submit = () => {
    form.transform((d) => ({ ...d, starts_at: `${d.date} ${d.time}`, booking_resource_id: d.booking_resource_id || (b ? undefined : pickedResource.value) || null }));
    if (b) form.put(route('bookings.update', b.id));
    else form.post(route('bookings.store'));
};
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="b ? $t('bk2_bookings_form.edit_n', { n0: b.number }) : $t('bk2_bookings_form.book_an_appointment')" />
        <PageHeader :title="b ? $t('bk2_bookings_form.edit_appointment_n', { n0: b.number }) : $t('bk2_bookings_form.new_appointment_booking')" :crumbs="[{ label: $t('bk2_bookings_form.bookings'), href: route('bookings.calendar') }, { label: b ? b.number : $t('bk2_bookings_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('bk2_bookings_form.customer')" :error="form.errors.customer_id"><PartyPicker v-if="!b" v-model="form.customer_id" /><p v-else class="font-bold py-2">{{ b.customer?.name }}</p></Field>
            <Field :label="$t('bk2_bookings_form.service')" :error="form.errors.bookable_service_id">
                <SelectCreate v-if="!b" v-model="form.bookable_service_id" :options="services" entity="bookable_service" :label="(s) => $t('bk2_bookings_form.n_n_min', { n0: s.name, n1: s.duration_minutes })" :placeholder="$t('bk2_bookings_form.choose_the_service')" />
                <p v-else class="font-bold py-2">{{ service?.name }}</p>
            </Field>
            <Field :label="$t('bk2_bookings_form.supplier')" :error="form.errors.booking_resource_id" :hint="$t('bk2_bookings_form.leave_empty_to_automatically_pic')">
                <SelectCreate v-model="form.booking_resource_id" :options="serviceResources" entity="booking_resource" :label="(r) => r.name" :placeholder="$t('bk2_bookings_form.any_available_resource')" />
            </Field>
            <Field :label="$t('bk2_bookings_form.date')" :error="form.errors.date"><DatePicker v-model="form.date" required /></Field>
            <Field :label="$t('bk2_bookings_form.time')" :error="form.errors.starts_at" class="md:col-span-2">
                <SlotPicker v-model="form.time" :url="route('bookings.slots')" :service="form.bookable_service_id" :resource="form.booking_resource_id" :date="form.date" :ignore="b?.id" @picked="onPicked" />
                <p v-if="form.time && resourceName" class="text-3xs text-slate-500 mt-1">{{ $t('bk2_bookings_form.resource_n', { n0: resourceName }) }}</p>
            </Field>
            <Field v-if="!b" :label="$t('bk2_bookings_form.deposit_received')" :error="form.errors.deposit_amount" :hint="$t('bk2_bookings_form.a_receipt_voucher_is_recorded_fo')"><div class="flex gap-2"><input v-model.number="form.deposit_amount" type="text" inputmode="decimal" data-num :class="input" data-deposit /><select v-if="Number(form.deposit_amount) > 0" v-model="form.deposit_method" :class="input" class="w-32" data-deposit-method><option value="cash">{{ $t('bk2_bookings_form.cash') }}</option><option value="card">{{ $t('bk2_bookings_form.card') }}</option><option value="bank_transfer">{{ $t('bk2_bookings_form.transfer') }}</option></select></div></Field>
            <Field :label="$t('bk2_bookings_form.notes')" :error="form.errors.notes" class="md:col-span-2"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <p v-if="service" class="md:col-span-2 text-slate-500">{{ $t('bk2_bookings_form.price') }} <span class="font-mono font-bold">{{ money(service.price) }}</span> {{ $t('bk2_bookings_form.the_membership_discount_is_appli') }}</p>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing || !form.time" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-save-booking>{{ b ? $t('bk2_bookings_form.save_the_appointment') : $t('bk2_bookings_form.booking') }}</button>
                <Link :href="b ? route('bookings.show', b.id) : route('bookings.calendar')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('bk2_bookings_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
