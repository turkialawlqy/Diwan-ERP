<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import CancelDocument from '@/Components/CancelDocument.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { ref } from 'vue';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import CurrencyPicker from '@/Components/Documents/CurrencyPicker.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    BanknotesIcon,
    ArrowPathIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';
import { today } from '@/utils/format';

const props = defineProps({
    payments: Object,
    filters: Object,
    stats: Object,
    bankAccounts: { type: Array, default: () => [] },
});

// New receipt voucher
const showForm = ref(false);
const openInvoices = ref([]);
const form = useForm({
    customer_id: null, invoice_id: '', amount: '', payment_date: today(),
    payment_method: 'bank_transfer', bank_account_id: '', reference_number: '', cheque_due_date: '', notes: '', currency_code: '', exchange_rate: '', auto_allocate: true,
});
const loadInvoices = async (customerId) => {
    form.invoice_id = '';
    openInvoices.value = customerId ? (await axios.get(route('sales.payments.open-invoices'), { params: { customer_id: customerId } })).data.data : [];
};
const pickInvoice = () => {
    const inv = openInvoices.value.find((i) => i.id === form.invoice_id);
    if (inv) {
        form.amount = Number(inv.remaining_amount);
        form.currency_code = inv.currency_code || '';
        form.exchange_rate = '';
    }
};
const submitPayment = () => form.post(route('sales.payments.store'), {
    preserveScroll: true,
    onSuccess: () => { showForm.value = false; form.reset(); openInvoices.value = []; },
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_payments.receipt_vouchers_and_payments')" />

        <div class="space-y-5">
            <!-- Breadcrumbs Ribbon -->
            <PageHeader year-scoped :title="$t('s_payments.payments')" :crumbs="[{ label: 'المبيعات' }, { label: 'المدفوعات' }]">
<template #actions>
<button
                        v-if="$canRoute('sales.payments.store')"
                        @click="showForm = true"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold"
                    >
                        {{ $t('s_payments.new_receipt_voucher') }}
                    </button>

                    <button 
                        @click="router.reload()" 
                        :title="$t('s_payments.refresh_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
</template>
</PageHeader>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_payments.total_receipt_vouchers') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_count ?? payments.total ?? 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_payments.total_receipts') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.total_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_payments.cash_payments') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ formatCurrency(stats?.cash_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_payments.card_bank_payments') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-700">{{ formatCurrency(stats?.bank_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6">{{ $t('s_payments.voucher_number') }}</th>
                                <th class="py-4 px-6">{{ $t('s_payments.customer') }}</th>
                                <th class="py-4 px-6">{{ $t('s_payments.amount') }}</th>
                                <th class="py-4 px-6">{{ $t('s_payments.payment_method') }}</th>
                                <th class="py-4 px-6">{{ $t('s_payments.payment_date') }}</th>
                                <th class="py-4 px-6"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in payments.data" :key="p.id" class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-4 px-6 font-bold text-slate-800 font-mono">{{ p.payment_number }}</td>
                                <td class="py-4 px-6 font-semibold text-slate-700">{{ p.customer?.name || '-' }}</td>
                                <td class="py-4 px-6 font-black text-emerald-600 font-mono">{{ formatCurrency(p.amount) }} {{ p.currency_code || '﷼' }}</td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ p.payment_method === 'cash' ? $t('s_payments.cash') : p.payment_method === 'card' ? $t('s_payments.card_mada') : $t('s_payments.bank_transfer') }}
                                </td>
                                <td class="py-4 px-6 text-slate-500">{{ p.payment_date }}</td>
                                <td class="py-4 px-6"><div class="flex items-center gap-3"><ShareDocument v-if="$can('sales.payments.print') && p.status !== 'cancelled'" type="receipt" :id="p.id" compact /><CancelDocument v-if="$can('sales.payments.cancel') || p.status === 'cancelled'" :url="route('sales.payments.cancel', p.id)" :label="$t('s_payments.receipt_voucher')" :status="p.status" /></div></td>
                            </tr>
                            <tr v-if="!payments.data || payments.data.length === 0">
                                <td colspan="6" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <BanknotesIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">{{ $t('s_payments.no_receipt_vouchers_recorded_yet') }}</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="payments?.links && payments.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs text-slate-500">
                        {{ $t('s_payments.showing') }} <span class="font-bold text-slate-700">{{ payments.from || 0 }}</span> {{ $t('s_payments.to') }} <span class="font-bold text-slate-700">{{ payments.to || 0 }}</span> {{ $t('s_payments.of') }} <span class="font-bold text-slate-700">{{ payments.total || 0 }}</span> {{ $t('s_payments.vouchers') }}
                    </p>
                    <div class="flex items-center gap-1">
                        <Link 
                            v-for="(link, i) in payments.links" 
                            :key="i"
                            :href="link.url || '#'"
                            v-html="link.label"
                            :class="[
                                'px-3 py-1.5 text-xs rounded-lg transition-all',
                                link.active ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'text-slate-600 hover:bg-slate-100',
                                !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>
        <Dialog :show="showForm" :title="$t('s_payments.new_receipt_voucher_2')" size="md" @close="showForm = false">
            <div class="space-y-3 text-xs">
                <Field :label="$t('s_payments.customer')" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" @update:model-value="loadInvoices" /></Field>
                <Field :label="$t('s_payments.invoice')" :error="form.errors.invoice_id" :hint="$t('s_payments.leave_it_on_auto_allocate_to_set')">
                    <select v-model="form.invoice_id" @change="pickInvoice" class="w-full rounded-xl border-slate-200 text-xs">
                        <option value="">{{ $t('s_payments.auto_allocate_to_open_invoices') }}</option>
                        <option v-for="inv in openInvoices" :key="inv.id" :value="inv.id">{{ inv.invoice_number }} — {{ $t('s_payments.remaining') }} {{ Number(inv.remaining_amount).toFixed(2) }} {{ inv.currency_code || '' }}</option>
                    </select>
                </Field>
                <div class="grid grid-cols-2 gap-2">
                    <Field :label="$t('s_payments.amount')" :error="form.errors.amount"><input v-model.number="form.amount" type="text" inputmode="decimal" data-num step="0.01" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field :label="$t('s_payments.date')" :error="form.errors.payment_date"><DatePicker v-model="form.payment_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('s_payments.payment_method')" :error="form.errors.payment_method">
                        <select v-model="form.payment_method" class="w-full rounded-xl border-slate-200 text-xs">
                            <option value="bank_transfer">{{ $t('s_payments.bank_transfer') }}</option><option value="cash">{{ $t('s_payments.cash') }}</option><option value="card">{{ $t('s_payments.card_mada') }}</option><option value="cheque">{{ $t('s_payments.cheque') }}</option>
                        </select>
                    </Field>
                    <Field :label="$t('s_payments.cash_box_bank')" :error="form.errors.bank_account_id">
                        <SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('s_payments.default')" />
                    </Field>
                </div>
                <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" />
                <Field :label="$t('s_payments.reference_number')"><input v-model="form.reference_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="form.payment_method === 'cheque'" :label="$t('s_payments.cheque_due_date_leave_empty_if_p')" :error="form.errors.cheque_due_date"><DatePicker v-model="form.cheque_due_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_payments.notes')"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <p v-if="form.errors.allocations" class="text-rose-600">{{ form.errors.allocations }}</p>
            </div>
            <template #footer>
                <button type="button" @click="showForm = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('s_payments.cancel') }}</button>
                <button type="button" @click="submitPayment" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('s_payments.save_voucher') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>
