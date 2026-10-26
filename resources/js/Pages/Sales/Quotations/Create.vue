<script setup>
import { taxRate, taxLabel } from '@/utils/tax';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    PlusIcon,
    TrashIcon,
    DocumentTextIcon,
    CheckIcon,
    UserPlusIcon,
} from '@heroicons/vue/24/outline';
import BranchSelect from '@/Components/BranchSelect.vue';

const props = defineProps({
    customers: Array,
    branches: Array,
    products: Array,
    suggestedNumber: String,
    company: Object,
    defaultTaxRate: Number,
    defaultBranchId: Number,
});

const today = new Date().toISOString().split('T')[0];
const defaultValidUntil = new Date(Date.now() + 15 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];


// Tax rules of the chosen branch (its country): default rate of new lines.
const selectedBranchId = ref(props.defaultBranchId || props.branches?.[0]?.id || '');
const branchTax = computed(() => props.branches?.find((b) => Number(b.id) === Number(selectedBranchId.value))?.tax);
const lineRate = () => Number(branchTax.value?.rate ?? lineRate());

const form = useForm({
    customer_id: props.customers?.[0]?.id || '',
    branch_id: selectedBranchId.value,
    quotation_number: props.suggestedNumber || 'QUO-26-1-000001',
    quotation_date: today,
    valid_until: defaultValidUntil,
    notes: taxRate() ? `عرض السعر ساري حتى التاريخ المحدد أعلاه. الأسعار تخضع لـ${taxLabel()}.` : 'عرض السعر ساري حتى التاريخ المحدد أعلاه.',
    terms: '1. الدفع عند قبول العرض وتأكيد الطلب.\n2. مدة التوريد والتنفيذ متفق عليها وفق طبيعة الأعمال.\n3. هذا العرض وثيقة تقديرية ولا يعتبر فاتورة ضريبية.',
    items: [
        {
            product_id: '',
            item_name: '',
            description: '',
            quantity: 1,
            unit_price: 0,
            discount: 0,
            tax_rate: lineRate(),
        }
    ]
});

// A new branch moves lines still on the old standard rate to the new one (reduced or zero rates stay).
watch(() => form.branch_id, (id) => {
    const oldRate = lineRate();
    selectedBranchId.value = id;
    const newRate = lineRate();
    form.items.forEach((item) => {
        if (Number(item.tax_rate) === oldRate) item.tax_rate = newRate;
    });
});

// Auto-fill item from product selection
const onProductSelect = (index, event) => {
    const productId = event.target.value;
    const item = form.items[index];
    if (!productId) return;

    const prod = props.products.find(p => p.id == productId);
    if (prod) {
        item.product_id = prod.id;
        item.item_name = prod.name_ar || prod.name_en || '';
        item.unit_price = Number(prod.selling_price) || 0;
        item.tax_rate = Number(prod.tax_rate ?? lineRate());
    }
};

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

const addItem = () => {
    form.items.push({
        product_id: '',
        item_name: '',
        description: '',
        quantity: 1,
        unit_price: 0,
        discount: 0,
        tax_rate: lineRate(),
    });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const selectedCustomer = computed(() => {
    return props.customers.find(c => c.id == form.customer_id);
});

const submit = () => {
    form.post(route('sales.quotations.store'));
};

// Lines read from a spreadsheet (bill of quantities): appended, or replacing the current ones.
const importLines = (lines, replace) => {
    const rows = lines.map((l) => ({ product_id: l.product_id || '', item_name: l.item_name, description: l.description || '', quantity: l.quantity, unit_price: l.unit_price, discount: l.discount, tax_rate: l.tax_rate }));
    form.items = replace ? rows : [...form.items.filter((i) => i.item_name || i.product_id), ...rows];
};
</script>

<template>
    <AppLayout>
        <Head title="إنشاء عرض سعر جديد" />

        <div class="space-y-6 w-full pb-12">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                    <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                        <HomeIcon class="w-4 h-4" />
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-slate-500">المبيعات</span>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <Link :href="route('sales.quotations.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">
                        عروض الأسعار
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-indigo-600 font-bold">إنشاء عرض سعر</span>
                </div>

                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.quotations.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs sm:text-sm font-bold transition-colors"
                    >
                        إلغاء
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>حفظ عرض السعر</span>
                    </button>
                </div>
            </div>

            <!-- Form Body -->
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Top Section: Customer & Quotation Meta -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Customer Information Card -->
                    <div class="lg:col-span-1 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                                <UserPlusIcon class="w-4 h-4 text-indigo-600" />
                                <span>بيانات العميل</span>
                            </h3>
                            <Link 
                                v-if="$canRoute('sales.clients.create')"
                                :href="route('sales.clients.create')" 
                                target="_blank"
                                class="text-2xs text-indigo-600 hover:text-indigo-700 font-bold"
                            >
                                + عميل جديد
                            </Link>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">اختر العميل *</label>
                            <select 
                                v-model="form.customer_id"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 py-2.5 bg-slate-50/50"
                                required
                            >
                                <option value="" disabled>-- حدد العميل --</option>
                                <option v-for="c in customers" :key="c.id" :value="c.id">
                                    {{ c.name }} {{ c.company_name ? `(${c.company_name})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.customer_id" class="text-2xs text-rose-500 mt-1">{{ form.errors.customer_id }}</p>
                        </div>

                        <!-- Customer Details Snapshot -->
                        <div v-if="selectedCustomer" class="p-3.5 bg-slate-50/80 rounded-xl border border-slate-100 text-xs space-y-1.5 text-slate-600">
                            <p class="flex justify-between">
                                <span class="text-slate-400">الرقم الضريبي:</span>
                                <span class="font-mono font-bold text-slate-800">{{ selectedCustomer.tax_number || 'غير مسجل' }}</span>
                            </p>
                            <p class="flex justify-between">
                                <span class="text-slate-400">الهاتف:</span>
                                <span class="font-mono text-slate-800">{{ selectedCustomer.phone || '-' }}</span>
                            </p>
                        </div>

                        <BranchSelect v-if="branches?.length > 1" v-model="form.branch_id" :branches="branches" :error="form.errors.branch_id" />
                    </div>

                    <!-- Quotation Meta Info Card -->
                    <div class="lg:col-span-2 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-2xs space-y-4">
                        <div class="pb-3 border-b border-slate-100">
                            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                                <DocumentTextIcon class="w-4 h-4 text-indigo-600" />
                                <span>بيانات عرض السعر</span>
                            </h3>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم عرض السعر *</label>
                                <input 
                                    v-model="form.quotation_number"
                                    type="text" 
                                    class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 py-2.5 bg-slate-50/50"
                                    required
                                />
                                <p v-if="form.errors.quotation_number" class="text-2xs text-rose-500 mt-1">{{ form.errors.quotation_number }}</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ العرض *</label>
                                <input 
                                    v-model="form.quotation_date"
                                    type="date" 
                                    class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 py-2.5 bg-slate-50/50"
                                    required
                                />
                                <p v-if="form.errors.quotation_date" class="text-2xs text-rose-500 mt-1">{{ form.errors.quotation_date }}</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">صالح حتى تاريخ</label>
                                <input 
                                    v-model="form.valid_until"
                                    type="date" 
                                    class="w-full font-mono text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 py-2.5 bg-slate-50/50"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">شروط وأحكام العرض</label>
                                <textarea 
                                    v-model="form.terms"
                                    rows="2"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 bg-slate-50/50"
                                    placeholder="شروط التسليم، السداد، الضمان..."
                                ></textarea>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">ملاحظات إضافية</label>
                                <textarea 
                                    v-model="form.notes"
                                    rows="2"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-500 focus:ring-indigo-500 bg-slate-50/50"
                                    placeholder="أي ملاحظات خاصة للعميل..."
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Section -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-sm font-black text-slate-800">الأصناف والخدمات المقدمة</h3>
                        <div class="flex flex-wrap items-center gap-2">
                        <ImportLines kind="sales" :customer-id="form.customer_id" @imported="importLines" />
                        <button 
                            type="button"
                            @click="addItem"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition-colors"
                        >
                            <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                            <span>إضافة بند جديد</span>
                        </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-right border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50/60 text-slate-600 font-bold">
                                    <th class="py-3 px-4 w-12 text-center">#</th>
                                    <th class="py-3 px-4 min-w-[200px]">الصنف / الخدمة</th>
                                    <th class="py-3 px-4 w-28">الكمية</th>
                                    <th class="py-3 px-4 w-32">سعر الوحدة (﷼)</th>
                                    <th class="py-3 px-4 w-28">خصم (﷼)</th>
                                    <th class="py-3 px-4 w-24">الضريبة %</th>
                                    <th class="py-3 px-4 w-32 text-start">الإجمالي (﷼)</th>
                                    <th class="py-3 px-4 w-12 text-center"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(item, idx) in form.items" :key="idx" class="hover:bg-slate-50/50">
                                    <td class="py-3 px-4 text-center font-bold text-slate-400">
                                        {{ idx + 1 }}
                                    </td>
                                    <td class="py-3 px-4 space-y-1">
                                        <select 
                                            v-if="products && products.length > 0"
                                            @change="onProductSelect(idx, $event)"
                                            class="w-full text-2xs rounded-lg border-slate-200 py-1.5 mb-1 bg-white text-slate-700"
                                        >
                                            <option value="">-- اختر من قائمة المنتجات (اختياري) --</option>
                                            <option v-for="p in products" :key="p.id" :value="p.id">
                                                {{ p.name_ar || p.name_en }} ({{ formatCurrency(p.selling_price) }} ﷼)
                                            </option>
                                        </select>
                                        <input 
                                            v-model="item.item_name"
                                            type="text" 
                                            placeholder="اسم الصنف أو الخدمة *"
                                            class="w-full text-xs font-bold rounded-lg border-slate-200 focus:border-indigo-500 py-1.5"
                                            required
                                        />
                                        <input 
                                            v-model="item.description"
                                            type="text" 
                                            placeholder="وصف تفصيلي للبند (اختياري)..."
                                            class="w-full text-2xs text-slate-500 rounded-lg border-slate-200 focus:border-indigo-500 py-1"
                                        />
                                    </td>
                                    <td class="py-3 px-4 align-top">
                                        <input 
                                            v-model.number="item.quantity"
                                            type="number" 
                                            min="0.01" 
                                            step="0.01"
                                            class="w-full font-mono text-xs rounded-lg border-slate-200 focus:border-indigo-500 py-1.5"
                                            required
                                        />
                                    </td>
                                    <td class="py-3 px-4 align-top">
                                        <input 
                                            v-model.number="item.unit_price"
                                            type="number" 
                                            min="0" 
                                            step="0.01"
                                            class="w-full font-mono text-xs rounded-lg border-slate-200 focus:border-indigo-500 py-1.5"
                                            required
                                        />
                                    </td>
                                    <td class="py-3 px-4 align-top">
                                        <input 
                                            v-model.number="item.discount"
                                            type="number" 
                                            min="0" 
                                            step="0.01"
                                            class="w-full font-mono text-xs rounded-lg border-slate-200 focus:border-indigo-500 py-1.5"
                                        />
                                    </td>
                                    <td class="py-3 px-4 align-top">
                                        <input 
                                            v-model.number="item.tax_rate"
                                            type="number" 
                                            min="0" 
                                            step="0.01"
                                            class="w-full font-mono text-xs rounded-lg border-slate-200 focus:border-indigo-500 py-1.5"
                                        />
                                    </td>
                                    <td class="py-3 px-4 align-top font-mono font-bold text-slate-800 pt-5">
                                        {{ formatCurrency(getItemTotal(item)) }}
                                    </td>
                                    <td class="py-3 px-4 align-top text-center pt-4">
                                        <button 
                                            type="button"
                                            @click="removeItem(idx)"
                                            :disabled="form.items.length <= 1"
                                            class="text-slate-300 hover:text-rose-500 transition-colors disabled:opacity-30"
                                            title="حذف البند"
                                        >
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary & Totals Ribbon -->
                    <div class="p-6 bg-slate-50/70 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                        <button 
                            type="button"
                            @click="addItem"
                            class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-800 text-xs font-bold self-start"
                        >
                            <PlusIcon class="w-4 h-4 stroke-2" />
                            <span>إضافة بند آخر</span>
                        </button>

                        <div class="w-full sm:w-80 space-y-2 text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>المجموع قبل الضريبة:</span>
                                <span class="font-mono font-bold">{{ formatCurrency(subtotal) }} ﷼</span>
                            </div>
                            <div v-if="totalDiscount > 0" class="flex justify-between text-emerald-600">
                                <span>إجمالي الخصم:</span>
                                <span class="font-mono font-bold">- {{ formatCurrency(totalDiscount) }} ﷼</span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>{{ $taxLabel() }}:</span>
                                <span class="font-mono font-bold">{{ formatCurrency(totalTax) }} ﷼</span>
                            </div>
                            <div class="flex justify-between text-base font-black text-slate-900 pt-2 border-t border-slate-200">
                                <span>الإجمالي النهائي:</span>
                                <span class="font-mono text-indigo-600">{{ formatCurrency(grandTotal) }} ﷼</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Save Actions -->
                <div class="flex justify-end gap-3 pt-2">
                    <Link 
                        :href="route('sales.quotations.index')"
                        class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs sm:text-sm font-bold transition-colors"
                    >
                        إلغاء
                    </Link>
                    <button 
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>حفظ عرض السعر</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
