<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';

const props = defineProps({ subscription: Object, renewsOn: String, periodTotal: Number, invoices: Array, plans: Array, statuses: Object, cycles: Object });
// Reactive: the page re-renders with the new status after every action.
const s = computed(() => props.subscription);
const post = (name, data = {}) => router.post(route(name, s.value.id), data, { preserveScroll: true });
const tone = { trial: 'bg-sky-50 text-sky-700 border-sky-200', active: 'bg-emerald-50 text-emerald-700 border-emerald-200', paused: 'bg-slate-100 text-slate-600 border-slate-200', past_due: 'bg-rose-50 text-rose-700 border-rose-200', cancelled: 'bg-slate-100 text-slate-500 border-slate-200', expired: 'bg-amber-50 text-amber-700 border-amber-200' };
const live = computed(() => ['trial', 'active', 'past_due'].includes(s.value.status));
const ended = computed(() => ['cancelled', 'expired'].includes(s.value.status));

const planDialog = ref(false);
const planForm = useForm({ service_plan_id: '', when: 'now' });
const changePlan = () => planForm.post(route('sales.subscriptions.change-plan', s.value.id), { preserveScroll: true, onSuccess: () => (planDialog.value = false) });

const cancelDialog = ref(false);
const cancelForm = useForm({ reason: '', at_period_end: true });
const cancel = () => cancelForm.post(route('sales.subscriptions.cancel', s.value.id), { preserveScroll: true, onSuccess: () => (cancelDialog.value = false) });
const btn = 'px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50';
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('s_sub.title')} ${s.number}`" />
        <PageHeader :title="`${$t('s_sub.title')} ${s.number}`" :subtitle="s.customer?.name" :crumbs="[{ label: 'المبيعات' }, { label: 'اشتراكات العملاء', href: route('sales.subscriptions.index') }, { label: s.number }]">
            <template #actions>
                <template v-if="$can('sales.subscriptions.edit')">
                    <button v-if="live" type="button" :class="btn" @click="post('sales.subscriptions.pause')" data-pause>{{ $t('s_sub.pause') }}</button>
                    <button v-if="s.status === 'paused'" type="button" :class="btn" @click="post('sales.subscriptions.resume')" data-resume>{{ $t('s_sub.resume') }}</button>
                    <button v-if="live || s.status === 'paused'" type="button" :class="btn" @click="planDialog = true" data-change-plan>{{ $t('s_sub.change_plan') }}</button>
                    <button v-if="s.cancel_at_period_end" type="button" :class="btn" @click="post('sales.subscriptions.keep')" data-keep>{{ $t('s_sub.undo_cancellation') }}</button>
                    <button v-else-if="!ended" type="button" class="px-3 py-1.5 rounded-lg border border-rose-200 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="cancelDialog = true" data-cancel>{{ $t('s_sub.cancel_subscription') }}</button>
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1">
                <p class="text-slate-500">{{ $t('s_sub.status') }}</p>
                <span class="px-2 py-0.5 rounded-full border font-bold" :class="tone[s.status]" data-status>{{ statuses[s.status] }}</span>
                <p v-if="s.cancel_at_period_end" class="text-amber-600 font-bold pt-1">{{ $t('s_sub.will_be_cancelled_at_the_end_of') }}</p>
                <p v-if="s.pending_plan" class="text-amber-600 font-bold pt-1">{{ $t('s_sub.moves_to', { name: s.pending_plan.name }) }}</p>
                <p v-if="s.cancel_reason" class="text-slate-500 pt-1">{{ $t('s_sub.reason') }}: {{ s.cancel_reason }}</p>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1">
                <p class="text-slate-500">{{ $t('s_sub.plan') }}</p>
                <p class="font-bold text-sm">{{ s.plan?.name }} <span class="text-slate-400 font-normal">× {{ Number(s.quantity) }}</span></p>
                <p class="font-mono">{{ money(s.unit_price) }} + {{ Number(s.tax_rate) }}% = <b>{{ money(periodTotal) }}</b> / {{ cycles[s.plan?.cycle] }}</p>
                <p v-if="s.trial_ends_at" class="text-sky-600">{{ $t('s_sub.trial_until', { d: s.trial_ends_at.slice(0, 10) }) }}</p>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1">
                <p class="text-slate-500">{{ s.auto_renew ? $t('s_sub.next_renewal') : $t('s_sub.ends_on') }}</p>
                <p class="font-mono font-bold text-sm" data-renews-on>{{ renewsOn || '—' }}</p>
                <p class="text-slate-500">{{ $t('s_sub.started') }} {{ s.start_date.slice(0, 10) }} · <Link :href="route('sales.clients.show', s.customer_id)" class="text-indigo-600 font-bold">{{ s.customer?.name }}</Link></p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <h2 class="px-4 py-3 text-xs font-bold text-slate-800 border-b border-slate-100">{{ $t('s_sub.issued_invoices') }}</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('s_sub.invoice') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub.date') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub.due') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub.total') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub.remaining') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices" :key="i.id" class="border-t border-slate-100">
                        <td class="px-4 py-2"><Link :href="route('sales.invoices.show', i.id)" class="font-mono font-bold text-indigo-600">{{ i.invoice_number }}</Link></td>
                        <td class="px-4 py-2 font-mono">{{ i.invoice_date?.slice(0, 10) }}</td>
                        <td class="px-4 py-2 font-mono">{{ i.due_date?.slice(0, 10) }}</td>
                        <td class="px-4 py-2 font-mono">{{ money(i.total) }}</td>
                        <td class="px-4 py-2 font-mono" :class="Number(i.remaining_amount) > 0 ? 'text-rose-600 font-bold' : ''">{{ money(i.remaining_amount) }}</td>
                        <td class="px-4 py-2">{{ { paid: $t('s_invoices.paid'), unpaid: $t('s_invoices.unpaid'), partially_paid: $t('s_invoices.partially_paid') }[i.payment_status] || i.payment_status }}</td>
                    </tr>
                    <tr v-if="!invoices.length"><td colspan="6" class="py-8 text-center text-slate-400">{{ $t('s_sub.no_invoices_issued_yet') }}</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :show="planDialog" :title="$t('s_sub.change_the_subscription_plan')" @close="planDialog = false">
            <div class="space-y-3 text-xs">
                <Field :label="$t('s_sub.new_plan')" :error="planForm.errors.service_plan_id">
                    <SelectCreate v-model="planForm.service_plan_id" :options="plans" entity="service_plan" :label="(p) => `${p.name} — ${money(p.price)} / ${cycles[p.cycle] || ''}`" :placeholder="$t('s_sub.choose')" data-new-plan-select />
                </Field>
                <Field :label="$t('s_sub.when_it_applies')" :hint="$t('s_sub.an_immediate_upgrade_issues_an_i')">
                    <select v-model="planForm.when" class="w-full rounded-xl border-slate-200 text-xs"><option value="now">{{ $t('s_sub.immediately_with_an_upgrade_diff') }}</option><option value="renewal">{{ $t('s_sub.at_the_next_renewal') }}</option></select>
                </Field>
            </div>
            <template #footer>
                <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="planDialog = false">{{ $t('s_sub.cancel') }}</button>
                <button type="button" :disabled="!planForm.service_plan_id || planForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50" @click="changePlan" data-confirm-plan>{{ $t('s_sub.confirm') }}</button>
            </template>
        </Dialog>

        <Dialog :show="cancelDialog" :title="$t('s_sub.cancel_subscription')" @close="cancelDialog = false">
            <div class="space-y-3 text-xs">
                <Field :label="$t('s_sub.when')" ><select v-model="cancelForm.at_period_end" class="w-full rounded-xl border-slate-200 text-xs"><option :value="true">{{ $t('s_sub.at_the_end_of_the_current_period') }}</option><option :value="false">{{ $t('s_sub.now') }}</option></select></Field>
                <Field :label="$t('s_sub.reason')" :error="cancelForm.errors.reason"><input v-model="cancelForm.reason" maxlength="500" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer>
                <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="cancelDialog = false">{{ $t('s_sub.back') }}</button>
                <button type="button" :disabled="cancelForm.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="cancel" data-confirm-cancel>{{ $t('s_sub.confirm_cancellation') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>
