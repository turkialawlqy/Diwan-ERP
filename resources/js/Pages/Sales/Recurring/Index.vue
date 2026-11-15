<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { taxRate } from '@/utils/tax';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import LineItemsEditor from '@/Components/Documents/LineItemsEditor.vue';
import CurrencyPicker from '@/Components/Documents/CurrencyPicker.vue';
import HijriDate from '@/Components/UI/HijriDate.vue';
import { money, today } from '@/utils/format';

/**
 * Recurring invoices: service contracts billed automatically every month,
 * quarter or year, optionally emailed to the customer on issue.
 */
const props = defineProps({ templates: Object, prefill: { type: Object, default: null } });
const flash = computed(() => usePage().props.flash || {});

const { t: tr } = useI18n();
const frequencies = computed(() => ({ monthly: tr('s_recurring.f_monthly'), quarterly: tr('s_recurring.f_quarterly'), yearly: tr('s_recurring.f_yearly') }));
const statuses = computed(() => ({ active: [tr('s_recurring.st_active'), 'bg-emerald-50 text-emerald-700'], paused: [tr('s_recurring.st_paused'), 'bg-amber-50 text-amber-700'], ended: [tr('s_recurring.st_ended'), 'bg-slate-100 text-slate-500'] }));

const open = ref(false);
const editing = ref(null);
const customer = ref(null);
const blank = () => ({ name: '', customer_id: null, frequency: 'monthly', interval: 1, start_date: today(), end_date: '', max_count: '', due_days: '', invoice_type: 'standard', auto_send: false, send_to: '', notes: '', currency_code: '', exchange_rate: '', lines: [] });
const form = useForm(blank());

const create = (data = null) => {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    customer.value = data?.customer || null;
    if (data) Object.assign(form, { ...blank(), ...data, lines: data.lines.map((l) => ({ ...l })) });
    open.value = true;
};
const edit = (t) => {
    editing.value = t;
    form.clearErrors();
    customer.value = t.customer;
    Object.assign(form, { ...blank(), ...t, start_date: t.start_date.substring(0, 10), end_date: t.end_date?.substring(0, 10) || '', max_count: t.max_count || '', due_days: t.due_days ?? '', currency_code: t.currency_code || '', send_to: t.send_to || '', notes: t.notes || '', lines: t.lines.map((l) => ({ ...l })) });
    open.value = true;
};
const save = () => {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    editing.value ? form.put(route('sales.recurring.update', editing.value.id), options) : form.post(route('sales.recurring.store'), options);
};
const toggle = (t) => router.post(route('sales.recurring.toggle', t.id), {}, { preserveScroll: true });
const runNow = (t) => router.post(route('sales.recurring.run', t.id), {}, { preserveScroll: true });
const remove = (t) => confirm(tr('s_recurring.confirm_delete', { name: t.name })) && router.delete(route('sales.recurring.destroy', t.id), { preserveScroll: true });

const perIssue = computed(() => form.lines.reduce((s, l) => s + (Number(l.quantity || 0) * Number(l.unit_price || 0) - Number(l.discount || 0)) * (1 + Number(l.tax_rate ?? taxRate()) / 100), 0));

onMounted(() => { if (props.prefill) create(props.prefill); });
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_recurring.recurring_invoices')" />
        <PageHeader :title="$t('s_recurring.recurring_invoices')" :subtitle="$t('s_recurring.service_contracts_invoiced_autom')" :crumbs="[{ label: 'المبيعات' }, { label: 'الفواتير الدورية' }]">
            <template #actions>
                <button v-if="$can('sales.recurring.create')" @click="create()" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('s_recurring.recurring_invoice') }}</button>
            </template>
        </PageHeader>

        <div v-if="flash.success" class="mb-3 rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-xs font-bold text-emerald-800">{{ flash.success }}</div>
        <div v-if="flash.error" class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-xs font-bold text-rose-800">{{ flash.error }}</div>

        <div class="space-y-3 text-xs">
            <div v-for="t in templates.data" :key="t.id" class="bg-white border border-slate-200 rounded-2xl p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-black text-slate-800 text-sm">{{ t.name }}</h3>
                            <span :class="['px-2 py-0.5 rounded-full font-bold', statuses[t.status][1]]">{{ statuses[t.status][0] }}</span>
                        </div>
                        <p class="text-slate-500 mt-1">{{ t.customer?.name }} · {{ frequencies[t.frequency] }}{{ t.interval > 1 ? ` (${$t('s_recurring.every_n', { n: t.interval })})` : '' }} · {{ money(t.estimated_total) }} {{ t.currency_code || $cur() }} {{ $t('s_recurring.per_issue') }}</p>
                        <p class="text-slate-500 mt-1">
                            {{ $t('s_recurring.issued') }} {{ t.generated_count }}{{ t.max_count ? ` ${$t('s_recurring.of')} ${t.max_count}` : '' }}
                            <template v-if="t.status !== 'ended'"> {{ $t('s_recurring.next_issue') }} <HijriDate :value="t.next_run_date" inline /></template>
                            <template v-if="t.auto_send"> · {{ $t('s_recurring.auto_sent_to') }} {{ t.send_to || t.customer?.email || $t('s_recurring.customer_email') }}</template>
                        </p>
                        <p v-if="t.last_error" class="mt-1 text-rose-600 font-bold">{{ $t('s_recurring.last_failed') }}: {{ t.last_error }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="$can('sales.recurring.edit') && t.status === 'active'" @click="runNow(t)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ $t('s_recurring.issue_due_invoices_now') }}</button>
                        <Link v-if="t.subscription_id" :href="route('sales.subscriptions.show', t.subscription_id)" class="px-3 py-1.5 rounded-xl border border-indigo-200 text-indigo-700 font-bold" data-owned-by-subscription>{{ $t('s_recurring.managed_from_the_subscription') }}</Link>
                        <template v-else>
                            <button v-if="$can('sales.recurring.edit') && t.status !== 'ended'" @click="toggle(t)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ t.status === 'active' ? $t('s_recurring.pause') : $t('s_recurring.resume') }}</button>
                            <button v-if="$can('sales.recurring.edit')" @click="edit(t)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ $t('s_recurring.edit') }}</button>
                            <button v-if="$can('sales.recurring.delete')" @click="remove(t)" class="px-3 py-1.5 rounded-xl border border-rose-200 text-rose-600 font-bold">{{ $t('s_recurring.delete') }}</button>
                        </template>
                    </div>
                </div>
                <div v-if="t.runs?.length" class="mt-3 flex flex-wrap gap-2">
                    <Link v-for="r in t.runs" :key="r.id" :href="r.invoice ? route('sales.invoices.show', r.invoice.id) : '#'" class="px-2.5 py-1 rounded-lg bg-slate-50 border border-slate-200 font-mono hover:border-indigo-300">
                        {{ r.period_date.substring(0, 7) }} · {{ r.invoice?.invoice_number || '—' }}
                    </Link>
                </div>
            </div>
            <div v-if="!templates.data.length" class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center text-slate-400">
                {{ $t('s_recurring.no_recurring_invoices_yet_create') }}
            </div>
            <Pagination :paginator="templates" />
        </div>

        <Dialog :show="open" :title="editing ? $t('s_recurring.edit_title') : $t('s_recurring.new_title')" size="xl" @close="open = false">
            <div class="space-y-4 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <Field :label="$t('s_recurring.contract_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" :placeholder="$t('s_recurring.example_monthly_maintenance_head')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.customer')" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" :initial="customer" /></Field>
                    <Field :label="$t('s_recurring.frequency')" :error="form.errors.frequency">
                        <select v-model="form.frequency" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in frequencies" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                    <Field :label="$t('s_recurring.every_number_of_periods')" :error="form.errors.interval"><input v-model.number="form.interval" type="text" inputmode="decimal" data-num min="1" max="12" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.first_issue_date')" :error="form.errors.start_date" :hint="$t('s_recurring.issued_on_the_same_day_of_each_m')"><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.payment_term_days')" :error="form.errors.due_days" :hint="$t('s_recurring.empty_customer_terms')"><input v-model.number="form.due_days" type="text" inputmode="decimal" data-num min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.ends_on_optional')" :error="form.errors.end_date"><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.or_after_a_number_of_issues')" :error="form.errors.max_count"><input v-model.number="form.max_count" type="text" inputmode="decimal" data-num min="1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.invoice_type')">
                        <select v-model="form.invoice_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="standard">{{ $t('s_recurring.tax_invoice_businesses') }}</option><option value="simplified">{{ $t('s_recurring.simplified_individuals') }}</option></select>
                    </Field>
                    <div><CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" /></div>
                </div>

                <div>
                    <h4 class="font-black text-slate-700 mb-2">{{ $t('s_recurring.invoice_lines') }} <span class="font-normal text-slate-400">{{ $t('s_recurring.services_only') }}</span></h4>
                    <LineItemsEditor v-model="form.lines" />
                    <p v-if="form.errors.lines" class="text-rose-600 mt-1">{{ form.errors.lines }}</p>
                    <p class="mt-2 text-slate-600">{{ $t('s_recurring.total_per_issue_including_tax') }} <b class="font-mono">{{ money(perIssue) }} {{ form.currency_code || $cur() }}</b></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 font-bold text-slate-700"><input v-model="form.auto_send" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $t('s_recurring.email_the_invoice_to_the_custome') }}</label>
                    <Field v-if="form.auto_send" :label="$t('s_recurring.send_to_email')" :error="form.errors.send_to" :hint="$t('s_recurring.empty_customer_email')"><input v-model="form.send_to" type="email" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_recurring.notes_shown_on_the_invoice')" class="md:col-span-2"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
            </div>
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('s_recurring.cancel') }}</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('s_recurring.save') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>
