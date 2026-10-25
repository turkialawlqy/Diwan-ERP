<script setup>
import { taxRate } from '@/utils/tax';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import CurrencyPicker from '@/Components/Documents/CurrencyPicker.vue';
import { createRemoteLookup } from '@/lookup';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import axios from 'axios';
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
    XMarkIcon,
    CubeIcon,
    ChevronUpDownIcon,
    BuildingOfficeIcon,
    PhoneIcon,
    EnvelopeIcon,
    IdentificationIcon,
    MapPinIcon,
    BanknotesIcon,
    TagIcon,
} from '@heroicons/vue/24/outline';
import CustomerModal from '../Clients/CustomerModal.vue';
import BranchSelect from '@/Components/BranchSelect.vue';

const props = defineProps({
    customers: Array,
    products: Array,
    categories: Array,
    units: Array,
    warehouses: Array,
    branches: Array,
    bankAccounts: Array,
    suggestedNumber: String,
    company: Object,
    defaultTaxRate: Number,
    defaultBranchId: Number,
});

const today = new Date().toISOString().split('T')[0];

// Tax rules of the chosen branch (its country): default rate of new lines and the totals label.
const selectedBranchId = ref(props.defaultBranchId || props.branches?.[0]?.id || '');
const branchTax = computed(() => props.branches?.find((b) => Number(b.id) === Number(selectedBranchId.value))?.tax);
const lineRate = () => Number(branchTax.value?.rate ?? props.defaultTaxRate ?? taxRate());

// Local state for dynamic lookup lists
const customersList = ref([...(props.customers || [])]);
const productsList = ref([...(props.products || [])]);
// Lists hold only a first slice; typing fetches more matches from the server.
const searchCustomersRemote = createRemoteLookup('lookup.customers', customersList);
const searchProductsRemote = createRemoteLookup('lookup.products', productsList);
const categoriesList = ref([...(props.categories || [])]);
const unitsList = ref([...(props.units || [])]);

// Main Invoice Form
const form = useForm({
    customer_id: customersList.value[0]?.id || '',
    branch_id: selectedBranchId.value,
    invoice_number: props.suggestedNumber || 'INV-26-1-000001',
    reference_number: '',
    invoice_date: today,
    due_date: today,
    supply_date: today,
    payment_method: 'bank_transfer',
    currency_code: '',
    exchange_rate: '',
    payment_status: 'paid',
    bank_account_id: '',
    paid_amount: 0,
    notes: 'شكراً لتعاملكم معنا.',
    terms: 'تستحق هذه الفاتورة خلال المدة المتفق عليها. تخضع لضريبة القيمة المضافة وفق أنظمة هيئة الزكاة والضريبة والجمارك بالمملكة العربية السعودية.',
    items: [
        {
            product_id: null,
            item_name: 'خدمات استشارية وتقنية',
            description: 'تقديم خدمات الاستشارات والدعم الفني',
            quantity: 1,
            unit_price: 1000,
            discount: 0,
            tax_rate: lineRate(),
            isDropdownOpen: false,
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

// Toast notification
const toastMessage = ref('');
const showToast = ref(false);
const triggerToast = (msg) => {
    toastMessage.value = msg;
    showToast.value = true;
    setTimeout(() => {
        showToast.value = false;
    }, 3500);
};

// ==========================================
// Financial Accounts & Treasuries (البنوك والخزائن)
// ==========================================
const cashAccounts = computed(() => {
    return (props.bankAccounts || []).filter(b => b.type === 'cash');
});

const bankTransferAccounts = computed(() => {
    return (props.bankAccounts || []).filter(b => b.type === 'bank');
});

const activeFinancialAccounts = computed(() => {
    if (form.payment_method === 'cash') {
        return cashAccounts.value;
    }
    return bankTransferAccounts.value;
});

const selectedBankAccountModel = computed(() => {
    return (props.bankAccounts || []).find(b => b.id === form.bank_account_id);
});

const syncSelectedFinancialAccount = () => {
    if (form.payment_method === 'credit') {
        form.payment_status = 'unpaid';
        form.bank_account_id = '';
        return;
    }
    if (form.payment_status === 'unpaid') {
        form.bank_account_id = '';
        return;
    }
    if (form.payment_method === 'cash') {
        const defaultCash = cashAccounts.value[0];
        form.bank_account_id = defaultCash ? defaultCash.id : '';
    } else {
        const defaultBank = bankTransferAccounts.value[0];
        form.bank_account_id = defaultBank ? defaultBank.id : '';
    }
};

onMounted(() => {
    syncSelectedFinancialAccount();
});

// ==========================================
// 1. CUSTOMER SEARCH & FAST-CREATION
// ==========================================
const customerSearch = ref('');
const isCustomerDropdownOpen = ref(false);

const selectedCustomer = computed(() => {
    return customersList.value.find(c => c.id === form.customer_id);
});

// Initialize search text
if (selectedCustomer.value) {
    customerSearch.value = selectedCustomer.value.name;
}

watch(customerSearch, (q) => searchCustomersRemote(q));

const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (!q) return customersList.value.slice(0, 15);
    return customersList.value.filter(c => 
        (c.name && c.name.toLowerCase().includes(q)) ||
        (c.company_name && c.company_name.toLowerCase().includes(q)) ||
        (c.phone && c.phone.includes(q)) ||
        (c.tax_number && c.tax_number.includes(q))
    ).slice(0, 15);
});

const selectCustomer = (customer) => {
    form.customer_id = customer.id;
    customerSearch.value = customer.name;
    isCustomerDropdownOpen.value = false;
};

// Customer Modal
const isCustomerModalOpen = ref(false);

const openNewCustomerModal = (initialName = '') => {
    isCustomerDropdownOpen.value = false;
    isCustomerModalOpen.value = true;
};

const closeCustomerModal = () => {
    isCustomerModalOpen.value = false;
};

const handleCustomerSavedFromInvoice = (created) => {
    if (created) {
        customersList.value.unshift(created);
        selectCustomer(created);
        closeCustomerModal();
        triggerToast(`تمت إضافة العميل "${created.name}" وتحديده للفاتورة بنجاح.`);
    }
};

// ==========================================
// 2. PRODUCT SEARCH & FAST-CREATION
// ==========================================
const activeRowIndexForProduct = ref(null);
const isProductModalOpen = ref(false);
const isSavingProduct = ref(false);
const productModalErrors = ref({});

const getFilteredProducts = (query) => {
    searchProductsRemote(query);
    const q = (query || '').trim().toLowerCase();
    if (!q) return productsList.value.slice(0, 15);
    return productsList.value.filter(p => 
        (p.name_ar && p.name_ar.toLowerCase().includes(q)) ||
        (p.name_en && p.name_en.toLowerCase().includes(q)) ||
        (p.sku && p.sku.toLowerCase().includes(q)) ||
        (p.barcode && p.barcode.toLowerCase().includes(q))
    ).slice(0, 15);
};

const selectProductForItem = (item, product) => {
    item.product_id = product.id;
    item.item_name = product.name_ar;
    item.description = product.description || '';
    item.unit_price = Number(product.selling_price) || 0;
    item.tax_rate = Number(product.tax_rate ?? lineRate());
    item.isDropdownOpen = false;
};

// Product Modal
const newProductForm = ref({
    name_ar: '',
    name_en: '',
    type: 'product',
    category_id: '',
    unit_id: '',
    selling_price: 0,
    purchase_price: 0,
    tax_rate: lineRate(),
    sku: '',
    barcode: '',
    min_stock_alert: 5,
    track_inventory: true,
    initial_stock: 0,
    warehouse_id: '',
    description: '',
});

const openNewProductModal = (rowIdx, initialName = '') => {
    activeRowIndexForProduct.value = rowIdx;

    const year = new Date().getFullYear().toString().slice(-2);
    const seq = String(productsList.value.length + 1).padStart(5, '0');
    const suggestedSku = `PRD-${year}-${seq}`;

    newProductForm.value = {
        name_ar: typeof initialName === 'string' && initialName.trim() ? initialName.trim() : '',
        name_en: '',
        type: 'product',
        category_id: categoriesList.value[0]?.id || '',
        unit_id: unitsList.value[0]?.id || '',
        selling_price: 0,
        purchase_price: 0,
        tax_rate: lineRate(),
        sku: suggestedSku,
        barcode: '',
        min_stock_alert: 5,
        track_inventory: true,
        initial_stock: 0,
        warehouse_id: props.warehouses?.[0]?.id || '',
        description: '',
    };
    productModalErrors.value = {};
    if (form.items[rowIdx]) {
        form.items[rowIdx].isDropdownOpen = false;
    }
    isProductModalOpen.value = true;
};

const closeProductModal = () => {
    isProductModalOpen.value = false;
    productModalErrors.value = {};
};

const submitNewProduct = async () => {
    isSavingProduct.value = true;
    productModalErrors.value = {};
    try {
        const response = await axios.post(route('inventory.products.store'), newProductForm.value, {
            headers: { 'Accept': 'application/json' }
        });
        if (response.data && response.data.product) {
            const created = response.data.product;
            productsList.value.unshift(created);

            // Insert into active line item
            if (activeRowIndexForProduct.value !== null && form.items[activeRowIndexForProduct.value]) {
                selectProductForItem(form.items[activeRowIndexForProduct.value], created);
            }
            closeProductModal();
            triggerToast(`تمت إضافة الصنف "${created.name_ar}" وإدراجه في الفاتورة بنجاح.`);
        }
    } catch (err) {
        if (err.response && err.response.data && err.response.data.errors) {
            productModalErrors.value = err.response.data.errors;
        } else {
            productModalErrors.value = { general: [err.response?.data?.message || 'حدث خطأ أثناء حفظ الصنف'] };
        }
    } finally {
        isSavingProduct.value = false;
    }
};

// ==========================================
// 3. ROW CALCULATIONS & TOTALS
// ==========================================
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
        product_id: null,
        item_name: '',
        description: '',
        quantity: 1,
        unit_price: 0,
        discount: 0,
        tax_rate: lineRate(),
        isDropdownOpen: false,
    });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

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

// Global click outside handler to close open combobox dropdowns
const handleGlobalClick = (event) => {
    if (!event.target.closest('.customer-combobox-wrapper')) {
        isCustomerDropdownOpen.value = false;
    }
    if (!event.target.closest('.product-combobox-wrapper')) {
        form.items.forEach(it => it.isDropdownOpen = false);
    }
};

onMounted(() => {
    document.addEventListener('click', handleGlobalClick);
});

onUnmounted(() => {
    document.removeEventListener('click', handleGlobalClick);
});

const importLines = (lines, replace) => {
    const rows = lines.map((l) => ({ product_id: l.product_id, item_name: l.item_name, description: l.description || '', quantity: l.quantity, unit_price: l.unit_price, discount: l.discount, tax_rate: l.tax_rate, isDropdownOpen: false }));
    form.items = replace ? rows : [...form.items.filter((i) => i.item_name || i.product_id), ...rows];
};
</script>

<template>
    <AppLayout own-errors>
        <Head title="إنشاء فاتورة مبيعات جديدة" />

        <!-- Floating Toast Message -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-4 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-4 opacity-0"
        >
            <div 
                v-if="showToast" 
                class="fixed top-5 start-1/2 -translate-x-1/2 z-50 bg-slate-900 text-white px-5 py-3 rounded-2xl shadow-xl border border-slate-700 text-xs font-bold flex items-center gap-2.5 backdrop-blur-md"
            >
                <CheckIcon class="w-4 h-4 text-emerald-400 stroke-2" />
                <span>{{ toastMessage }}</span>
            </div>
        </transition>

        <div class="space-y-6 w-full pb-12">
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
                            <!-- Customer Searchable Combobox with Instant Create Button -->
                            <div class="sm:col-span-2 relative customer-combobox-wrapper">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                        <span>العميل</span>
                                        <span class="text-rose-500">*</span>
                                        <span title="ابحث عن عميل أو اكتب اسمه مباشرة للإنشاء الفوري" class="cursor-help text-slate-400 hover:text-slate-600">
                                            <InformationCircleIcon class="w-3.5 h-3.5" />
                                        </span>
                                    </label>
                                    <span v-if="selectedCustomer" class="text-xs text-slate-500 font-medium">
                                        الرصيد الحالي: <strong class="text-indigo-600">{{ formatCurrency(selectedCustomer.current_balance) }} ﷼</strong>
                                    </span>
                                </div>

                                <div class="relative">
                                    <input 
                                        type="text" 
                                        v-model="customerSearch"
                                        @focus="isCustomerDropdownOpen = true"
                                        @input="isCustomerDropdownOpen = true"
                                        placeholder="ابحث برقم العميل، الاسم، الجوال أو اكتب اسماً جديداً..."
                                        class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 ps-3 pe-10 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 font-bold text-slate-800 transition shadow-2xs"
                                    />
                                    <div class="absolute inset-y-0 end-0 pe-3 flex items-center pointer-events-none text-slate-400">
                                        <ChevronUpDownIcon class="w-4 h-4" />
                                    </div>
                                </div>

                                <!-- Customer Dropdown Menu with Instant "إنشاء" Button -->
                                <div 
                                    v-if="isCustomerDropdownOpen" 
                                    class="absolute start-0 end-0 top-full mt-1.5 bg-white border border-slate-200 rounded-2xl shadow-xl z-40 max-h-72 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                >
                                    <!-- Instant Create Action (Matches User Screenshot) -->
                                    <div 
                                        @click="openNewCustomerModal(customerSearch)"
                                        class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                    >
                                        <div class="flex items-center gap-2">
                                            <UserPlusIcon class="w-4 h-4 stroke-2" />
                                            <span>إنشاء "{{ customerSearch.trim() || 'عميل جديد' }}"</span>
                                        </div>
                                        <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">نافذة الإضافة +</span>
                                    </div>

                                    <!-- Customer List Results -->
                                    <div class="py-1">
                                        <div 
                                            v-for="c in filteredCustomers" 
                                            :key="c.id"
                                            @click="selectCustomer(c)"
                                            class="p-2.5 rounded-xl hover:bg-slate-50 cursor-pointer flex items-center justify-between transition text-xs"
                                            :class="form.customer_id === c.id ? 'bg-indigo-50/70 text-indigo-700 font-bold' : 'text-slate-700'"
                                        >
                                            <div class="space-y-0.5">
                                                <div class="font-bold flex items-center gap-1.5">
                                                    <span>{{ c.name }}</span>
                                                    <span v-if="c.company_name" class="text-3xs font-normal text-slate-500">({{ c.company_name }})</span>
                                                </div>
                                                <div class="text-3xs text-slate-400 flex items-center gap-3">
                                                    <span v-if="c.phone">📞 {{ c.phone }}</span>
                                                    <span v-if="c.tax_number">ضريبي: {{ c.tax_number }}</span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-3xs font-mono font-semibold text-slate-500">
                                                    {{ formatCurrency(c.current_balance) }} ﷼
                                                </span>
                                            </div>
                                        </div>

                                        <div v-if="filteredCustomers.length === 0" class="p-3 text-center text-xs text-slate-400">
                                            لا يوجد عميل بهذا الاسم، اضغط على زر الإنشاء بالأعلى لإضافته فوراً.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="branches?.length > 1" class="sm:col-span-2">
                                <BranchSelect v-model="form.branch_id" :branches="branches" :error="form.errors.branch_id" />
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

                            <div class="sm:col-span-2">
                                <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" />
                            </div>

                            <!-- Payment Method -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">طريقة الدفع <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_method" 
                                    @change="syncSelectedFinancialAccount"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-semibold"
                                >
                                    <option value="bank_transfer">تحويل بنكي (Bank Transfer)</option>
                                    <option value="cash">نقداً (Cash)</option>
                                    <option value="card">مدى / بطاقة شبكة (Card/Mada)</option>
                                    <option value="credit">آجل / ذمم مدينة (Credit)</option>
                                </select>
                            </div>

                            <!-- Payment Status -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">حالة السداد <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_status" 
                                    @change="syncSelectedFinancialAccount"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-semibold"
                                >
                                    <option value="paid">مدفوعة بالكامل</option>
                                    <option value="partially_paid">مدفوعة جزئياً</option>
                                    <option value="unpaid">غير مدفوعة (آجل)</option>
                                </select>
                            </div>

                            <!-- Receiving Bank Account / Cash Treasury Selector -->
                            <div v-if="form.payment_status !== 'unpaid' && form.payment_method !== 'credit'" class="sm:col-span-2 bg-indigo-50/40 p-4 rounded-xl border border-indigo-100 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                        <BuildingOfficeIcon v-if="form.payment_method === 'bank_transfer' || form.payment_method === 'card'" class="w-4 h-4 text-indigo-600" />
                                        <BanknotesIcon v-else class="w-4 h-4 text-emerald-600" />
                                        <span>{{ form.payment_method === 'cash' ? 'الخزينة النقدية المستلمة (صندوق النقدية)' : 'الحساب البنكي المحول إليه (المصرف المستلم)' }}</span>
                                        <span class="text-rose-500">*</span>
                                    </label>
                                    <span v-if="selectedBankAccountModel" class="text-2xs font-normal text-slate-500">
                                        الرصيد الدفتري الحالي: <strong class="text-indigo-600 font-mono font-bold">{{ formatCurrency(selectedBankAccountModel.current_balance) }} ﷼</strong>
                                    </span>
                                </div>
                                <select 
                                    v-model="form.bank_account_id"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-indigo-200 bg-white px-3 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 font-bold text-slate-800 shadow-2xs"
                                    required
                                >
                                    <option value="" disabled>-- اختر الحساب المالي أو الخزينة المودع بها --</option>
                                    <option 
                                        v-for="acc in activeFinancialAccounts" 
                                        :key="acc.id" 
                                        :value="acc.id"
                                    >
                                        {{ acc.name }} 
                                        {{ acc.account_number ? `(${acc.bank_name || 'حساب رقم'}: ${acc.account_number})` : '' }} 
                                        - الرصيد: {{ formatCurrency(acc.current_balance) }} ﷼
                                    </option>
                                </select>
                                <p class="text-3xs text-slate-500">
                                    {{ form.payment_method === 'cash' 
                                        ? 'سيتم تسجيل القيد المحاسبي وإيداع المبلغ في هذه الخزينة تلقائياً وتحديث كشف حساب الصندوق.' 
                                        : 'سيتم تسجيل القيد المحاسبي وإيداع الحوالة البنكية في كشف حساب هذا البنك مباشرة في دفتر الأستاذ.' 
                                    }}
                                </p>
                            </div>

                            <!-- Partially Paid Amount Breakdown (if selected) -->
                            <div v-if="form.payment_status === 'partially_paid'" class="sm:col-span-2 bg-amber-50/50 p-4 rounded-xl border border-amber-200 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-amber-900 mb-1.5">المبلغ المدفوع الآن (نقداً / بالحساب)</label>
                                        <input 
                                            v-model.number="form.paid_amount"
                                            type="number" 
                                            step="0.01" 
                                            min="0" 
                                            :max="grandTotal"
                                            class="w-full text-xs sm:text-sm rounded-xl border border-amber-300 px-3 py-2.5 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 bg-white font-mono font-bold text-amber-900"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-amber-900 mb-1.5">المبلغ المتبقي (آجل كذمم مدينة للعميل)</label>
                                        <div class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 bg-slate-50 font-mono font-bold text-rose-600 flex items-center justify-between">
                                            <span>{{ formatCurrency(Math.max(0, grandTotal - (form.paid_amount || 0))) }}</span>
                                            <span class="text-3xs text-slate-400">﷼</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Line Items Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                <span>بنود الفاتورة والأصناف</span>
                                <span class="px-2 py-0.5 rounded-full text-2xs bg-indigo-50 text-indigo-700 font-bold">
                                    {{ form.items.length }} بنود
                                </span>
                            </h2>
                            <ImportLines kind="sales" :customer-id="form.customer_id" @imported="importLines" />
                            <button 
                                type="button"
                                @click="addItem"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>إضافة صنف</span>
                            </button>
                        </div>

                        <!-- Items Table -->
                        <div class="overflow-visible">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-500 font-bold">
                                        <th class="py-2.5 px-2 text-center w-8">#</th>
                                        <th class="py-2.5 px-3 min-w-[240px]">المنتج / الصنف *</th>
                                        <th class="py-2.5 px-3 min-w-[150px]">الوصف</th>
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

                                        <!-- Product Combobox with Instant Search and Create Option -->
                                        <td class="py-3 px-3 relative product-combobox-wrapper">
                                            <div class="relative">
                                                <input 
                                                    v-model="item.item_name"
                                                    type="text" 
                                                    @focus="item.isDropdownOpen = true"
                                                    @input="item.isDropdownOpen = true"
                                                    placeholder="اختر أو انشئ منتج..."
                                                    class="w-full text-xs rounded-xl border border-slate-200 ps-2.5 pe-8 py-2 focus:border-indigo-500 font-bold text-slate-800 transition"
                                                    required
                                                />
                                                <div class="absolute inset-y-0 end-0 pe-2.5 flex items-center pointer-events-none text-slate-400">
                                                    <ChevronUpDownIcon class="w-3.5 h-3.5" />
                                                </div>
                                            </div>

                                            <!-- Dropdown for Product Matching & Fast-Creation -->
                                            <div 
                                                v-if="item.isDropdownOpen"
                                                class="absolute start-3 end-3 top-full mt-1 bg-white border border-slate-200 rounded-2xl shadow-xl z-40 max-h-64 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                            >
                                                <!-- Create Product Button (Matches Screenshot) -->
                                                <div 
                                                    @click="openNewProductModal(idx, item.item_name)"
                                                    class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <PlusIcon class="w-4 h-4 stroke-2" />
                                                        <span>إنشاء "{{ item.item_name.trim() || 'صنف جديد' }}"</span>
                                                    </div>
                                                    <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">نافذة الصنف +</span>
                                                </div>

                                                <!-- Product Matches -->
                                                <div class="py-1">
                                                    <div 
                                                        v-for="prod in getFilteredProducts(item.item_name)" 
                                                        :key="prod.id"
                                                        @click="selectProductForItem(item, prod)"
                                                        class="p-2 rounded-xl hover:bg-slate-50 cursor-pointer flex items-center justify-between transition text-xs"
                                                    >
                                                        <div class="space-y-0.5">
                                                            <div class="font-bold text-slate-800">{{ prod.name_ar }}</div>
                                                            <div class="text-3xs text-slate-400 flex items-center gap-2">
                                                                <span v-if="prod.sku" class="font-mono">كود: {{ prod.sku }}</span>
                                                                <span v-if="prod.unit">({{ prod.unit.name_ar }})</span>
                                                            </div>
                                                        </div>
                                                        <div class="font-bold text-indigo-600 font-mono">
                                                            {{ formatCurrency(prod.selling_price) }} ﷼
                                                        </div>
                                                    </div>

                                                    <div v-if="getFilteredProducts(item.item_name).length === 0" class="p-2.5 text-center text-xs text-slate-400">
                                                        لا يوجد صنف مطابق، اضغط على زر الإنشاء أعلاه لإضافته فوراً.
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Item Description -->
                                        <td class="py-3 px-3">
                                            <input 
                                                v-model="item.description"
                                                type="text" 
                                                placeholder="وصف البند..."
                                                class="w-full text-2xs rounded-xl border border-slate-200 px-2.5 py-2 text-slate-600 focus:border-indigo-500"
                                            />
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.quantity"
                                                type="number" 
                                                step="0.01" 
                                                min="0.01" 
                                                class="w-full text-xs text-center rounded-xl border border-slate-200 px-2 py-2 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>

                                        <!-- Unit Price -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.unit_price"
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                class="w-full text-xs text-start rounded-xl border border-slate-200 px-2 py-2 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>

                                        <!-- Discount -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.discount"
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                class="w-full text-xs text-start rounded-xl border border-slate-200 px-2 py-2 focus:border-indigo-500 font-mono"
                                            />
                                        </td>

                                        <!-- Tax Rate -->
                                        <td class="py-3 px-2 text-center pt-3 font-bold text-slate-600 text-2xs">
                                            %{{ item.tax_rate }}
                                        </td>

                                        <!-- Row Total -->
                                        <td class="py-3 px-3 text-end pt-3 font-black text-slate-800 font-mono whitespace-nowrap">
                                            {{ formatCurrency(getItemTotal(item)) }}
                                        </td>

                                        <!-- Delete Item Button -->
                                        <td class="py-3 px-2 text-center pt-2.5">
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
                                    <span class="px-1.5 py-0.5 rounded text-3xs font-bold bg-indigo-50 text-indigo-700">{{ lineRate() }}%</span>
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

        <!-- ========================================== -->
        <!-- POPUP MODAL: CREATE NEW CUSTOMER           -->
        <!-- ========================================== -->
        <CustomerModal
            :show="isCustomerModalOpen"
            @close="closeCustomerModal"
            @saved="handleCustomerSavedFromInvoice"
        />

        <!-- ========================================== -->
        <!-- POPUP MODAL: CREATE NEW PRODUCT / ITEM     -->
        <!-- ========================================== -->
        <div 
            v-if="isProductModalOpen" 
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200 overflow-y-auto"
        >
            <div class="bg-white rounded-3xl border border-slate-100 shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden my-auto">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 border border-indigo-200/80 text-indigo-600 flex items-center justify-center">
                            <CubeIcon class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-900">إضافة صنف / منتج جديد</h3>
                            <p class="text-3xs text-slate-500">أدخل تفاصيل الصنف وسيتم إدراجه في الفاتورة مباشرة</p>
                        </div>
                    </div>
                    <button 
                        @click="closeProductModal"
                        class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                    >
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Body Form -->
                <div class="p-6 overflow-y-auto space-y-4">
                    <!-- General Error Alert -->
                    <div v-if="productModalErrors.general" class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 font-bold">
                        {{ productModalErrors.general[0] }}
                    </div>

                    <!-- Type Selector (Product vs Service) -->
                    <div class="flex items-center gap-2 p-1 bg-slate-100 rounded-xl max-w-xs">
                        <button 
                            type="button"
                            @click="newProductForm.type = 'product'"
                            :class="newProductForm.type === 'product' ? 'bg-white text-indigo-700 shadow-xs font-bold' : 'text-slate-600 font-medium'"
                            class="flex-1 py-1.5 rounded-lg text-xs transition text-center"
                        >
                            منتج مخزني
                        </button>
                        <button 
                            type="button"
                            @click="newProductForm.type = 'service'"
                            :class="newProductForm.type === 'service' ? 'bg-white text-indigo-700 shadow-xs font-bold' : 'text-slate-600 font-medium'"
                            class="flex-1 py-1.5 rounded-lg text-xs transition text-center"
                        >
                            خدمة / استشارة
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name Arabic -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم الصنف (بالعربية) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newProductForm.name_ar"
                                type="text" 
                                required
                                placeholder="مثال: شاشة ذكية 55 بوصة"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-bold"
                            />
                            <p v-if="productModalErrors.name_ar" class="text-3xs text-rose-600 mt-1 font-semibold">{{ productModalErrors.name_ar[0] }}</p>
                        </div>

                        <!-- Name English -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم الصنف (باللغة الإنجليزية)</label>
                            <input 
                                v-model="newProductForm.name_en"
                                type="text" 
                                dir="ltr"
                                placeholder="e.g. Smart TV 55 Inch"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">التصنيف</label>
                            <select 
                                v-model="newProductForm.category_id"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-medium"
                            >
                                <option value="">بدون تصنيف (عام)</option>
                                <option v-for="cat in categoriesList" :key="cat.id" :value="cat.id">
                                    {{ cat.name_ar }}
                                </option>
                            </select>
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الوحدة</label>
                            <select 
                                v-model="newProductForm.unit_id"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-medium"
                            >
                                <option value="">اختر الوحدة...</option>
                                <option v-for="u in unitsList" :key="u.id" :value="u.id">
                                    {{ u.name_ar }} ({{ u.code || u.name_en || '-' }})
                                </option>
                            </select>
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">سعر البيع الافتراضي (﷼) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="newProductForm.selling_price"
                                type="number" 
                                step="0.01"
                                min="0"
                                required
                                placeholder="0.00"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono font-bold text-indigo-700"
                            />
                            <p v-if="productModalErrors.selling_price" class="text-3xs text-rose-600 mt-1 font-semibold">{{ productModalErrors.selling_price[0] }}</p>
                        </div>

                        <!-- Purchase Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">سعر الشراء / التكلفة (﷼)</label>
                            <input 
                                v-model.number="newProductForm.purchase_price"
                                type="number" 
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Tax Rate -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">نسبة الضريبة (%)</label>
                            <input 
                                v-model.number="newProductForm.tax_rate"
                                type="number" 
                                step="0.01"
                                min="0"
                                placeholder="15"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">كود الصنف (SKU)</label>
                            <input 
                                v-model="newProductForm.sku"
                                type="text" 
                                dir="ltr"
                                placeholder="PRD-26-00001"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Barcode -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الباركود (Barcode)</label>
                            <input 
                                v-model="newProductForm.barcode"
                                type="text" 
                                dir="ltr"
                                placeholder="628xxxxxxxxxx"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Initial Stock (Only if product) -->
                        <div v-if="newProductForm.type === 'product'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">الكمية الافتتاحية للمخزون</label>
                            <input 
                                v-model.number="newProductForm.initial_stock"
                                type="number" 
                                step="0.01"
                                min="0"
                                placeholder="0"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">وصف الصنف / تفاصيل إضافية</label>
                        <textarea 
                            v-model="newProductForm.description"
                            rows="2"
                            placeholder="وصف مختصر لمواصفات الصنف يظهر على الفاتورة..."
                            class="w-full text-xs rounded-xl border border-slate-200 p-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none text-slate-600"
                        ></textarea>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-end gap-3">
                    <button 
                        type="button"
                        @click="closeProductModal"
                        class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                    >
                        إلغاء
                    </button>
                    <button 
                        type="button"
                        @click="submitNewProduct"
                        :disabled="isSavingProduct || !newProductForm.name_ar"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-2 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="isSavingProduct" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4 stroke-2" />
                        <span>حفظ وإدراج في الفاتورة</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
