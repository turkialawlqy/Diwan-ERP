<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    PlusIcon,
    TrashIcon,
    ArrowPathIcon,
    InformationCircleIcon,
    CheckIcon,
    DocumentTextIcon,
    UserPlusIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    customers: Array,
    branches: Array,
    suggestedNumber: String,
    company: Object,
    defaultTaxRate: Number,
});

const today = new Date().toISOString().split('T')[0];

const form = useForm({
    customer_id: props.customers?.[0]?.id || '',
    branch_id: props.branches?.[0]?.id || '',
    invoice_number: props.suggestedNumber || 'INV-26-1-000001',
    reference_number: '',
    invoice_date: today,
    due_date: today,
    supply_date: today,
    payment_method: 'cash',
    payment_status: 'paid',
    paid_amount: 0,
    notes: 'شكراً لتعاملكم معنا.',
    terms: 'تستحق هذه الفاتورة خلال المدة المتفق عليها. تخضع لضريبة القيمة المضافة وفق أنظمة هيئة الزكاة والضريبة والجمارك بالمملكة العربية السعودية.',
    items: [
        {
            item_name: 'خدمات استشارية وتقنية',
            description: 'تقديم خدمات الاستشارات والدعم الفني',
            quantity: 1,
            unit_price: 1000,
            discount: 0,
            tax_rate: props.defaultTaxRate || 15.00,
        }
    ]
});

// Row calculations
const getItemSubtotal = (item) => {
    const qty = Number(item.quantity) || 0;
    const price = Number(item.unit_price) || 0;
    const discount = Number(item.discount) || 0;
    return Math.max(0, (qty * price) - discount);
};

const getItemTax = (item) => {
    const subtotal = getItemSubtotal(item);
    const rate = Number(item.tax_rate) || 0;
    return subtotal * (rate / 100);
};

const getItemTotal = (item) => {
    return getItemSubtotal(item) + getItemTax(item);
};

// Global totals
const subtotal = computed(() => {
    return form.items.reduce((acc, item) => acc + getItemSubtotal(item), 0);
});

const totalTax = computed(() => {
    return form.items.reduce((acc, item) => acc + getItemTax(item), 0);
});

const totalDiscount = computed(() => {
    return form.items.reduce((acc, item) => acc + (Number(item.discount) || 0), 0);
});

const grandTotal = computed(() => {
    return subtotal.value + totalTax.value;
});

const remainingBalance = computed(() => {
    if (form.payment_status === 'paid') return 0;
    const paid = Number(form.paid_amount) || 0;
    return Math.max(0, grandTotal.value - paid);
});

const addItem = () => {
    form.items.push({
        item_name: '',
        description: '',
        quantity: 1,
        unit_price: 0,
        discount: 0,
        tax_rate: props.defaultTaxRate || 15.00,
    });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const selectedCustomer = computed(() => {
    return props.customers?.find(c => c.id === form.customer_id);
});

const submitInvoice = () => {
    if (form.payment_status === 'paid') {
        form.paid_amount = grandTotal.value;
    }
    form.post(route('sales.invoices.store'));
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
        <Head title="إنشاء فاتورة مبيعات جديدة" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header Ribbon & Action Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <Link :href="route('sales.invoices.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">الفواتير</Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">إنشاء فاتورة</span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.invoices.index')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 font-medium text-xs sm:text-sm transition-all"
                    >
                        إلغاء
                    </Link>
                    <button 
                        @click="submitInvoice"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>{{ form.processing ? 'جاري الإصدار...' : 'حفظ وإصدار الفاتورة' }}</span>
                    </button>
                </div>
            </div>

            <!-- Validation Errors Alert -->
            <div v-if="Object.keys(form.errors).length > 0" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold">
                <p class="font-bold mb-1">يرجى تصحيح الأخطاء التالية:</p>
                <ul class="list-disc list-inside space-y-0.5 text-rose-700 text-xs">
                    <li v-for="(err, key) in form.errors" :key="key">{{ err }}</li>
                </ul>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Invoice Form (Left 2 Columns) -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Invoice Details Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-5">
                        <h2 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
                            <DocumentTextIcon class="w-5 h-5 text-indigo-600" />
                            <span>البيانات الأساسية للفاتورة</span>
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Customer Select -->
                            <div class="sm:col-span-2">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-xs font-bold text-slate-700">
                                        العميل <span class="text-rose-500">*</span>
                                    </label>
                                    <span v-if="selectedCustomer" class="text-xs text-slate-400">
                                        الرصيد الحالي: <strong class="text-indigo-600">{{ formatCurrency(selectedCustomer.current_balance) }} ﷼</strong>
                                    </span>
                                </div>
                                <select 
                                    v-model="form.customer_id" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-medium"
                                >
                                    <option value="" disabled>اختر العميل...</option>
                                    <option v-for="c in customers" :key="c.id" :value="c.id">
                                        {{ c.name }} {{ c.company_name ? `(${c.company_name})` : '' }} - {{ c.phone || 'بدون هاتف' }}
                                    </option>
                                </select>
                            </div>

                            <!-- Invoice Number -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم الفاتورة <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.invoice_number"
                                    type="text" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono font-bold text-indigo-700 bg-slate-50/50"
                                />
                            </div>

                            <!-- Reference Number -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">الرقم المرجعي / أمر الشراء</label>
                                <input 
                                    v-model="form.reference_number"
                                    type="text" 
                                    placeholder="مثال: PO-2026-09"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Invoice Date -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ الإصدار <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.invoice_date"
                                    type="date" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Supply Date -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ التوريد (الزكاة والضريبة)</label>
                                <input 
                                    v-model="form.supply_date"
                                    type="date" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Payment Method -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">طريقة الدفع <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_method" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                >
                                    <option value="cash">نقداً (Cash)</option>
                                    <option value="card">مدى / بطاقة ائتمان (Card/Mada)</option>
                                    <option value="bank_transfer">تحويل بنكي (Bank Transfer)</option>
                                    <option value="credit">آجل / ذمم (Credit)</option>
                                </select>
                            </div>

                            <!-- Payment Status -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">حالة السداد <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_status" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                >
                                    <option value="paid">مدفوعة بالكامل</option>
                                    <option value="unpaid">غير مدفوعة (آجل)</option>
                                    <option value="partially_paid">مدفوعة جزئياً</option>
                                </select>
                            </div>

                            <!-- Partially Paid Amount (if selected) -->
                            <div v-if="form.payment_status === 'partially_paid'" class="sm:col-span-2">
                                <label class="block text-xs font-bold text-amber-700 mb-1.5">المبلغ المدفوع مقدماً</label>
                                <input 
                                    v-model="form.paid_amount"
                                    type="number" 
                                    step="0.01" 
                                    min="0" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-amber-300 px-3 py-2.5 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 bg-amber-50/40"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Line Items Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                <span>بنود الفاتورة والخدمات</span>
                                <span class="px-2 py-0.5 rounded-full text-2xs bg-indigo-50 text-indigo-700 font-bold">
                                    {{ form.items.length }} بنود
                                </span>
                            </h2>
                            <button 
                                type="button"
                                @click="addItem"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>إضافة بند</span>
                            </button>
                        </div>

                        <!-- Items Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-500 font-bold">
                                        <th class="py-2.5 px-2 text-center w-8">#</th>
                                        <th class="py-2.5 px-3 min-w-[200px]">البند / الخدمة</th>
                                        <th class="py-2.5 px-2 w-20">الكمية</th>
                                        <th class="py-2.5 px-2 w-24">السعر</th>
                                        <th class="py-2.5 px-2 w-20">الخصم</th>
                                        <th class="py-2.5 px-2 w-16 text-center">الضريبة</th>
                                        <th class="py-2.5 px-3 w-28 text-end">الإجمالي</th>
                                        <th class="py-2.5 px-2 w-8 text-center"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(item, idx) in form.items" :key="idx" class="align-top">
                                        <td class="py-3 px-2 text-center text-slate-400 font-mono pt-4">
                                            {{ idx + 1 }}
                                        </td>
                                        <td class="py-3 px-3 space-y-1.5">
                                            <input 
                                                v-model="item.item_name"
                                                type="text" 
                                                placeholder="اسم المنتج أو الخدمة..."
                                                class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 focus:border-indigo-500 font-semibold"
                                                required
                                            />
                                            <input 
                                                v-model="item.description"
                                                type="text" 
                                                placeholder="وصف إضافي اختياري..."
                                                class="w-full text-2xs rounded-lg border border-slate-100 px-2.5 py-1 text-slate-500 focus:border-indigo-500"
                                            />
                                        </td>
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.quantity"
                                                type="number" 
                                                step="0.01" 
                                                min="0.01" 
                                                class="w-full text-xs text-center rounded-lg border border-slate-200 px-2 py-1.5 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.unit_price"
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                class="w-full text-xs text-start rounded-lg border border-slate-200 px-2 py-1.5 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.discount"
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                class="w-full text-xs text-start rounded-lg border border-slate-200 px-2 py-1.5 focus:border-indigo-500 font-mono"
                                            />
                                        </td>
                                        <td class="py-3 px-2 text-center pt-4 font-bold text-slate-500 text-2xs">
                                            %15
                                        </td>
                                        <td class="py-3 px-3 text-end pt-4 font-black text-slate-800 font-mono whitespace-nowrap">
                                            {{ formatCurrency(getItemTotal(item)) }}
                                        </td>
                                        <td class="py-3 px-2 text-center pt-3">
                                            <button 
                                                type="button"
                                                @click="removeItem(idx)"
                                                :disabled="form.items.length <= 1"
                                                class="p-1 text-slate-300 hover:text-rose-600 disabled:opacity-20 transition-colors"
                                                title="حذف البند"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Add Item Bottom Bar -->
                        <button 
                            type="button"
                            @click="addItem"
                            class="w-full py-2.5 rounded-xl border border-dashed border-slate-200 hover:border-indigo-300 bg-slate-50/50 hover:bg-indigo-50/30 text-slate-500 hover:text-indigo-600 font-bold text-xs transition-colors flex items-center justify-center gap-1.5"
                        >
                            <PlusIcon class="w-4 h-4 stroke-2" />
                            <span>إضافة بند جديد للفاتورة</span>
                        </button>
                    </div>

                    <!-- Notes & Terms -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-2">
                            <label class="block text-xs font-bold text-slate-700">ملاحظات الفاتورة</label>
                            <textarea 
                                v-model="form.notes"
                                rows="3"
                                class="w-full text-xs rounded-xl border border-slate-200 p-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none text-slate-600"
                            ></textarea>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-2">
                            <label class="block text-xs font-bold text-slate-700">الشروط والأحكام</label>
                            <textarea 
                                v-model="form.terms"
                                rows="3"
                                class="w-full text-xs rounded-xl border border-slate-200 p-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none text-slate-600"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- Invoice Summary & ZATCA Card (Right Column) -->
                <div class="space-y-6">
                    <!-- Totals Summary Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4 sticky top-24">
                        <h3 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3">
                            ملخص الحسابات والضريبة
                        </h3>

                        <div class="space-y-3 text-xs">
                            <!-- Subtotal -->
                            <div class="flex items-center justify-between text-slate-600">
                                <span>المجموع قبل الضريبة</span>
                                <span class="font-bold text-slate-800 font-mono">{{ formatCurrency(subtotal) }} ﷼</span>
                            </div>

                            <!-- Discount -->
                            <div v-if="totalDiscount > 0" class="flex items-center justify-between text-rose-600">
                                <span>إجمالي الخصم</span>
                                <span class="font-bold font-mono">- {{ formatCurrency(totalDiscount) }} ﷼</span>
                            </div>

                            <!-- VAT -->
                            <div class="flex items-center justify-between text-slate-600">
                                <div class="flex items-center gap-1">
                                    <span>ضريبة القيمة المضافة</span>
                                    <span class="px-1.5 py-0.5 rounded text-3xs font-bold bg-indigo-50 text-indigo-700">15%</span>
                                </div>
                                <span class="font-bold text-slate-800 font-mono">{{ formatCurrency(totalTax) }} ﷼</span>
                            </div>

                            <!-- Divider -->
                            <div class="border-t border-slate-200 pt-3">
                                <div class="flex items-center justify-between text-slate-900">
                                    <span class="text-sm font-black">الإجمالي شامل الضريبة</span>
                                    <span class="text-base font-black text-indigo-600 font-mono">{{ formatCurrency(grandTotal) }} ﷼</span>
                                </div>
                            </div>

                            <!-- Payment breakdown if not fully paid -->
                            <div v-if="form.payment_status !== 'paid'" class="bg-slate-50 rounded-xl p-3 space-y-2 border border-slate-100">
                                <div class="flex items-center justify-between text-2xs text-slate-500">
                                    <span>المسدد</span>
                                    <span class="font-bold text-emerald-600 font-mono">{{ formatCurrency(form.paid_amount) }} ﷼</span>
                                </div>
                                <div class="flex items-center justify-between text-2xs text-slate-500">
                                    <span>المتبقي (آجل)</span>
                                    <span class="font-bold text-rose-600 font-mono">{{ formatCurrency(remainingBalance) }} ﷼</span>
                                </div>
                            </div>
                        </div>

                        <!-- ZATCA Badge Info -->
                        <div class="p-3 rounded-xl bg-emerald-50/70 border border-emerald-200/60 text-emerald-900 text-2xs space-y-1.5">
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                <span>متوافق مع هيئة الزكاة والضريبة (ZATCA)</span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                سيتم تلقائياً تشفير وتوليد رمز الاستجابة السريعة (QR Code) المتوافق مع المرحلة الأولى والثانية للفوترة الإلكترونية السعودية فور حفظ الفاتورة.
                            </p>
                        </div>

                        <!-- Big Submit Button -->
                        <button 
                            @click="submitInvoice"
                            :disabled="form.processing"
                            class="w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center justify-center gap-2 disabled:opacity-50"
                        >
                            <CheckIcon class="w-4 h-4 stroke-2" />
                            <span>{{ form.processing ? 'جاري الحفظ...' : 'حفظ وإصدار الفاتورة' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
