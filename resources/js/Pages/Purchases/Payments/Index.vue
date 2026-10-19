<script setup>
import CancelDocument from '@/Components/CancelDocument.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import CurrencyPicker from '@/Components/Documents/CurrencyPicker.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BanknotesIcon,
    XMarkIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    payments: Object,
    suppliers: Array,
    unpaidInvoices: Array,
    stats: Object,
    filters: Object,
});

const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');

const form = useForm({
    supplier_id: props.suppliers?.[0]?.id || '',
    purchase_invoice_id: '',
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    reference_number: '',
    notes: '',
    currency_code: '',
    exchange_rate: '',
});

const filteredInvoices = ref([]);

const onSupplierChange = () => {
    filteredInvoices.value = props.unpaidInvoices.filter(i => i.supplier_id === form.supplier_id);
    form.purchase_invoice_id = '';
};

const openAddModal = () => {
    form.reset();
    form.supplier_id = props.suppliers?.[0]?.id || '';
    onSupplierChange();
    isAddModalOpen.value = true;
};

const submitPayment = () => {
    form.post(route('purchases.payments.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const handleSearch = () => {
    router.get(route('purchases.payments.index'), {
        search: search.value || undefined,
    }, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="سندات صرف الموردين" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleSearch"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المشتريات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">سندات صرف الموردين</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>سند صرف جديد</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">عدد سندات الصرف</span>
                    <h3 class="text-xl font-black text-slate-900 mt-1">{{ stats?.total_payments_count || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">إجمالي المبالغ المصروفة</span>
                    <h3 class="text-xl font-black text-emerald-600 font-mono mt-1">{{ formatCurrency(stats?.total_paid_amount) }} <span class="text-xs font-normal text-slate-400">﷼</span></h3>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6">رقم السند</th>
                                <th class="py-4 px-6">المورد</th>
                                <th class="py-4 px-6">فاتورة الشراء</th>
                                <th class="py-4 px-6">تاريخ السند</th>
                                <th class="py-4 px-6">طريقة الصرف</th>
                                <th class="py-4 px-6">رقم المرجع / التحويل</th>
                                <th class="py-4 px-6 text-end">المبلغ المصروف</th>
                                <th class="py-4 px-6"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="pay in payments.data" 
                                :key="pay.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ pay.payment_number }}
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ pay.supplier?.name || '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    <span v-if="pay.purchase_invoice">
                                        {{ pay.purchase_invoice.invoice_number }}
                                    </span>
                                    <span v-else class="text-slate-400">سند عام على الحساب</span>
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ pay.payment_date ? pay.payment_date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6">
                                    <span class="px-2.5 py-0.5 rounded-full text-3xs font-bold bg-slate-100 text-slate-700">
                                        {{ pay.payment_method === 'bank_transfer' ? 'تحويل بنكي' : (pay.payment_method === 'cash' ? 'نقداً' : pay.payment_method) }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-500">
                                    {{ pay.reference_number || '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono font-black text-slate-900 text-end">
                                    {{ formatCurrency(pay.amount) }} <span class="text-xs font-normal">﷼</span>
                                </td>
                                <td class="py-4 px-6"><div class="flex items-center gap-3"><ShareDocument v-if="$can('purchases.payments.print') && pay.status !== 'cancelled'" type="payment" :id="pay.id" compact /><CancelDocument v-if="$can('purchases.payments.cancel') || pay.status === 'cancelled'" :url="route('purchases.payments.cancel', pay.id)" label="سند الصرف" :status="pay.status" /></div></td>
                            </tr>
                            <tr v-if="!payments.data || payments.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا توجد سندات صرف مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="payments?.links && payments.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ payments.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ payments.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ payments.total }}</span> سند
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in payments.links" :key="i">
                            <Link 
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50',
                                    'min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label"
                            />
                            <span 
                                v-else
                                class="min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>

            <!-- Add Payment Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-indigo-600" />
                            <span>تسجيل سند صرف لمورد</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitPayment" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">المورد <span class="text-rose-500">*</span></label>
                            <select 
                                v-model="form.supplier_id" 
                                @change="onSupplierChange"
                                required 
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 font-medium"
                            >
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                    {{ s.name }} (الرصيد: {{ formatCurrency(s.current_balance) }} ﷼)
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">فاتورة الشراء (اختياري)</label>
                            <select 
                                v-model="form.purchase_invoice_id"
                                @change="() => { const inv = filteredInvoices.find((i) => i.id === form.purchase_invoice_id); form.currency_code = inv?.currency_code || ''; form.exchange_rate = ''; }" 
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 font-medium"
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
                                    v-model="form.amount" 
                                    type="number" 
                                    step="0.01" 
                                    min="0.01" 
                                    required 
                                    placeholder="0.00" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono font-bold text-slate-900"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">تاريخ السند <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.payment_date" 
                                    type="date" 
                                    required 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">طريقة الصرف</label>
                                <select 
                                    v-model="form.payment_method" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-medium"
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
                            <button 
                                type="button" 
                                @click="isAddModalOpen = false" 
                                class="px-4 py-2 border border-slate-200 rounded-xl hover:bg-slate-50 text-slate-600 font-semibold"
                            >
                                إلغاء
                            </button>
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
            </div>
        </div>
    </AppLayout>
</template>
