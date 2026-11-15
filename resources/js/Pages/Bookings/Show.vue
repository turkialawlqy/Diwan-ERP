<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import { money } from '@/utils/format';

const props = defineProps({ booking: Object, coveringPackages: Array, net: Number, statuses: Object, depositOnCancel: Object });
const b = computed(() => props.booking);
const open = computed(() => ['pending', 'confirmed'].includes(b.value.status));
const when = (v) => String(v).replace('T', ' ').slice(0, 16);
const post = (name, data = {}) => router.post(route(name, b.value.id), data, { preserveScroll: true });
const tone = { pending: 'bg-amber-50 text-amber-700 border-amber-200', confirmed: 'bg-sky-50 text-sky-700 border-sky-200', completed: 'bg-emerald-50 text-emerald-700 border-emerald-200', cancelled: 'bg-rose-50 text-rose-700 border-rose-200', no_show: 'bg-slate-100 text-slate-600 border-slate-200' };

const cancelling = ref(false);
const cancel = useForm({ reason: '', deposit: props.depositOnCancel?.default ?? null });
const doCancel = () => cancel.post(route('bookings.cancel', b.value.id), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });

const completing = ref(false);
const done = useForm({ use_package: true, customer_package_id: '' });
const doComplete = () => done.transform((d) => ({ ...d, customer_package_id: d.use_package ? d.customer_package_id || null : null })).post(route('bookings.complete', b.value.id), { preserveScroll: true, onSuccess: () => (completing.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="$t('bk2_bookings_show.booking_n', { n0: b.number })" />
        <PageHeader :title="`${b.number} — ${b.customer?.name}`" :crumbs="[{ label: $t('bk2_bookings_show.bookings'), href: route('bookings.calendar') }, { label: b.number }]">
            <template #actions>
                <ShareDocument v-if="$can('bookings.bookings.view')" type="booking" :id="b.id" />
                <template v-if="open && $can('bookings.bookings.edit')">
                    <button v-if="b.status === 'pending'" type="button" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold" @click="post('bookings.confirm')" data-confirm>{{ $t('bk2_bookings_show.confirm') }}</button>
                    <button type="button" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="completing = true" data-complete>{{ $t('bk2_bookings_show.complete') }}</button>
                    <Link :href="route('bookings.edit', b.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" data-reschedule>{{ $t('bk2_bookings_show.reschedule') }}</Link>
                    <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="post('bookings.no-show')" data-no-show>{{ $t('bk2_bookings_show.did_not_attend') }}</button>
                    <button type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" @click="cancelling = true" data-cancel>{{ $t('bk2_bookings_show.cancel_the_booking') }}</button>
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3">
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.status') }}</p><span class="inline-block px-2.5 py-1 rounded-full border font-bold" :class="tone[b.status]" data-status>{{ statuses[b.status] }}</span></div>
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.appointment') }}</p><p class="font-mono font-bold">{{ when(b.starts_at) }} → {{ String(b.ends_at).slice(11, 16) }}</p></div>
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.service') }}</p><p class="font-bold">{{ b.service?.name }}</p></div>
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.supplier') }}</p><p class="font-bold">{{ b.resource?.name }}</p></div>
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.customer') }}</p><Link :href="route('sales.clients.show', b.customer_id)" class="font-bold text-indigo-600">{{ b.customer?.name }}</Link><p class="text-slate-500" dir="ltr">{{ b.customer?.phone }}</p></div>
                <div><p class="text-slate-500">{{ $t('bk2_bookings_show.source') }}</p><p class="font-bold">{{ b.source === 'portal' ? $t('bk2_bookings_show.customer_portal') : $t('bk2_bookings_show.employee') }}</p></div>
                <div v-if="b.notes" class="col-span-2"><p class="text-slate-500">{{ $t('bk2_bookings_show.notes') }}</p><p class="whitespace-pre-line">{{ b.notes }}</p></div>
                <div v-if="b.cancel_reason" class="col-span-2"><p class="text-slate-500">{{ $t('bk2_bookings_show.cancellation_reason') }}</p><p>{{ b.cancel_reason }}</p></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                <h2 class="font-bold text-slate-800">{{ $t('bk2_bookings_show.amount') }}</h2>
                <p class="flex justify-between"><span>{{ $t('bk2_bookings_show.price') }}</span><span class="font-mono">{{ money(b.price) }}</span></p>
                <p v-if="Number(b.member_discount)" class="flex justify-between text-emerald-700"><span>{{ $t('bk2_bookings_show.membership_discount') }}</span><span class="font-mono">- {{ money(b.member_discount) }}</span></p>
                <p class="flex justify-between font-bold border-t border-slate-100 pt-2"><span>{{ $t('bk2_bookings_show.net_before_tax') }}</span><span class="font-mono" data-net>{{ money(net) }}</span></p>
                <p v-if="Number(b.deposit_amount)" class="flex justify-between"><span>{{ $t('bk2_bookings_show.deposit_received') }}</span><span class="font-mono">{{ money(b.deposit_amount) }}</span></p>
                <p v-if="b.invoice" class="pt-2 border-t border-slate-100">{{ $t('bk2_bookings_show.invoice') }} <Link :href="route('sales.invoices.show', b.invoice.id)" class="font-mono font-bold text-indigo-600" data-invoice>{{ b.invoice.invoice_number }}</Link></p>
                <p v-if="b.package" class="pt-2 border-t border-slate-100">{{ $t('bk2_bookings_show.a_visit_was_deducted_from_the_pa') }} <Link :href="route('bookings.customer-packages.show', b.package.id)" class="font-mono font-bold text-indigo-600" data-used-package>{{ b.package.number }}</Link></p>
            </div>
        </div>

        <Dialog :show="completing" :title="$t('bk2_bookings_show.complete_the_booking')" @close="completing = false">
            <div class="space-y-3 text-xs">
                <label v-if="coveringPackages.length" class="flex items-center gap-2"><input v-model="done.use_package" type="checkbox" class="rounded border-slate-300" data-use-package /> {{ $t('bk2_bookings_show.deduct_a_visit_from_the_customer') }}</label>
                <select v-if="coveringPackages.length && done.use_package" v-model="done.customer_package_id" class="w-full rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('bk2_bookings_show.the_nearest_to_expiry_automatica') }}</option><option v-for="p in coveringPackages" :key="p.id" :value="p.id">{{ $t('bk2_bookings_show.n_n_n_left', { n0: p.number, n1: p.name, n2: p.remaining }) }}</option>
                </select>
                <p v-if="!coveringPackages.length || !done.use_package" class="text-slate-600">{{ $t('bk2_bookings_show.an_invoice_will_be_issued_to_the', { n0: money(net) }) }}<span v-if="Number(b.deposit_amount)"> {{ $t('bk2_bookings_show.after_deducting_the_deposit_n', { n0: money(b.deposit_amount) }) }}</span>.</p>
                <p v-if="done.errors.customer_package_id" class="text-rose-600 font-bold">{{ done.errors.customer_package_id }}</p>
            </div>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="completing = false">{{ $t('bk2_bookings_show.back') }}</button><button type="button" :disabled="done.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="doComplete" data-confirm-complete>{{ $t('bk2_bookings_show.confirm_completion') }}</button></template>
        </Dialog>
        <Dialog :show="cancelling" :title="$t('bk2_bookings_show.cancel_the_booking')" @close="cancelling = false">
            <input v-model="cancel.reason" :placeholder="$t('bk2_bookings_show.cancellation_reason_optional')" class="w-full rounded-xl border-slate-200 text-xs" data-cancel-reason />
            <div v-if="depositOnCancel" class="mt-3 space-y-1.5 text-xs" data-cancel-deposit>
                <p class="font-bold text-slate-600">{{ $t('bookings.deposit_received') }} {{ money(b.deposit_amount) }}</p>
                <label class="flex items-center gap-2"><input v-model="cancel.deposit" type="radio" value="credit" data-deposit-credit /> {{ $t('bookings.deposit_credit') }}</label>
                <label class="flex items-center gap-2"><input v-model="cancel.deposit" type="radio" value="forfeit" data-deposit-forfeit /> {{ $t('bookings.deposit_forfeit') }}</label>
                <p class="text-slate-400">{{ depositOnCancel.late ? $t('bookings.deposit_late') : $t('bookings.deposit_early') }}</p>
                <p v-if="cancel.errors.deposit" class="text-rose-600 font-bold">{{ cancel.errors.deposit }}</p>
            </div>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="cancelling = false">{{ $t('bk2_bookings_show.back') }}</button><button type="button" :disabled="cancel.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="doCancel" data-confirm-cancel>{{ $t('bk2_bookings_show.confirm_cancellation') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
