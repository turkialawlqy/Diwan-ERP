<script setup>
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
    customer_id: null, invoice_id: '', amount: '', payment_date: new Date().toISOString().substring(0, 10),
    payment_method: 'bank_transfer', bank_account_id: '', reference_number: '', notes: '', currency_code: '', exchange_rate: '', auto_allocate: true,
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
        <Head title="سندات القبض والدفعات" />

        <div class="space-y-5">
            <!-- Breadcrumbs Ribbon -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-slate-500">المبيعات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المدفوعات</span>
                    </div>

                    <button
                        v-if="$canRoute('sales.payments.store')"
                        @click="showForm = true"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold"
                    >
                        + سند قبض جديد
                    </button>

                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي سندات القبض</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_count ?? payments.total ?? 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي المقبوضات</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.total_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">سداد نقدي</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ formatCurrency(stats?.cash_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">سداد شبكة / بنكي</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-700">{{ formatCurrency(stats?.bank_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6">رقم السند</th>
                                <th class="py-4 px-6">العميل</th>
                                <th class="py-4 px-6">المبلغ</th>
                                <th class="py-4 px-6">طريقة الدفع</th>
                                <th class="py-4 px-6">تاريخ السداد</th>
                                <th class="py-4 px-6"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in payments.data" :key="p.id" class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-4 px-6 font-bold text-slate-800 font-mono">{{ p.payment_number }}</td>
                                <td class="py-4 px-6 font-semibold text-slate-700">{{ p.customer?.name || '-' }}</td>
                                <td class="py-4 px-6 font-black text-emerald-600 font-mono">{{ formatCurrency(p.amount) }} {{ p.currency_code || '﷼' }}</td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ p.payment_method === 'cash' ? 'نقداً' : p.payment_method === 'card' ? 'بطاقة / مدى' : 'تحويل بنكي' }}
                                </td>
                                <td class="py-4 px-6 text-slate-500">{{ p.payment_date }}</td>
                                <td class="py-4 px-6"><div class="flex items-center gap-3"><ShareDocument v-if="$can('sales.payments.print') && p.status !== 'cancelled'" type="receipt" :id="p.id" compact /><CancelDocument v-if="$can('sales.payments.cancel') || p.status === 'cancelled'" :url="route('sales.payments.cancel', p.id)" label="سند القبض" :status="p.status" /></div></td>
                            </tr>
                            <tr v-if="!payments.data || payments.data.length === 0">
                                <td colspan="6" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <BanknotesIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد سندات قبض مسجلة حالياً</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="payments?.links && payments.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs text-slate-500">
                        عرض <span class="font-bold text-slate-700">{{ payments.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ payments.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ payments.total || 0 }}</span> سند
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
        <Dialog :show="showForm" title="سند قبض جديد" size="md" @close="showForm = false">
            <div class="space-y-3 text-xs">
                <Field label="العميل" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" @update:model-value="loadInvoices" /></Field>
                <Field label="الفاتورة" :error="form.errors.invoice_id" hint="اتركه «توزيع تلقائي» لتسديد أقدم الفواتير المفتوحة بنفس العملة">
                    <select v-model="form.invoice_id" @change="pickInvoice" class="w-full rounded-xl border-slate-200 text-xs">
                        <option value="">توزيع تلقائي على الفواتير المفتوحة</option>
                        <option v-for="inv in openInvoices" :key="inv.id" :value="inv.id">{{ inv.invoice_number }} — متبقي {{ Number(inv.remaining_amount).toFixed(2) }} {{ inv.currency_code || '' }}</option>
                    </select>
                </Field>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="المبلغ" :error="form.errors.amount"><input v-model="form.amount" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field label="التاريخ" :error="form.errors.payment_date"><input v-model="form.payment_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="طريقة الدفع" :error="form.errors.payment_method">
                        <select v-model="form.payment_method" class="w-full rounded-xl border-slate-200 text-xs">
                            <option value="bank_transfer">تحويل بنكي</option><option value="cash">نقداً</option><option value="card">بطاقة / مدى</option><option value="cheque">شيك</option>
                        </select>
                    </Field>
                    <Field label="الصندوق / البنك" :error="form.errors.bank_account_id">
                        <select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs">
                            <option value="">الافتراضي</option>
                            <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </Field>
                </div>
                <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" />
                <Field label="رقم المرجع"><input v-model="form.reference_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="ملاحظات"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <p v-if="form.errors.allocations" class="text-rose-600">{{ form.errors.allocations }}</p>
            </div>
            <template #footer>
                <button type="button" @click="showForm = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="submitPayment" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">حفظ السند</button>
            </template>
        </Dialog>
    </AppLayout>
</template>
