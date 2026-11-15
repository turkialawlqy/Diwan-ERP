<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, subscription: Object, invoices: Array, statuses: Object, canRequest: Boolean });
const note = ref('');
const ask = (type) => router.post(route('customer-portal.subscriptions.request', { company: props.portal.company.id, subscription: props.subscription.id }), { type, note: note.value }, { preserveScroll: true });
</script>

<template>
    <Head :title="$t('por_portal_subscriptionshow.subscription_n', { n0: subscription.number })" />
    <PortalLayout :portal="portal" active="subscriptions">
        <div class="mb-3"><h1 class="text-lg font-black text-slate-900">{{ subscription.plan?.name }}</h1><p class="text-xs text-slate-500"><span class="font-mono">{{ subscription.number }}</span> · {{ statuses[subscription.status] || subscription.status }}</p></div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('por_portal_subscriptionshow.quantity') }}</p><p class="font-mono font-bold">{{ Number(subscription.quantity) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('por_portal_subscriptionshow.cycle_total') }}</p><p class="font-mono font-bold">{{ money(subscription.total) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('por_portal_subscriptionshow.next_renewal') }}</p><p class="font-mono font-bold">{{ subscription.renews_on || '—' }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">{{ $t('por_portal_subscriptionshow.start') }}</p><p class="font-mono font-bold">{{ subscription.start_date?.slice(0, 10) }}</p></div>
        </div>
        <p v-if="subscription.cancel_at_period_end" class="rounded-xl border border-amber-200 bg-amber-50 text-amber-700 text-xs font-bold px-4 py-3 mb-3">{{ $t('por_portal_subscriptionshow.the_subscription_will_be_cancell') }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">{{ $t('por_portal_subscriptionshow.subscription_invoices') }}</h2>
            <table class="w-full text-xs"><tbody>
                <tr v-for="i in invoices" :key="i.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono font-bold">{{ i.invoice_number }}</td><td class="px-4 py-2 font-mono">{{ i.invoice_date?.slice(0, 10) }}</td><td class="px-4 py-2 font-mono">{{ money(i.total) }}</td><td class="px-4 py-2 font-mono" :class="Number(i.remaining_amount) > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">{{ Number(i.remaining_amount) > 0 ? $t('por_portal_subscriptionshow.n_left', { n0: money(i.remaining_amount) }) : $t('por_portal_subscriptionshow.paid') }}</td></tr>
                <tr v-if="!invoices.length"><td class="py-6 text-center text-slate-400">{{ $t('por_portal_subscriptionshow.no_invoices_yet') }}</td></tr>
            </tbody></table>
        </div>
        <div v-if="canRequest" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2 text-xs" data-request>
            <p class="font-bold">{{ $t('por_portal_subscriptionshow.request_a_change_or_cancellation') }}</p><p class="text-slate-500">{{ $t('por_portal_subscriptionshow.your_request_reaches_the_team_as') }}</p>
            <textarea v-model="note" rows="2" :placeholder="$t('por_portal_subscriptionshow.notes_optional')" class="w-full rounded-xl border-slate-200 text-xs"></textarea>
            <div class="flex gap-2"><button type="button" class="px-4 py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold" @click="ask('change')" data-request-change>{{ $t('por_portal_subscriptionshow.request_a_plan_change') }}</button><button type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 font-bold" @click="ask('cancel')" data-request-cancel>{{ $t('por_portal_subscriptionshow.request_cancellation') }}</button></div>
        </div>
    </PortalLayout>
</template>
