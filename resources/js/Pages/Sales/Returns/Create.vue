<script setup>
import { taxRate } from '@/utils/tax';
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    ArrowUturnLeftIcon,
    CheckIcon,
    DocumentTextIcon,
    UserIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    customers: Array,
    recentInvoices: Array,
    selectedInvoice: Object,
    suggestedNumber: String,
    company: Object,
    defaultTaxRate: Number,
});

const today = new Date().toISOString().split('T')[0];

const form = useForm({
    invoice_id: props.selectedInvoice?.id || '',
    customer_id: props.selectedInvoice?.customer_id || props.customers?.[0]?.id || '',
    credit_note_number: props.suggestedNumber || 'CN-26-000001',
    credit_note_date: today,
    subtotal: props.selectedInvoice ? Number(props.selectedInvoice.subtotal) : 0,
    tax_amount: props.selectedInvoice ? Number(props.selectedInvoice.tax_amount) : 0,
    total: props.selectedInvoice ? Number(props.selectedInvoice.total) : 0,
    reason: '',
    status: 'approved',
    refund_method: 'credit',
    // Item level return (goods back to stock) when the invoice is known.
    items: (props.selectedInvoice?.items || []).map((i) => ({
        invoice_item_id: i.id, name: i.item_name, returnable: Number(i.returnable_quantity ?? i.quantity),
        unit_net: Number(i.quantity) ? Number(i.subtotal ?? (i.quantity * i.unit_price - (i.discount || 0))) / Number(i.quantity) : 0,
        tax_rate: Number(i.tax_rate), quantity: 0, return_to_stock: true,
    })),
});
const itemMode = ref(form.items.length > 0);
const itemsTotal = computed(() => form.items.reduce((s, i) => s + Number(i.quantity || 0) * i.unit_net * (1 + i.tax_rate / 100), 0));

// Watch invoice selection changes
watch(() => form.invoice_id, (newInvoiceId) => {
    if (!newInvoiceId) return;
    if (newInvoiceId != props.selectedInvoice?.id) {
        router.get(route('sales.returns.create'), { invoice_id: newInvoiceId });
        return;
    }
    const inv = props.recentInvoices?.find(i => i.id == newInvoiceId);
    if (inv) {
        form.customer_id = inv.customer_id;
        // Calculate subtotal & tax if total is available
        const tot = Number(inv.total) || 0;
        const sub = Math.round((tot / (1 + (props.defaultTaxRate ?? taxRate()) / 100)) * 100) / 100;
        const tax = Math.round((tot - sub) * 100) / 100;
        form.subtotal = sub;
        form.tax_amount = tax;
        form.total = tot;
    }
});

// Auto calculate tax when subtotal changes
const onSubtotalChange = () => {
    const sub = Number(form.subtotal) || 0;
    const rate = (props.defaultTaxRate ?? taxRate()) / 100;
    form.tax_amount = Math.round(sub * rate * 100) / 100;
    form.total = Math.round((sub + form.tax_amount) * 100) / 100;
};

// Auto calculate subtotal when total changes
const onTotalChange = () => {
    const tot = Number(form.total) || 0;
    const sub = Math.round((tot / (1 + (props.defaultTaxRate ?? taxRate()) / 100)) * 100) / 100;
    form.subtotal = sub;
    form.tax_amount = Math.round((tot - sub) * 100) / 100;
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const selectedCustomer = computed(() => {
    return props.customers?.find(c => c.id == form.customer_id);
});

const submit = () => {
    form.transform((d) => (itemMode.value
        ? { ...d, items: d.items.filter((i) => Number(i.quantity) > 0).map(({ invoice_item_id, quantity, return_to_stock }) => ({ invoice_item_id, quantity, return_to_stock })) }
        : { ...d, items: [] })).post(route('sales.returns.store'));
};
</script>

<template>
    <AppLayout>
        <Head title="إصدار إشعار دائن / مرتجع مبيعات" />

        <div class="space-y-6 w-full pb-12">
            <!-- Breadcrumbs Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                    <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                        <HomeIcon class="w-4 h-4" />
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-slate-500">المبيعات</span>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <Link :href="route('sales.returns.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">
                        المرتجعات
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-indigo-600 font-bold">إصدار إشعار دائن</span>
                </div>

                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.returns.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs sm:text-sm font-bold transition-colors"
                    >
                        إلغاء
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-rose-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>اعتماد وإصدار الإشعار</span>
                    </button>
                </div>
            </div>

            <!-- Form Body -->
            <form @submit.prevent="submit" class="space-y-6">
                <div v-if="selectedInvoice" class="bg-white border border-slate-200 rounded-2xl p-4">
                    <label class="flex items-center gap-2 text-xs font-bold mb-3"><input v-model="itemMode" type="checkbox" class="rounded" /> إرجاع أصناف (تعود للمخزون وتُعكس تكلفتها)</label>
                    <div class="overflow-x-auto" v-if="itemMode"><table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">القابل للإرجاع</th><th class="px-3 py-2">صافي سعر الوحدة</th><th class="px-3 py-2 w-32">الكمية المرتجعة</th><th class="px-3 py-2">يعود للمخزون</th></tr></thead>
                        <tbody>
                            <tr v-for="i in form.items" :key="i.invoice_item_id" class="border-t border-slate-100">
                                <td class="px-3 py-2">{{ i.name }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ i.returnable }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ formatCurrency(i.unit_net) }}</td>
                                <td class="px-3 py-2"><input v-model="i.quantity" type="number" min="0" :max="i.returnable" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                                <td class="px-3 py-2 text-center"><input v-model="i.return_to_stock" type="checkbox" class="rounded" /></td>
                            </tr>
                        </tbody>
                    </table></div>
                    <p v-if="itemMode" class="text-xs mt-2">إجمالي المرتجع شامل الضريبة: <b class="font-mono">{{ formatCurrency(itemsTotal) }}</b></p>
                    <p v-if="form.errors.items" class="text-xs text-rose-600 mt-1">{{ form.errors.items }}</p>
                    <p v-if="form.errors.lines" class="text-xs text-rose-600 mt-1">{{ form.errors.lines }}</p>
                </div>
                <!-- Selected Invoice Info (if available) -->
                <div v-if="selectedInvoice" class="bg-indigo-50/70 border border-indigo-200 rounded-2xl p-5 text-xs text-indigo-900 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-indigo-200/60 pb-3">
                        <div class="flex items-center gap-2">
                            <DocumentTextIcon class="w-5 h-5 text-indigo-600" />
                            <span class="font-bold text-sm">مرتجع مرتبط بالفاتورة الضريبية:</span>
                            <span class="font-mono font-black text-indigo-700">{{ selectedInvoice.invoice_number }}</span>
                        </div>
                        <span class="text-indigo-700 font-medium">تاريخ الفاتورة: {{ selectedInvoice.invoice_date }}</span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-2xs sm:text-xs">
                        <div>
                            <span class="text-indigo-600/80 block">العميل:</span>
                            <strong class="text-slate-800">{{ selectedInvoice.customer?.name }}</strong>
                        </div>
                        <div>
                            <span class="text-indigo-600/80 block">قيمة الفاتورة الأصلية:</span>
                            <strong class="font-mono text-slate-800">{{ formatCurrency(selectedInvoice.total) }} ﷼</strong>
                        </div>
                        <div>
                            <span class="text-indigo-600/80 block">الضريبة المضمنة:</span>
                            <strong class="font-mono text-slate-800">{{ formatCurrency(selectedInvoice.tax_amount) }} ﷼</strong>
                        </div>
                        <div>
                            <span class="text-indigo-600/80 block">حالة السداد:</span>
                            <strong class="text-slate-800">{{ selectedInvoice.payment_status === 'paid' ? 'مدفوعة' : 'غير مدفوعة' }}</strong>
                        </div>
                    </div>

                    <!-- Items Preview -->
                    <div v-if="selectedInvoice.items && selectedInvoice.items.length > 0" class="pt-2">
                        <p class="font-bold text-2xs text-indigo-800 mb-1">بنود الفاتورة الأصلية للرجوع إليها:</p>
                        <div class="bg-white/90 rounded-xl p-3 border border-indigo-100 divide-y divide-slate-100 max-h-36 overflow-y-auto">
                            <div v-for="it in selectedInvoice.items" :key="it.id" class="py-1.5 flex justify-between items-center text-2xs">
                                <span>{{ it.item_name }} ({{ Number(it.quantity) }} × {{ formatCurrency(it.unit_price) }} ﷼)</span>
                                <span class="font-mono font-bold text-slate-700">{{ formatCurrency(it.total) }} ﷼</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Form Inputs -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Right Card: Details & Identification -->
                    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-2xs space-y-4">
                        <div class="pb-3 border-b border-slate-100">
                            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                                <ArrowUturnLeftIcon class="w-4 h-4 text-rose-600" />
                                <span>بيانات الإشعار الدائن</span>
                            </h3>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم الإشعار الدائن *</label>
                            <input 
                                v-model="form.credit_note_number"
                                type="text"
                                class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                                required
                            />
                            <p v-if="form.errors.credit_note_number" class="text-2xs text-rose-500 mt-1">{{ form.errors.credit_note_number }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ الإشعار الدائن *</label>
                            <input 
                                v-model="form.credit_note_date"
                                type="date"
                                class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                                required
                            />
                            <p v-if="form.errors.credit_note_date" class="text-2xs text-rose-500 mt-1">{{ form.errors.credit_note_date }}</p>
                        </div>

                        <!-- Invoice Picker if not pre-selected -->
                        <div v-if="!selectedInvoice">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">الفاتورة الأصلية (اختياري)</label>
                            <select 
                                v-model="form.invoice_id"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                            >
                                <option value="">-- بدون ربط بفاتورة سابقة --</option>
                                <option v-for="inv in recentInvoices" :key="inv.id" :value="inv.id">
                                    {{ inv.invoice_number }} - {{ inv.customer?.name }} ({{ formatCurrency(inv.total) }} ﷼)
                                </option>
                            </select>
                        </div>

                        <!-- Customer Selection -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">العميل *</label>
                            <select 
                                v-model="form.customer_id"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                                required
                            >
                                <option value="" disabled>-- اختر العميل --</option>
                                <option v-for="c in customers" :key="c.id" :value="c.id">
                                    {{ c.name }} {{ c.company_name ? `(${c.company_name})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.customer_id" class="text-2xs text-rose-500 mt-1">{{ form.errors.customer_id }}</p>
                        </div>

                        <!-- Customer info preview -->
                        <div v-if="selectedCustomer" class="p-3 bg-slate-50 rounded-xl text-xs space-y-1 text-slate-600">
                            <p class="flex justify-between">
                                <span>الرصيد الحالي للعميل:</span>
                                <span class="font-mono font-bold" :class="selectedCustomer.current_balance > 0 ? 'text-amber-600' : 'text-slate-800'">
                                    {{ formatCurrency(selectedCustomer.current_balance) }} ﷼
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Left Card: Amounts & Reason -->
                    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-2xs space-y-4">
                        <div class="pb-3 border-b border-slate-100">
                            <h3 class="text-sm font-black text-slate-800">المبالغ وسبب الإرجاع</h3>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">سبب الإرجاع / الإشعار الدائن *</label>
                            <textarea 
                                v-model="form.reason"
                                rows="3"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 bg-slate-50/50"
                                placeholder="مثال: تلف في البضاعة أثناء النقل، خطأ في المواصفات، رغبة العميل في الإلغاء..."
                                required
                            ></textarea>
                            <p v-if="form.errors.reason" class="text-2xs text-rose-500 mt-1">{{ form.errors.reason }}</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">المبلغ قبل الضريبة (﷼) *</label>
                                <input 
                                    v-model.number="form.subtotal"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    @input="onSubtotalChange"
                                    class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                                    required
                                />
                                <p v-if="form.errors.subtotal" class="text-2xs text-rose-500 mt-1">{{ form.errors.subtotal }}</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $taxLabel() }}</label>
                                <input 
                                    v-model.number="form.tax_amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">الإجمالي المسترد شامل الضريبة (﷼) *</label>
                            <input 
                                v-model.number="form.total"
                                type="number"
                                min="0.01"
                                step="0.01"
                                @input="onTotalChange"
                                class="w-full font-mono text-base font-black text-rose-600 rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-rose-50/30"
                                required
                            />
                            <p v-if="form.errors.total" class="text-2xs text-rose-500 mt-1">{{ form.errors.total }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">طريقة المعالجة المحاسبية</label>
                            <select 
                                v-model="form.status"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-rose-500 focus:ring-rose-500 py-2.5 bg-slate-50/50"
                            >
                                <option value="approved">خصم من رصيد العميل (قيد بالحساب)</option>
                                <option value="refunded">استرداد نقدي فوري للعميل</option>
                                <option value="draft">حفظ كمسودة للمراجعة</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Submit Action Buttons -->
                <div class="flex justify-end gap-3 pt-2">
                    <Link 
                        :href="route('sales.returns.index')"
                        class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs sm:text-sm font-bold transition-colors"
                    >
                        إلغاء
                    </Link>
                    <button 
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-rose-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>اعتماد وإصدار الإشعار الدائن</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
