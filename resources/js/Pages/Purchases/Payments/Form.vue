<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { today } from '@/utils/format';

const props = defineProps({ suppliers: Array, unpaidInvoices: Array });

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
});
useUnsavedGuard(form);

const filteredInvoices = ref([]);

const onSupplierChange = () => {
    filteredInvoices.value = props.unpaidInvoices.filter((i) => i.supplier_id === form.supplier_id);
    form.purchase_invoice_id = '';
};
onSupplierChange();

const formatCurrency = (val) => Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const submitPayment = () => form.post(route('purchases.payments.store'));
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
