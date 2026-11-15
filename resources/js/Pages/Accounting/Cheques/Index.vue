<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ask } from '@/composables/useDialog';
import { ref, reactive, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, today } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ cheques: Object, summary: Object, bankAccounts: Array, transitions: Object, filters: Object });

const open = ref(false);
const form = useForm({ direction: 'incoming', cheque_number: '', bank_name: '', bank_account_id: '', party_type: 'customer', party_id: '', invoice_id: '', amount: '', issue_date: today(), due_date: today(), notes: '' });
// Open invoices of the chosen customer: a cheque against one of them pays it.
const openInvoices = ref([]);
const loadInvoices = async () => {
    openInvoices.value = [];
    form.invoice_id = '';
    if (form.party_type !== 'customer' || !form.party_id) return;
    try {
        const { data } = await axios.get(route('sales.payments.open-invoices'), { params: { customer_id: form.party_id } });
        openInvoices.value = (data.data || []).filter((i) => !i.currency_code || i.currency_code === (usePage().props.tenant?.currency || 'SAR'));
    } catch (e) {
        openInvoices.value = [];
    }
};

// Party search (customers / suppliers) through the bounded lookup endpoints.
const partyQuery = ref('');
const partyResults = ref([]);
const searchParty = async () => {
    if (partyQuery.value.trim().length < 2) return;
    const { data } = await axios.get(route(form.party_type === 'customer' ? 'lookup.customers' : 'lookup.suppliers'), { params: { q: partyQuery.value } });
    partyResults.value = data.data;
};
const pickParty = (p) => { form.party_id = p.id; partyQuery.value = p.name; partyResults.value = []; loadInvoices(); };
const setDirection = (d) => { form.direction = d; form.party_type = d === 'incoming' ? 'customer' : 'supplier'; form.party_id = ''; partyQuery.value = ''; };

const save = () => form.post(route('accounting.cheques.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); partyQuery.value = ''; } });

const filters = reactive({ direction: props.filters.direction ?? '', status: props.filters.status ?? '', search: props.filters.search ?? '' });
const apply = () => router.get(route('accounting.cheques.index'), filters, { preserveState: true });

const statusLabel = computed(() => ({ pending: t('a_cheques.st_pending'), deposited: t('a_cheques.st_deposited'), cleared: t('a_cheques.st_cleared'), bounced: t('a_cheques.st_bounced'), cancelled: t('a_cheques.st_cancelled') }));
const actionLabel = computed(() => ({ deposited: t('a_cheques.ac_deposit'), cleared: t('a_cheques.ac_clear'), bounced: t('a_cheques.ac_bounce'), cancelled: t('a_cheques.ac_cancel') }));
const move = async (cheque, status) => {
    const d = await ask(t('a_cheques.ask_date', { action: actionLabel.value[status], number: cheque.cheque_number ?? '' }), { title: actionLabel.value[status], type: 'date', default: today(), required: true, danger: status === 'bounced' });
    if (!d) return;
    router.post(route('accounting.cheques.transition', cheque.id), { status, date: d, bank_account_id: cheque.bank_account_id }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_cheques.cheques')" />
        <PageHeader :title="$t('a_cheques.cheque_management')" :subtitle="$t('a_cheques.cheques_received_from_customers')" :crumbs="[{ label: 'المحاسبة' }, { label: 'الشيكات' }]">
            <template #actions><button v-if="$can('accounting.cheques.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('a_cheques.record_a_cheque') }}</button></template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('a_cheques.outstanding_incoming_cheques')" :value="money(summary.incoming?.amount ?? 0)" tone="emerald" />
            <StatCard :label="$t('a_cheques.incoming_due_within_7_days')" :value="money(summary.incoming?.due_soon ?? 0)" tone="amber" />
            <StatCard :label="$t('a_cheques.outstanding_outgoing_cheques')" :value="money(summary.outgoing?.amount ?? 0)" tone="rose" />
            <StatCard :label="$t('a_cheques.outgoing_due_within_7_days')" :value="money(summary.outgoing?.due_soon ?? 0)" tone="amber" />
        </div>

        <form @submit.prevent="apply" class="flex flex-wrap gap-2 mb-3 text-xs">
            <select v-model="filters.direction" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('a_cheques.all_cheques') }}</option><option value="incoming">{{ $t('a_cheques.incoming') }}</option><option value="outgoing">{{ $t('a_cheques.outgoing') }}</option></select>
            <select v-model="filters.status" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('a_cheques.all_statuses') }}</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select>
            <input v-model="filters.search" :placeholder="$t('a_cheques.cheque_number_or_name')" class="rounded-xl border-slate-200 text-xs" />
            <button class="px-4 rounded-xl bg-slate-800 text-white font-bold">{{ $t('ui.apply') }}</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="px-3 py-2">{{ $t('a_cheques.type') }}</th><th class="text-start px-3 py-2">{{ $t('a_cheques.cheque_number') }}</th><th class="text-start px-3 py-2">{{ $t('a_cheques.party') }}</th><th class="text-start px-3 py-2">{{ $t('a_cheques.bank') }}</th>
                        <th class="px-3 py-2">{{ $t('a_cheques.due') }}</th><th class="text-end px-3 py-2">{{ $t('ui.amount') }}</th><th class="px-3 py-2">{{ $t('ui.status') }}</th><th class="px-3 py-2">{{ $t('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in cheques.data" :key="c.id" class="border-t border-slate-100">
                            <td class="px-3 py-2 text-center"><span :class="c.direction === 'incoming' ? 'text-emerald-600' : 'text-rose-600'" class="font-bold">{{ c.direction === 'incoming' ? $t('a_cheques.incoming') : $t('a_cheques.outgoing') }}</span></td>
                            <td class="px-3 py-2 font-mono">{{ c.cheque_number }}</td>
                            <td class="px-3 py-2 font-semibold">{{ c.party_name }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ c.bank_name || c.bank_account?.name || '—' }}</td>
                            <td class="px-3 py-2 text-center font-mono" :class="c.status === 'pending' && date(c.due_date) <= today() ? 'text-rose-600 font-bold' : ''">{{ date(c.due_date) }}</td>
                            <td class="px-3 py-2 text-end font-mono font-bold">{{ money(c.amount) }}</td>
                            <td class="px-3 py-2 text-center">{{ statusLabel[c.status] }}</td>
                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                <template v-if="$can('accounting.cheques.edit')">
                                    <button v-for="next in (transitions[c.direction]?.[c.status] || [])" :key="next" @click="move(c, next)" class="mx-0.5 px-2 py-1 rounded-lg border border-slate-200 hover:border-indigo-300 hover:text-indigo-600">{{ actionLabel[next] }}</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!cheques.data.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="cheques" />
        </div>

        <Dialog :show="open" :title="$t('a_cheques.record_a_cheque')" size="lg" @close="open = false">
            <div class="flex gap-2 mb-4 text-xs">
                <button type="button" @click="setDirection('incoming')" :class="form.direction === 'incoming' ? 'bg-emerald-600 text-white' : 'bg-slate-100'" class="px-4 py-2 rounded-xl font-bold">{{ $t('a_cheques.incoming_cheque_from_a_customer') }}</button>
                <button type="button" @click="setDirection('outgoing')" :class="form.direction === 'outgoing' ? 'bg-rose-600 text-white' : 'bg-slate-100'" class="px-4 py-2 rounded-xl font-bold">{{ $t('a_cheques.outgoing_cheque_to_a_supplier') }}</button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                <div class="md:col-span-2 relative">
                    <Field :label="form.direction === 'incoming' ? $t('a_cheques.customer') : $t('a_cheques.supplier')" :error="form.errors.party_id">
                        <input v-model="partyQuery" @input="searchParty" :placeholder="$t('a_cheques.type_to_search')" class="w-full rounded-xl border-slate-200 text-xs" />
                    </Field>
                    <div v-if="partyResults.length" class="absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow max-h-48 overflow-y-auto">
                        <button v-for="p in partyResults" :key="p.id" type="button" @mousedown.prevent="pickParty(p)" @keydown.enter.prevent="pickParty(p)" class="block w-full text-start px-3 py-2 hover:bg-indigo-50">{{ p.name }}</button>
                    </div>
                </div>
                <Field v-if="openInvoices.length" :label="$t('a_cheques.for_an_invoice_optional')" :error="form.errors.invoice_id">
                    <select v-model="form.invoice_id" class="w-full rounded-xl border-slate-200 text-xs">
                        <option value="">{{ $t('a_cheques.on_account') }}</option>
                        <option v-for="i in openInvoices" :key="i.id" :value="i.id">{{ i.invoice_number }} — {{ $t('s_payments.remaining') }} {{ i.remaining_amount }}</option>
                    </select>
                </Field>
                <Field :label="$t('a_cheques.cheque_number')" :error="form.errors.cheque_number"><input v-model="form.cheque_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_cheques.amount')" :error="form.errors.amount"><input v-model.number="form.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_cheques.issue_date')"><DatePicker v-model="form.issue_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_cheques.due_date')" :error="form.errors.due_date"><DatePicker v-model="form.due_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_cheques.cheque_bank')"><input v-model="form.bank_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_cheques.our_bank_account')"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts.filter(x => x.type === 'bank')" entity="bank" label="name" placeholder="—" /></Field>
            </div>
            <template #footer><button @click="save" :disabled="form.processing || !form.party_id" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>
