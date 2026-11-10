<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { today } from '@/utils/format';

const props = defineProps({ suppliers: Array, whtRates: { type: Object, default: () => ({}) }, prefill: { type: Object, default: null } });

const form = useForm({
    supplier_id: props.suppliers?.[0]?.id || '',
    purchase_invoice_id: '',
    amount: '',
    payment_date: today(),
    payment_method: 'bank_transfer',
    reference_number: '',
    cheque_due_date: '',
    notes: '',
    currency_code: '',
    exchange_rate: '',
    auto_allocate: false,
    allocations: [],
    payment_request_id: '',
    bank_account_id: '',
});
// Carrying out an approved payment request: its data fills the voucher and the request is tied to it.
if (props.prefill) {
    const p = props.prefill;
    Object.assign(form, { supplier_id: p.supplier_id || form.supplier_id, purchase_invoice_id: p.purchase_invoice_id || '', amount: p.amount, payment_method: p.payment_method || form.payment_method, bank_account_id: p.bank_account_id || '', notes: p.notes || '', payment_request_id: p.payment_request_id });
    form.defaults();
}
useUnsavedGuard(form);

const supplier = computed(() => props.suppliers.find((s) => s.id === form.supplier_id));
// Withholding tax on a non-resident supplier's payment (the rate of his category).
const whtRate = computed(() => (supplier.value?.is_non_resident ? Number(props.whtRates[supplier.value.wht_category || 'other'] ?? 0) : 0));
const whtAmount = computed(() => Math.round((Number(form.amount) || 0) * whtRate.value) / 100);
const filteredInvoices = ref([]);
// Spreading one payment over several invoices: an amount per open invoice, or «oldest first».
const alloc = ref({});
const openInvoices = computed(() => [...filteredInvoices.value].sort((a, b) => a.id - b.id).filter((i) => (i.currency_code || '') === (form.currency_code || '')));
const allocated = computed(() => Object.values(alloc.value).reduce((s, v) => s + (Number(v) || 0), 0));
const distribute = () => {
    let left = Number(form.amount) || 0;
    const next = {};
    for (const inv of openInvoices.value) {
        const take = Math.min(left, Number(inv.remaining_amount));
        if (take > 0) next[inv.id] = Math.round(take * 100) / 100;
        left = Math.round((left - take) * 100) / 100;
    }
    alloc.value = next;
    form.auto_allocate = false;
};

// The chosen supplier's open invoices come from the server: the list of every supplier's would be megabytes.
let invoicesRequest = 0;
const loadOpenInvoices = async () => {
    const current = ++invoicesRequest;
    filteredInvoices.value = [];
    if (!form.supplier_id) return;
    try {
        const { data } = await axios.get(route('purchases.payments.open-invoices'), { params: { supplier_id: form.supplier_id } });
        if (current === invoicesRequest) filteredInvoices.value = data.data;
    } catch (e) {
        if (current === invoicesRequest) filteredInvoices.value = [];
    }
};
const onSupplierChange = () => {
    form.purchase_invoice_id = '';
    alloc.value = {};
    loadOpenInvoices();
};
onSupplierChange();

const formatCurrency = (val) => Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const submitPayment = () => form.transform((d) => {
    const rows = d.purchase_invoice_id ? [] : Object.entries(alloc.value).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ purchase_invoice_id: Number(id), amount: Number(v) }));
    const { allocations, ...rest } = d;
    return rows.length ? { ...rest, allocations: rows, auto_allocate: false } : { ...rest, auto_allocate: !d.purchase_invoice_id && d.auto_allocate };
}).post(route('purchases.payments.store'));
</script>

<template>
    <AppLayout>
        <Head title="سند صرف جديد" />
        <PageHeader title="سند صرف جديد" :crumbs="[{ label: 'المشتريات' }, { label: 'مدفوعات الموردين', href: route('purchases.payments.index') }, { label: 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitPayment" class="form-grid text-xs">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">المورد <span class="text-rose-500">*</span></label>
                                <SelectCreate v-model="form.supplier_id" :options="suppliers" entity="supplier" :label="(s) => `${s.name} (الرصيد: ${formatCurrency(s.current_balance)} ﷼)`" placeholder="اختر المورد" :allow-empty="false" required @update:modelValue="$nextTick(onSupplierChange)" />
                            </div>
            
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">فاتورة الشراء (اختياري)</label>
                                <select 
                                    v-model="form.purchase_invoice_id"
                                    @change="() => { const inv = filteredInvoices.find((i) => i.id === form.purchase_invoice_id); form.currency_code = inv?.currency_code || ''; form.exchange_rate = ''; }" 
                                    class="w-full rounded-xl border border-slate-200 py-2 font-medium"
                                >
                                    <option value="">سداد عام على رصيد الحساب</option>
                                    <option v-for="inv in filteredInvoices" :key="inv.id" :value="inv.id">
                                        {{ inv.invoice_number }} (المتبقي: {{ formatCurrency(inv.remaining_amount) }} ﷼)
                                    </option>
                                </select>
                            </div>
            
                            <p v-if="whtRate > 0" class="col-span-full rounded-xl bg-amber-50 text-amber-800 px-3 py-2 font-bold" data-wht-note>
                                مورد غير مقيم: تُستقطع ضريبة {{ whtRate }}% = {{ formatCurrency(whtAmount) }} ﷼، والمدفوع فعليًا {{ formatCurrency((Number(form.amount) || 0) - whtAmount) }} ﷼ (تُورَّد للهيئة).
                            </p>

                            <div v-if="!form.purchase_invoice_id && openInvoices.length" class="col-span-full border border-slate-200 rounded-xl p-3 space-y-2" data-allocation>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-bold text-slate-700">توزيع المبلغ على عدة فواتير (اختياري)</span>
                                    <span class="flex items-center gap-3">
                                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" v-model="form.auto_allocate" :disabled="allocated > 0" /> الأقدم أولًا تلقائيًا</label>
                                        <button type="button" @click="distribute" data-distribute class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">وزّع حسب المبلغ</button>
                                    </span>
                                </div>
                                <div v-for="inv in openInvoices" :key="inv.id" class="flex items-center gap-3" data-allocation-row>
                                    <span class="flex-1 font-mono">{{ inv.invoice_number }}</span>
                                    <span class="text-slate-500">المتبقي {{ formatCurrency(inv.remaining_amount) }}</span>
                                    <input v-model="alloc[inv.id]" type="text" inputmode="decimal" data-num placeholder="0.00" class="w-28 rounded-lg border border-slate-200 px-2 py-1 font-mono text-center" />
                                </div>
                                <p class="text-slate-500">الموزَّع {{ formatCurrency(allocated) }} من {{ formatCurrency(form.amount) }}، والباقي يبقى دفعة على حساب المورد.</p>
                                <p v-if="form.errors.allocations" class="text-rose-600 font-bold">{{ form.errors.allocations }}</p>
                            </div>

                            <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" />
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">المبلغ المصروف <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model.number="form.amount" 
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        min="0.01" 
                                        required 
                                        placeholder="0.00" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono font-bold text-slate-900"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">تاريخ السند <span class="text-rose-500">*</span></label>
                                    <DatePicker 
                                        v-model="form.payment_date" 
                                        required 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2" />
                                </div>
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">طريقة الصرف</label>
                                    <select 
                                        v-model="form.payment_method" 
                                        class="w-full rounded-xl border border-slate-200 py-2 font-medium"
                                    >
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="cash">نقداً</option>
                                        <option value="cheque">شيك</option>
                                        <option value="card">بطاقة</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">رقم المرجع أو التحويل</label>
                                    <input 
                                        v-model="form.reference_number" 
                                        type="text" 
                                        placeholder="TRANS-XXXXX" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono"
                                    />
                                </div>
                                <div v-if="form.payment_method === 'cheque'">
                                    <label class="block font-bold text-slate-700 mb-1">تاريخ استحقاق الشيك (فارغ = حاضر)</label>
                                    <DatePicker v-model="form.cheque_due_date" class="w-full rounded-xl border border-slate-200 px-3 py-2" />
                                    <p v-if="form.errors.cheque_due_date" class="text-rose-600 mt-1">{{ form.errors.cheque_due_date }}</p>
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">البيان أو الملاحظات</label>
                                <input 
                                    v-model="form.notes" 
                                    type="text" 
                                    placeholder="ملاحظات السند..." 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2"
                                />
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                                <Link :href="route('purchases.payments.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing" 
                                    class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                                >
                                    حفظ السند
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>
