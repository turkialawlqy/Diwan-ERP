<script setup>
import { taxRate } from '@/utils/tax';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import CurrencyPicker from '@/Components/Documents/CurrencyPicker.vue';
import { createRemoteLookup } from '@/lookup';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
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
    XMarkIcon,
    CubeIcon,
    UserPlusIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    ChevronUpDownIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';
import BranchSelect from '@/Components/BranchSelect.vue';

const props = defineProps({
    suppliers: Array,
    warehouses: Array,
    branches: Array,
    products: Array,
    categories: Array,
    units: Array,
    suggestedNumber: String,
    defaultTaxRate: Number,
    defaultBranchId: Number,
});

// Reactive Lists for Local Updates
const suppliersList = ref([...(props.suppliers || [])]);
const productsList = ref([...(props.products || [])]);
const categoriesList = ref([...(props.categories || [])]);
const unitsList = ref([...(props.units || [])]);


// Tax rules of the chosen branch (its country): default rate of new lines.
const selectedBranchId = ref(props.defaultBranchId || props.branches?.[0]?.id || '');
const branchTax = computed(() => props.branches?.find((b) => Number(b.id) === Number(selectedBranchId.value))?.tax);
const lineRate = () => Number(branchTax.value?.rate ?? lineRate());

const form = useForm({
    supplier_id: suppliersList.value[0]?.id || '',
    warehouse_id: props.warehouses?.[0]?.id || '',
    branch_id: selectedBranchId.value,
    invoice_number: props.suggestedNumber || '',
    supplier_invoice_number: '',
    invoice_date: new Date().toISOString().split('T')[0],
    due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    supply_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    currency_code: '',
    exchange_rate: '',
    payment_status: 'unpaid',
    paid_amount: 0,
    notes: '',
    items: [
        {
            product_id: productsList.value[0]?.id || null,
            item_name: productsList.value[0]?.name_ar || 'بند توريد جديد',
            quantity: 1,
            unit_price: Number(productsList.value[0]?.purchase_price) || 0,
            discount: 0,
            tax_rate: Number(productsList.value[0]?.tax_rate ?? taxRate()),
            isDropdownOpen: false,
        }
    ],
});

// Warehouses of the chosen branch (and shared ones without a branch); all of them if none match.
const branchWarehouses = computed(() => {
    const own = (props.warehouses || []).filter((w) => !w.branch_id || Number(w.branch_id) === Number(selectedBranchId.value));
    return own.length ? own : props.warehouses || [];
});
watch(branchWarehouses, (list) => {
    if (!list.some((w) => w.id === form.warehouse_id)) form.warehouse_id = list[0]?.id || '';
}, { immediate: true });

// A new branch moves lines still on the old standard rate to the new one (reduced or zero rates stay).
watch(() => form.branch_id, (id) => {
    const oldRate = lineRate();
    selectedBranchId.value = id;
    const newRate = lineRate();
    form.items.forEach((item) => {
        if (Number(item.tax_rate) === oldRate) item.tax_rate = newRate;
    });
});

// ==========================================
// 1. SUPPLIER SEARCH & FAST-CREATION
// ==========================================
const supplierSearch = ref('');
const isSupplierDropdownOpen = ref(false);

const selectedSupplier = computed(() => {
    return suppliersList.value.find(s => s.id === form.supplier_id);
});

// Initialize search text with current supplier
if (selectedSupplier.value) {
    supplierSearch.value = selectedSupplier.value.name;
}

// Lists hold only a first slice; typing fetches more matches from the server.
const searchSuppliersRemote = createRemoteLookup('lookup.suppliers', suppliersList);
const searchProductsRemote = createRemoteLookup('lookup.products', productsList);
watch(supplierSearch, (q) => searchSuppliersRemote(q));

const filteredSuppliers = computed(() => {
    const q = supplierSearch.value.trim().toLowerCase();
    if (!q) return suppliersList.value.slice(0, 15);
    return suppliersList.value.filter(s => 
        (s.name && s.name.toLowerCase().includes(q)) ||
        (s.company_name && s.company_name.toLowerCase().includes(q)) ||
        (s.phone && s.phone.includes(q)) ||
        (s.tax_number && s.tax_number.includes(q))
    ).slice(0, 15);
});

const selectSupplier = (supplier) => {
    form.supplier_id = supplier.id;
    supplierSearch.value = supplier.name;
    isSupplierDropdownOpen.value = false;
};

// Supplier Modal State
const isSupplierModalOpen = ref(false);
const isSavingSupplier = ref(false);
const supplierModalErrors = ref({});

const newSupplierForm = ref({
    name: '',
    company_name: '',
    phone: '',
    tax_number: '',
    commercial_register: '',
    email: '',
    address: '',
    opening_balance: 0,
});

const isNonExistingSupplier = (name) => {
    if (!name || !name.trim()) return false;
    const clean = name.trim().toLowerCase();
    return !suppliersList.value.some(s => 
        (s.name && s.name.trim().toLowerCase() === clean) ||
        (s.company_name && s.company_name.trim().toLowerCase() === clean) ||
        (s.phone && s.phone.trim() === clean) ||
        (s.tax_number && s.tax_number.trim() === clean)
    );
};

const openNewSupplierModal = (initialName = '') => {
    const nameToSet = typeof initialName === 'string' && initialName.trim() 
        ? initialName.trim() 
        : supplierSearch.value.trim();

    supplierSearch.value = nameToSet;

    newSupplierForm.value = {
        name: nameToSet,
        company_name: '',
        phone: '',
        tax_number: '',
        commercial_register: '',
        email: '',
        address: '',
        opening_balance: 0,
    };
    supplierModalErrors.value = {};
    isSupplierDropdownOpen.value = false;
    isSupplierModalOpen.value = true;
};

const closeSupplierModal = () => {
    isSupplierModalOpen.value = false;
    supplierModalErrors.value = {};
};

const submitNewSupplier = async () => {
    if (!newSupplierForm.value.name.trim()) return;
    isSavingSupplier.value = true;
    supplierModalErrors.value = {};
    try {
        const response = await axios.post(route('purchases.suppliers.store'), newSupplierForm.value, {
            headers: { 'Accept': 'application/json' }
        });
        if (response.data && response.data.supplier) {
            const created = response.data.supplier;
            suppliersList.value.unshift(created);
            selectSupplier(created);
            closeSupplierModal();
            triggerToast(`تمت إضافة المورد "${created.name}" وتحديده للفاتورة بنجاح.`);
        }
    } catch (err) {
        if (err.response && err.response.data && err.response.data.errors) {
            supplierModalErrors.value = err.response.data.errors;
        } else {
            supplierModalErrors.value = { general: [err.response?.data?.message || 'حدث خطأ أثناء حفظ بيانات المورد'] };
        }
    } finally {
        isSavingSupplier.value = false;
    }
};

// ==========================================
// 2. PRODUCT SEARCH & FAST-CREATION
// ==========================================
const activeRowIndexForProduct = ref(null);
const isProductModalOpen = ref(false);
const isSavingProduct = ref(false);
const productModalErrors = ref({});

const isNonExistingProduct = (name) => {
    if (!name || !name.trim()) return false;
    const clean = name.trim().toLowerCase();
    return !productsList.value.some(p => 
        (p.name_ar && p.name_ar.trim().toLowerCase() === clean) ||
        (p.name_en && p.name_en.trim().toLowerCase() === clean) ||
        (p.sku && p.sku.trim().toLowerCase() === clean)
    );
};

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
    item.unit_price = Number(product.purchase_price) || 0;
    item.tax_rate = Number(product.tax_rate ?? lineRate());
    item.isDropdownOpen = false;
};

const newProductForm = ref({
    type: 'product',
    name_ar: '',
    name_en: '',
    category_id: '',
    unit_id: '',
    purchase_price: 0,
    selling_price: 0,
    tax_rate: lineRate(),
    sku: '',
    barcode: '',
    description: '',
});

const openNewProductModal = (rowIndex, initialName = '') => {
    activeRowIndexForProduct.value = rowIndex;

    const nameToSet = typeof initialName === 'string' && initialName.trim()
        ? initialName.trim()
        : (rowIndex !== null && form.items[rowIndex]?.item_name ? form.items[rowIndex].item_name.trim() : '');

    // Keep the typed name in the table row
    if (rowIndex !== null && form.items[rowIndex]) {
        form.items[rowIndex].item_name = nameToSet;
        form.items[rowIndex].isDropdownOpen = false;
    }

    const year = new Date().getFullYear().toString().slice(-2);
    const seq = String(productsList.value.length + 1).padStart(5, '0');
    const suggestedSku = `PRD-${year}-${seq}`;

    newProductForm.value = {
        type: 'product',
        name_ar: nameToSet,
        name_en: '',
        category_id: categoriesList.value[0]?.id || '',
        unit_id: unitsList.value[0]?.id || '',
        purchase_price: 0,
        selling_price: 0,
        tax_rate: lineRate(),
        sku: suggestedSku,
        barcode: '',
        description: '',
    };
    productModalErrors.value = {};
    isProductModalOpen.value = true;
};

const closeProductModal = () => {
    isProductModalOpen.value = false;
    productModalErrors.value = {};
};

const submitNewProduct = async () => {
    if (!newProductForm.value.name_ar.trim()) return;
    isSavingProduct.value = true;
    productModalErrors.value = {};

    // ProductController requires selling_price; default to purchase_price or 0 if omitted
    if (!newProductForm.value.selling_price || Number(newProductForm.value.selling_price) <= 0) {
        newProductForm.value.selling_price = Number(newProductForm.value.purchase_price) || 0;
    }

    try {
        const response = await axios.post(route('inventory.products.store'), newProductForm.value, {
            headers: { 'Accept': 'application/json' }
        });
        if (response.data && response.data.product) {
            const created = response.data.product;
            productsList.value.unshift(created);

            // Populate the specific row
            if (activeRowIndexForProduct.value !== null && form.items[activeRowIndexForProduct.value]) {
                selectProductForItem(form.items[activeRowIndexForProduct.value], created);
            } else {
                form.items.push({
                    product_id: created.id,
                    item_name: created.name_ar,
                    quantity: 1,
                    unit_price: Number(created.purchase_price) || 0,
                    discount: 0,
                    tax_rate: Number(created.tax_rate ?? taxRate()),
                    isDropdownOpen: false,
                });
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
const addItem = () => {
    form.items.push({
        product_id: null,
        item_name: '',
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

// ==========================================
// 4. TOAST NOTIFICATIONS & CLICK OUTSIDE
// ==========================================
const toastMessage = ref('');
const isToastVisible = ref(false);
let toastTimeout = null;

const triggerToast = (msg) => {
    if (toastTimeout) clearTimeout(toastTimeout);
    toastMessage.value = msg;
    isToastVisible.value = true;
    toastTimeout = setTimeout(() => {
        isToastVisible.value = false;
    }, 4000);
};

const handleGlobalClick = (event) => {
    if (!event.target.closest('.supplier-combobox-wrapper')) {
        isSupplierDropdownOpen.value = false;
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
    const rows = lines.map((l) => ({ product_id: l.product_id, item_name: l.item_name, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount, tax_rate: l.tax_rate, isDropdownOpen: false }));
    form.items = replace ? rows : [...form.items.filter((i) => i.item_name || i.product_id), ...rows];
};
</script>

<template>
    <AppLayout>
        <Head title="إنشاء فاتورة شراء جديدة" />

        <!-- Notification Toast -->
        <Transition
            enter-active-class="transform transition ease-out duration-300"
            enter-from-class="-translate-y-4 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div 
                v-if="isToastVisible" 
                class="fixed top-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2.5 px-4 py-3 bg-slate-900 text-white rounded-2xl shadow-xl border border-slate-700 text-xs font-bold"
            >
                <CheckCircleIcon class="w-5 h-5 text-emerald-400 stroke-2 shrink-0" />
                <span>{{ toastMessage }}</span>
            </div>
        </Transition>

        <div class="space-y-6 w-full">
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
                            <!-- Supplier Searchable Combobox with Instant Create Option On Typing -->
                            <div class="sm:col-span-2 relative supplier-combobox-wrapper">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block font-bold text-slate-700 flex items-center gap-1.5">
                                        <span>المورد</span>
                                        <span class="text-rose-500">*</span>
                                        <span title="ابحث برقم المورد أو الاسم أو الجوال، أو اكتب اسماً جديداً للإنشاء المباشر" class="cursor-help text-slate-400 hover:text-slate-600">
                                            <InformationCircleIcon class="w-3.5 h-3.5" />
                                        </span>
                                    </label>
                                    <span v-if="selectedSupplier" class="text-xs text-slate-500 font-medium">
                                        الرصيد الحالي المستحق: <strong class="text-indigo-600">{{ formatCurrency(selectedSupplier.current_balance) }} ﷼</strong>
                                    </span>
                                </div>

                                <div class="relative">
                                    <input 
                                        type="text" 
                                        v-model="supplierSearch"
                                        @focus="isSupplierDropdownOpen = true"
                                        @input="isSupplierDropdownOpen = true"
                                        placeholder="ابحث برقم المورد، الاسم، الجوال أو اكتب اسماً جديداً..."
                                        class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 ps-3 pe-10 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 font-bold text-slate-800 transition shadow-2xs"
                                        required
                                    />
                                    <div class="absolute inset-y-0 end-0 pe-3 flex items-center pointer-events-none text-slate-400">
                                        <ChevronUpDownIcon class="w-4 h-4" />
                                    </div>
                                </div>

                                <!-- Supplier Dropdown with Instant Create Action -->
                                <div 
                                    v-if="isSupplierDropdownOpen" 
                                    class="absolute start-0 end-0 top-full mt-1.5 bg-white border border-slate-200 rounded-2xl shadow-xl z-40 max-h-72 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                >
                                    <!-- Instant Create Action (ONLY on Typing Non-existent supplier) -->
                                    <div 
                                        v-if="isNonExistingSupplier(supplierSearch)"
                                        @click.stop="openNewSupplierModal(supplierSearch)"
                                        class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                    >
                                        <div class="flex items-center gap-2">
                                            <UserPlusIcon class="w-4 h-4 stroke-2" />
                                            <span>إنشاء "{{ supplierSearch.trim() }}"</span>
                                        </div>
                                        <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">نافذة الإضافة +</span>
                                    </div>

                                    <!-- Supplier Matches List -->
                                    <div class="py-1">
                                        <div 
                                            v-for="s in filteredSuppliers" 
                                            :key="s.id"
                                            @click="selectSupplier(s)"
                                            class="p-2.5 rounded-xl hover:bg-slate-50 cursor-pointer flex items-center justify-between transition text-xs"
                                            :class="form.supplier_id === s.id ? 'bg-indigo-50/70 text-indigo-700 font-bold' : 'text-slate-700'"
                                        >
                                            <div class="space-y-0.5">
                                                <div class="font-bold flex items-center gap-1.5">
                                                    <span>{{ s.name }}</span>
                                                    <span v-if="s.company_name" class="text-3xs font-normal text-slate-500">({{ s.company_name }})</span>
                                                </div>
                                                <div class="text-3xs text-slate-400 flex items-center gap-3">
                                                    <span v-if="s.phone">📞 {{ s.phone }}</span>
                                                    <span v-if="s.tax_number">الرقم الضريبي: {{ s.tax_number }}</span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-3xs font-mono font-semibold text-slate-500">
                                                    {{ formatCurrency(s.current_balance) }} ﷼
                                                </span>
                                            </div>
                                        </div>

                                        <div v-if="filteredSuppliers.length === 0" class="p-3 text-center text-xs text-slate-400">
                                            لا يوجد مورد بهذا الاسم، اضغط على زر الإنشاء بالأعلى لإضافته فوراً.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="branches?.length > 1">
                                <BranchSelect v-model="form.branch_id" :branches="branches" :error="form.errors.branch_id" />
                            </div>

                            <!-- Warehouse Selection -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">المستودع المستلم <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.warehouse_id" 
                                    required
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                                >
                                    <option v-for="w in branchWarehouses" :key="w.id" :value="w.id">
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
                        <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" class="pt-2" />
                    </div>

                    <!-- Items Table Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                            <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2">
                                <ArchiveBoxIcon class="w-4 h-4 text-indigo-600" />
                                <span>بنود فاتورة الشراء والمنتجات المستلمة</span>
                            </h2>
                            <ImportLines kind="purchase" @imported="importLines" />
                            <button 
                                type="button" 
                                @click="addItem"
                                class="px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition-colors flex items-center gap-1 border border-indigo-200/60"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>إضافة سطر</span>
                            </button>
                        </div>

                        <div class="overflow-visible">
                            <table class="w-full text-right text-xs">
                                <thead>
                                    <tr class="text-slate-400 font-bold border-b border-slate-100 pb-2">
                                        <th class="py-2.5 px-2 text-center w-8">#</th>
                                        <th class="py-2.5 px-3 min-w-[240px]">المنتج / الصنف *</th>
                                        <th class="py-2.5 px-2 w-24">الكمية</th>
                                        <th class="py-2.5 px-2 w-28">سعر الشراء</th>
                                        <th class="py-2.5 px-2 w-16 text-center">الضريبة</th>
                                        <th class="py-2.5 px-3 w-28 text-end">الإجمالي</th>
                                        <th class="py-2.5 px-1 text-center w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(item, idx) in form.items" :key="idx" class="align-top">
                                        <td class="py-3 px-2 text-center text-slate-400 font-mono pt-3.5">
                                            {{ idx + 1 }}
                                        </td>

                                        <!-- Product Combobox with Instant Search and Create Option On Typing -->
                                        <td class="py-2.5 px-3 relative product-combobox-wrapper">
                                            <div class="relative">
                                                <input 
                                                    v-model="item.item_name"
                                                    type="text" 
                                                    @focus="item.isDropdownOpen = true"
                                                    @input="item.isDropdownOpen = true"
                                                    placeholder="اختر أو اكتب اسم منتج للإنشاء..."
                                                    class="w-full text-xs rounded-xl border border-slate-200 ps-2.5 pe-8 py-2 focus:border-indigo-500 font-bold text-slate-800 transition"
                                                    required
                                                />
                                                <div class="absolute inset-y-0 end-0 pe-2.5 flex items-center pointer-events-none text-slate-400">
                                                    <ChevronUpDownIcon class="w-3.5 h-3.5" />
                                                </div>
                                            </div>

                                            <!-- Dropdown for Product Matching & Fast-Creation On Typing -->
                                            <div 
                                                v-if="item.isDropdownOpen"
                                                class="absolute start-3 end-3 top-full mt-1 bg-white border border-slate-200 rounded-2xl shadow-xl z-50 max-h-64 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                            >
                                                <!-- Create Product Action ONLY on Typing Non-existent product -->
                                                <div 
                                                    v-if="isNonExistingProduct(item.item_name)"
                                                    @click.stop="openNewProductModal(idx, item.item_name)"
                                                    class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <CubeIcon class="w-4 h-4 stroke-2" />
                                                        <span>إنشاء "{{ item.item_name.trim() }}"</span>
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
                                                                <span v-if="prod.barcode" class="font-mono">باركد: {{ prod.barcode }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="font-bold text-indigo-600 font-mono">
                                                                {{ formatCurrency(prod.purchase_price) }} ﷼
                                                            </span>
                                                            <div class="text-3xs text-slate-400">سعر الشراء</div>
                                                        </div>
                                                    </div>

                                                    <div v-if="getFilteredProducts(item.item_name).length === 0" class="p-2.5 text-center text-xs text-slate-400">
                                                        لا يوجد صنف مطابق، اضغط على زر الإنشاء أعلاه لإضافته فوراً.
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-2.5 px-2">
                                            <input 
                                                v-model.number="item.quantity" 
                                                type="number" 
                                                step="0.01" 
                                                min="0.01" 
                                                required 
                                                class="w-full text-xs rounded-xl border border-slate-200 px-2 py-2 font-mono text-center font-bold focus:border-indigo-500"
                                            />
                                        </td>

                                        <!-- Purchase Price -->
                                        <td class="py-2.5 px-2">
                                            <input 
                                                v-model.number="item.unit_price" 
                                                type="number" 
                                                step="0.01" 
                                                min="0" 
                                                required 
                                                class="w-full text-xs rounded-xl border border-slate-200 px-2 py-2 font-mono text-end font-bold focus:border-indigo-500"
                                            />
                                        </td>

                                        <!-- Tax Rate -->
                                        <td class="py-2.5 px-2 text-center">
                                            <span class="inline-block py-2 px-2 text-2xs font-mono font-bold text-slate-500 bg-slate-50 rounded-xl border border-slate-100">
                                                {{ lineRate() }}%
                                            </span>
                                        </td>

                                        <!-- Total -->
                                        <td class="py-2.5 px-3 text-end font-mono font-bold text-slate-900 pt-3">
                                            {{ formatCurrency(calculateLineTotal(item)) }}
                                        </td>

                                        <!-- Remove Row -->
                                        <td class="py-2.5 px-1 text-center pt-2">
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
                                <span>{{ $taxLabel() }}:</span>
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

        <!-- ========================================== -->
        <!-- POPUP MODAL: CREATE NEW SUPPLIER           -->
        <!-- ========================================== -->
        <div 
            v-if="isSupplierModalOpen" 
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200 overflow-y-auto"
        >
            <div class="bg-white rounded-3xl border border-slate-100 shadow-2xl max-w-xl w-full max-h-[90vh] flex flex-col overflow-hidden my-auto">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 border border-indigo-200/80 text-indigo-600 flex items-center justify-center">
                            <UserPlusIcon class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-900">إضافة مورد جديد</h3>
                            <p class="text-3xs text-slate-500">تسجيل بيانات مورد واعتماده في الفاتورة مباشرة</p>
                        </div>
                    </div>
                    <button 
                        type="button"
                        @click="closeSupplierModal"
                        class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                    >
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Body Form -->
                <div class="p-6 overflow-y-auto space-y-4 text-xs">
                    <!-- General Error Alert -->
                    <div v-if="supplierModalErrors.general" class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 font-bold">
                        {{ supplierModalErrors.general[0] }}
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Supplier Name -->
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">اسم المورد / المسؤول <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newSupplierForm.name"
                                type="text" 
                                required
                                placeholder="مثال: شركة التوريدات المتقدمة / أحمد محمود"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-bold"
                            />
                            <p v-if="supplierModalErrors.name" class="text-3xs text-rose-600 mt-1 font-semibold">{{ supplierModalErrors.name[0] }}</p>
                        </div>

                        <!-- Company Name -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">اسم الشركة / المؤسسة</label>
                            <input 
                                v-model="newSupplierForm.company_name"
                                type="text" 
                                placeholder="اسم الشركة التجاري"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">رقم الهاتف / الجوال</label>
                            <input 
                                v-model="newSupplierForm.phone"
                                type="text" 
                                dir="ltr"
                                placeholder="05xxxxxxxx"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Tax Number -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الرقم الضريبي (15 رقم)</label>
                            <input 
                                v-model="newSupplierForm.tax_number"
                                type="text" 
                                maxlength="15"
                                dir="ltr"
                                placeholder="300000000000003"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Commercial Register -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">السجل التجاري</label>
                            <input 
                                v-model="newSupplierForm.commercial_register"
                                type="text" 
                                dir="ltr"
                                placeholder="1010xxxxxx"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                            <input 
                                v-model="newSupplierForm.email"
                                type="email" 
                                dir="ltr"
                                placeholder="supplier@example.com"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Opening Balance -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الرصيد الافتتاحي (﷼)</label>
                            <input 
                                v-model.number="newSupplierForm.opening_balance"
                                type="number" 
                                step="0.01"
                                placeholder="0.00"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Address -->
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">العنوان / المدينة</label>
                            <input 
                                v-model="newSupplierForm.address"
                                type="text" 
                                placeholder="المدينة، الحي، الشارع"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-end gap-3">
                    <button 
                        type="button"
                        @click="closeSupplierModal"
                        class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                    >
                        إلغاء
                    </button>
                    <button 
                        type="button"
                        @click="submitNewSupplier"
                        :disabled="isSavingSupplier || !newSupplierForm.name.trim()"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-2 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="isSavingSupplier" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4 stroke-2" />
                        <span>حفظ واعتماد المورد</span>
                    </button>
                </div>
            </div>
        </div>

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
                            <h3 class="text-sm font-black text-slate-900">إضافة صنف / منتج جديد للمخزون</h3>
                            <p class="text-3xs text-slate-500">أدخل تفاصيل الصنف وسيتم إدراجه في الفاتورة وحفظه في المنتجات</p>
                        </div>
                    </div>
                    <button 
                        type="button"
                        @click="closeProductModal"
                        class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                    >
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Body Form -->
                <div class="p-6 overflow-y-auto space-y-4 text-xs">
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
                            <label class="block font-bold text-slate-700 mb-1">اسم الصنف (بالعربية) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newProductForm.name_ar"
                                type="text" 
                                required
                                placeholder="مثال: زيت محرك 5W-30"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-bold"
                            />
                            <p v-if="productModalErrors.name_ar" class="text-3xs text-rose-600 mt-1 font-semibold">{{ productModalErrors.name_ar[0] }}</p>
                        </div>

                        <!-- Name English -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">اسم الصنف (بالإنجليزية)</label>
                            <input 
                                v-model="newProductForm.name_en"
                                type="text" 
                                dir="ltr"
                                placeholder="e.g. Engine Oil 5W-30"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">التصنيف</label>
                            <select 
                                v-model="newProductForm.category_id"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            >
                                <option value="">بدون تصنيف (عام)</option>
                                <option v-for="cat in categoriesList" :key="cat.id" :value="cat.id">
                                    {{ cat.name_ar }}
                                </option>
                            </select>
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الوحدة</label>
                            <select 
                                v-model="newProductForm.unit_id"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            >
                                <option value="">اختر الوحدة...</option>
                                <option v-for="u in unitsList" :key="u.id" :value="u.id">
                                    {{ u.name_ar }} ({{ u.code || u.name_en || '-' }})
                                </option>
                            </select>
                        </div>

                        <!-- Purchase Price -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">سعر الشراء / التكلفة (﷼) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="newProductForm.purchase_price"
                                type="number" 
                                step="0.01" 
                                min="0" 
                                required
                                placeholder="0.00"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold text-indigo-700"
                            />
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">سعر البيع المقترح (﷼)</label>
                            <input 
                                v-model.number="newProductForm.selling_price"
                                type="number" 
                                step="0.01" 
                                min="0" 
                                placeholder="0.00 (اختياري، يطابق الشراء إن ترك فارغاً)"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Tax Rate -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">نسبة الضريبة (%)</label>
                            <input 
                                v-model.number="newProductForm.tax_rate"
                                type="number" 
                                step="0.01" 
                                min="0" 
                                placeholder="15"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">كود الصنف (SKU)</label>
                            <input 
                                v-model="newProductForm.sku"
                                type="text" 
                                dir="ltr"
                                placeholder="PRD-26-00001"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Barcode -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">الباركود (Barcode)</label>
                            <input 
                                v-model="newProductForm.barcode"
                                type="text" 
                                dir="ltr"
                                placeholder="628xxxxxxxxxx"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Description -->
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">وصف الصنف / تفاصيل إضافية</label>
                            <textarea 
                                v-model="newProductForm.description"
                                rows="2" 
                                placeholder="وصف مواصفات الصنف..." 
                                class="w-full rounded-xl border border-slate-200 p-2.5 focus:border-indigo-500 resize-none text-slate-600"
                            ></textarea>
                        </div>
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
                        :disabled="isSavingProduct || !newProductForm.name_ar.trim()"
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
