<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    TrashIcon,
    HomeIcon,
    ChevronRightIcon,
    CheckIcon,
    BuildingOfficeIcon,
    ArchiveBoxIcon,
    CalendarIcon,
    CreditCardIcon,
    ReceiptPercentIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    suppliers: Array,
    warehouses: Array,
    branches: Array,
    products: Array,
    suggestedNumber: String,
    defaultTaxRate: Number,
});

const form = useForm({
    supplier_id: props.suppliers?.[0]?.id || '',
    warehouse_id: props.warehouses?.[0]?.id || '',
    branch_id: props.branches?.[0]?.id || '',
    invoice_number: props.suggestedNumber || '',
    supplier_invoice_number: '',
    invoice_date: new Date().toISOString().split('T')[0],
    due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    supply_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    payment_status: 'unpaid',
    paid_amount: 0,
    notes: '',
    items: [
        {
            product_id: props.products?.[0]?.id || null,
            item_name: props.products?.[0]?.name_ar || 'بند توريد جديد',
            quantity: 1,
            unit_price: props.products?.[0]?.purchase_price || 0,
            discount: 0,
            tax_rate: 15.00,
        }
    ],
});

const selectedSupplier = computed(() => {
    return props.suppliers.find(s => s.id === form.supplier_id);
});

const onProductSelect = (index) => {
    const pId = form.items[index].product_id;
    if (!pId) return;
    const prod = props.products.find(p => p.id === pId);
    if (prod) {
        form.items[index].item_name = prod.name_ar;
        form.items[index].unit_price = Number(prod.purchase_price) || 0;
        form.items[index].tax_rate = Number(prod.tax_rate) || 15.00;
    }
};

const addItem = () => {
    form.items.push({
        product_id: null,
        item_name: '',
        quantity: 1,
        unit_price: 0,
        discount: 0,
        tax_rate: 15.00,
    });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const calculateLineSubtotal = (item) => {
    return (Number(item.quantity || 0) * Number(item.unit_price || 0)) - Number(item.discount || 0);
};

const calculateLineTax = (item) => {
    return calculateLineSubtotal(item) * (Number(item.tax_rate || 0) / 100);
};

const calculateLineTotal = (item) => {
    return calculateLineSubtotal(item) + calculateLineTax(item);
};

const subtotal = computed(() => {
    return form.items.reduce((sum, item) => sum + calculateLineSubtotal(item), 0);
});

const taxAmount = computed(() => {
    return form.items.reduce((sum, item) => sum + calculateLineTax(item), 0);
});

const total = computed(() => {
    return subtotal.value + taxAmount.value;
});

const submit = () => {
    form.post(route('purchases.invoices.store'));
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
        <Head title="إنشاء فاتورة شراء جديدة - الأستاذ" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header Ribbon -->
            <div class="flex items-center justify-between">
                <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs font-medium text-slate-600 shadow-2xs">
                    <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                        <HomeIcon class="w-4 h-4" />
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <Link :href="route('purchases.invoices.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">فواتير الشراء</Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-indigo-600 font-bold">إنشاء فاتورة شراء</span>
                </div>

                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('purchases.invoices.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50"
                    >
                        إلغاء
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>حفظ الفاتورة وتحديث المخزون</span>
                    </button>
                </div>
            </div>

            <!-- Main Form Layout -->
            <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left 2 Cols: Main Invoice Details & Items -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Information Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2 pb-2 border-b border-slate-100">
                            <BuildingOfficeIcon class="w-4 h-4 text-indigo-600" />
                            <span>بيانات المورد والمستودع</span>
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <!-- Supplier Selection -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">المورد <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.supplier_id" 
                                    required
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                                >
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                        {{ s.name }} (الرصيد: {{ formatCurrency(s.current_balance) }} ﷼)
                                    </option>
                                </select>
                            </div>

                            <!-- Warehouse Selection (Increments Stock Here) -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">المستودع المستلم <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.warehouse_id" 
                                    required
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                                >
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                                        {{ w.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Invoice Number -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">رقم الفاتورة بالنظام <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.invoice_number" 
                                    type="text" 
                                    required 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono font-bold text-indigo-700 focus:border-indigo-500"
                                />
                            </div>

                            <!-- Supplier's Own Invoice Number -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">رقم فاتورة المورد (المرجع)</label>
                                <input 
                                    v-model="form.supplier_invoice_number" 
                                    type="text" 
                                    placeholder="مثال: INV-SUP-2026-99" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-xs">
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">تاريخ الفاتورة</label>
                                <input 
                                    v-model="form.invoice_date" 
                                    type="date" 
                                    required 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">تاريخ الاستحقاق</label>
                                <input 
                                    v-model="form.due_date" 
                                    type="date" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">تاريخ استلام البضاعة</label>
                                <input 
                                    v-model="form.supply_date" 
                                    type="date" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Items Table Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                            <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2">
                                <ArchiveBoxIcon class="w-4 h-4 text-indigo-600" />
                                <span>بنود فاتورة الشراء والمنتجات المستلمة</span>
                            </h2>
                            <button 
                                type="button" 
                                @click="addItem"
                                class="px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition-colors flex items-center gap-1"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>إضافة سطر</span>
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-right text-xs">
                                <thead>
                                    <tr class="text-slate-400 font-bold border-b border-slate-100 pb-2">
                                        <th class="py-2 px-2 w-5/12">البند / المنتج</th>
                                        <th class="py-2 px-2 w-2/12">الكمية</th>
                                        <th class="py-2 px-2 w-2/12">سعر الشراء</th>
                                        <th class="py-2 px-2 w-1/12">الضريبة</th>
                                        <th class="py-2 px-2 w-2/12">الإجمالي</th>
                                        <th class="py-2 px-1 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(item, idx) in form.items" :key="idx" class="group">
                                        <!-- Product Selection & Name -->
                                        <td class="py-2 px-2">
                                            <div class="space-y-1">
                                                <select 
                                                    v-model="item.product_id"
                                                    @change="onProductSelect(idx)"
                                                    class="w-full text-2xs rounded-lg border border-slate-200 py-1.5 px-2 font-medium"
                                                >
                                                    <option :value="null">-- بند توريد حر / غير مسجل --</option>
                                                    <option v-for="p in products" :key="p.id" :value="p.id">
                                                        {{ p.name_ar }} ({{ p.sku || 'SKU' }})
                                                    </option>
                                                </select>
                                                <input 
                                                    v-model="item.item_name" 
                                                    type="text" 
                                                    required 
                                                    placeholder="وصف البند المورد..." 
                                                    class="w-full text-xs rounded-lg border border-slate-200 py-1.5 px-2 font-medium"
                                                />
                                            </div>
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-2 px-2 align-top">
                                            <input 
                                                v-model="item.quantity" 
                                                type="number" 
                                                step="0.01" 
                                                min="0.01" 
                                                required 
                                                class="w-full text-xs rounded-lg border border-slate-200 py-1.5 px-2 font-mono text-center font-bold"
                                            />
                                        </td>

                                        <!-- Purchase Price -->
                                        <td class="py-2 px-2 align-top">
                                            <input 
                                                v-model="item.unit_price" 
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                required 
                                                class="w-full text-xs rounded-lg border border-slate-200 py-1.5 px-2 font-mono text-end font-bold"
                                            />
                                        </td>

                                        <!-- Tax Rate -->
                                        <td class="py-2 px-2 align-top">
                                            <span class="inline-block py-1.5 px-2 text-2xs font-mono font-bold text-slate-500 bg-slate-50 rounded-lg">
                                                15%
                                            </span>
                                        </td>

                                        <!-- Total -->
                                        <td class="py-2 px-2 align-top text-end font-mono font-bold text-slate-900 pt-3">
                                            {{ formatCurrency(calculateLineTotal(item)) }}
                                        </td>

                                        <!-- Remove Row -->
                                        <td class="py-2 px-1 align-top text-center pt-2.5">
                                            <button 
                                                type="button" 
                                                @click="removeItem(idx)"
                                                :disabled="form.items.length === 1"
                                                class="p-1 rounded-lg text-slate-300 hover:text-rose-600 hover:bg-rose-50 disabled:opacity-30 transition-colors"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Notes -->
                        <div class="pt-3 border-t border-slate-100">
                            <label class="block text-2xs font-bold text-slate-600 mb-1">ملاحظات الفاتورة أو شروط التوريد</label>
                            <textarea 
                                v-model="form.notes" 
                                rows="2" 
                                placeholder="اكتب أي شروط خاصة أو بيانات التسليم هنا..." 
                                class="w-full text-xs rounded-xl border border-slate-200 p-3 focus:border-indigo-500"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Col: Financial Summary & Payment -->
                <div class="space-y-6">
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2 pb-2 border-b border-slate-100">
                            <ReceiptPercentIcon class="w-4 h-4 text-indigo-600" />
                            <span>ملخص الحساب والضريبة</span>
                        </h2>

                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between py-1 text-slate-600">
                                <span>المجموع غير شامل الضريبة:</span>
                                <span class="font-mono font-bold">{{ formatCurrency(subtotal) }} ﷼</span>
                            </div>
                            <div class="flex justify-between py-1 text-slate-600">
                                <span>ضريبة القيمة المضافة 15%:</span>
                                <span class="font-mono font-bold text-indigo-600">{{ formatCurrency(taxAmount) }} ﷼</span>
                            </div>
                            <div class="flex justify-between py-2 border-t border-slate-200 text-sm font-black text-slate-900">
                                <span>المجموع النهائي:</span>
                                <span class="font-mono text-indigo-700">{{ formatCurrency(total) }} ﷼</span>
                            </div>
                        </div>

                        <!-- Payment Status & Method -->
                        <div class="pt-4 border-t border-slate-100 space-y-3 text-xs">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">حالة السداد للمورد</label>
                                <select 
                                    v-model="form.payment_status" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-medium"
                                >
                                    <option value="unpaid">غير مدفوعة (مستحقة لاحقاً / آجل)</option>
                                    <option value="partially_paid">مدفوعة جزئياً (دفعة مقدمة)</option>
                                    <option value="paid">مدفوعة بالكامل الآن</option>
                                </select>
                            </div>

                            <div v-if="form.payment_status === 'partially_paid'">
                                <label class="block font-bold text-slate-700 mb-1">المبلغ المدفوع مقدماً</label>
                                <input 
                                    v-model="form.paid_amount" 
                                    type="number" 
                                    step="0.01" 
                                    :max="total"
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono font-bold text-emerald-600"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 mb-1">طريقة السداد</label>
                                <select 
                                    v-model="form.payment_method" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-medium"
                                >
                                    <option value="bank_transfer">تحويل بنكي</option>
                                    <option value="cash">نقداً</option>
                                    <option value="card">بطاقة / مدى</option>
                                    <option value="credit">آجل</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
                            >
                                <CheckIcon class="w-4 h-4 stroke-2" />
                                <span>حفظ الفاتورة وإدخال البضاعة</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
