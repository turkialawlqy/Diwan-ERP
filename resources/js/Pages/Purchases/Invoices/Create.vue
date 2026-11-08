<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { useFixedDropdown } from '@/composables/useFixedDropdown';
import DatePicker from '@/Components/UI/DatePicker.vue';
import DimensionFields from '@/Components/Documents/DimensionFields.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
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
import { today, addDays } from '@/utils/format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    costCenters: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    dimensionPolicy: { type: Object, default: () => ({}) },
    suppliers: Array,
    warehouses: Array,
    branches: Array,
    products: Array,
    categories: Array,
    units: Array,
    bankAccounts: { type: Array, default: () => [] },
    suggestedNumber: String,
    defaultTaxRate: Number,
    defaultBranchId: Number,
    invoice: { type: Object, default: null }, // editing an existing bill
});
const editing = props.invoice;

// Reactive Lists for Local Updates
const suppliersList = ref([...(props.suppliers || [])]);
if (props.invoice?.supplier && !suppliersList.value.some((x) => x.id === props.invoice.supplier_id)) suppliersList.value.unshift(props.invoice.supplier);
const productsList = ref([...(props.products || [])]);
const categoriesList = ref([...(props.categories || [])]);
const unitsList = ref([...(props.units || [])]);


// Tax rules of the chosen branch (its country): default rate of new lines.
const selectedBranchId = ref(props.invoice?.branch_id || props.defaultBranchId || props.branches?.[0]?.id || '');
const branchTax = computed(() => props.branches?.find((b) => Number(b.id) === Number(selectedBranchId.value))?.tax);
const lineRate = () => Number(branchTax.value?.rate ?? lineRate());

const form = useForm({
    supplier_id: suppliersList.value[0]?.id || '',
    warehouse_id: props.warehouses?.[0]?.id || '',
    branch_id: selectedBranchId.value,
    invoice_number: props.suggestedNumber || '',
    supplier_invoice_number: '',
    cost_center_id: '',
    project_id: '',
    invoice_date: today(),
    due_date: addDays(today(), 30),
    supply_date: today(),
    payment_method: 'bank_transfer',
    currency_code: '',
    exchange_rate: '',
    payment_status: 'unpaid',
    paid_amount: 0,
    payments: [],
    notes: '',
    items: [
        {
            product_id: productsList.value[0]?.id || null,
            item_name: productsList.value[0]?.name_ar || t('pinv.new_supply_line'),
            quantity: 1,
            unit_price: Number(productsList.value[0]?.purchase_price) || 0,
            discount: 0,
            tax_rate: Number(productsList.value[0]?.tax_rate ?? taxRate()),
            isDropdownOpen: false,
        }
    ],
});
// Editing: the bill as saved (same number; saving undoes and re-posts it).
if (editing) {
    const d = (v) => (v ? String(v).substring(0, 10) : '');
    Object.assign(form, {
        supplier_id: editing.supplier_id,
        warehouse_id: editing.warehouse_id,
        branch_id: editing.branch_id,
        invoice_number: editing.invoice_number,
        supplier_invoice_number: editing.supplier_invoice_number || '',
        cost_center_id: editing.cost_center_id || '',
        project_id: editing.project_id || '',
        invoice_date: d(editing.invoice_date),
        due_date: d(editing.due_date),
        supply_date: d(editing.supply_date),
        currency_code: editing.currency_code || '',
        exchange_rate: editing.exchange_rate || '',
        payments: (editing.payments || []).map((p) => ({ method: p.method, bank_account_id: p.bank_account_id || '', amount: Number(p.amount) })),
        notes: editing.notes || '',
        items: (editing.items || []).map((i) => ({
            product_id: i.product_id, item_name: i.item_name, quantity: Number(i.quantity), unit_price: Number(i.unit_price),
            discount: Number(i.discount) || 0, tax_rate: Number(i.tax_rate), isDropdownOpen: false,
        })),
    });
    form.defaults();
}
useUnsavedGuard(form);

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
            supplierModalErrors.value = { general: [err.response?.data?.message || t('pinv.supplier_save_error')] };
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
            productModalErrors.value = { general: [err.response?.data?.message || t('pinv.item_save_error')] };
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

const addPayment = () => form.payments.push({ method: 'cash', bank_account_id: '', amount: Math.max(0, remainingNow.value) || '' });
const paidNow = computed(() => form.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0));
const remainingNow = computed(() => Math.round((total.value - paidNow.value) * 100) / 100);
const submit = () => {
    form.transform((d) => ({ ...d, payments: d.payments.map((p) => ({ ...p, amount: Number(p.amount) || 0, bank_account_id: p.bank_account_id || null })), payment_method: d.payments.length ? d.payments[0].method : 'credit', payment_status: 'unpaid' }))
        .submit(editing ? 'put' : 'post', editing ? route('purchases.invoices.update', editing.id) : route('purchases.invoices.store'));
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

// The lines table scrolls sideways inside its card on narrow screens, so the
// product list is fixed under its field instead of hanging from the row.
const { style: productListStyle, anchor: anchorProductList } = useFixedDropdown(() => form.items.some(it => it.isDropdownOpen));
const openProductList = (item, event) => {
    form.items.forEach(it => { it.isDropdownOpen = it === item; }); // one list at a time: they share the position
    anchorProductList(event.target);
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
        <Head :title="editing ? $t('pinv.edit_invoice', { number: editing.invoice_number }) : $t('pinv.create_a_new_purchase_invoice')" />

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
            <PageHeader :title="editing ? $t('pinv.edit_invoice', { number: editing.invoice_number }) : $t('pinv.create_purchase_invoice')" :crumbs="[{ label: $t('pinv.purchase_invoices') }, { label: editing ? $t('pinv.edit') : $t('pinv.create_purchase_invoice') }]">
<template #actions>
<Link 
                        :href="route('purchases.invoices.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50"
                    >
                        {{ $t('pinv.cancel') }}
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('pinv.save_the_invoice_and_update_stoc') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- Main Form Layout -->
            <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left 2 Cols: Main Invoice Details & Items -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Information Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2 pb-2 border-b border-slate-100">
                            <BuildingOfficeIcon class="w-4 h-4 text-indigo-600" />
                            <span>{{ $t('pinv.supplier_and_warehouse') }}</span>
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <!-- Supplier Searchable Combobox with Instant Create Option On Typing -->
                            <div class="sm:col-span-2 relative supplier-combobox-wrapper">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block font-bold text-slate-700 flex items-center gap-1.5">
                                        <span>{{ $t('pinv.supplier') }}</span>
                                        <span class="text-rose-500">*</span>
                                        <span :title="$t('pinv.search_by_supplier_number_name_o')" class="cursor-help text-slate-400 hover:text-slate-600">
                                            <InformationCircleIcon class="w-3.5 h-3.5" />
                                        </span>
                                    </label>
                                    <span v-if="selectedSupplier" class="text-xs text-slate-500 font-medium">
                                        {{ $t('pinv.current_balance_due') }} <strong class="text-indigo-600">{{ formatCurrency(selectedSupplier.current_balance) }} ﷼</strong>
                                    </span>
                                </div>

                                <div class="relative">
                                    <input 
                                        type="text" 
                                        v-model="supplierSearch"
                                        @focus="isSupplierDropdownOpen = true"
                                        @input="isSupplierDropdownOpen = true"
                                        :placeholder="$t('pinv.search_by_supplier_number_name_m')"
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
                                            <span>{{ $t('pinv.create_named', { name: supplierSearch.trim() }) }}</span>
                                        </div>
                                        <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">{{ $t('pinv.add_window') }}</span>
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
                                                    <span v-if="s.tax_number">{{ $t('pinv.tax_n', { n: s.tax_number }) }}</span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-3xs font-mono font-semibold text-slate-500">
                                                    {{ formatCurrency(s.current_balance) }} ﷼
                                                </span>
                                            </div>
                                        </div>

                                        <div v-if="filteredSuppliers.length === 0" class="p-3 text-center text-xs text-slate-400">
                                            {{ $t('pinv.no_supplier_with_this_name_press') }}
                                        </div>
                                    </div>
                                </div>
                            </div>


                            <!-- Warehouse Selection -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.receiving_warehouse') }} <span class="text-rose-500">*</span></label>
                                <SelectCreate v-model="form.warehouse_id" :options="branchWarehouses" entity="warehouse" label="name" :allow-empty="false" required />
                            </div>

                            <!-- Invoice Number -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.system_invoice_number') }} <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.invoice_number" 
                                    type="text" 
                                    required 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono font-bold text-indigo-700 focus:border-indigo-500"
                                />
                            </div>

                            <!-- Supplier's Own Invoice Number -->
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.supplier_invoice_number_referenc') }}</label>
                                <input 
                                    v-model="form.supplier_invoice_number" 
                                    type="text" 
                                    :placeholder="$t('pinv.e_g_inv_sup_2026_99')" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-xs">
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">{{ $t('pinv.invoice_date') }}</label>
                                <DatePicker 
                                    v-model="form.invoice_date" 
                                    required 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500" />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">{{ $t('pinv.due_date') }}</label>
                                <DatePicker 
                                    v-model="form.due_date" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500" />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-600 mb-1">{{ $t('pinv.goods_receipt_date') }}</label>
                                <DatePicker 
                                    v-model="form.supply_date" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500" />
                            </div>
                        </div>
                        <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" class="pt-2" />
                    </div>

                    <!-- Items Table Card -->
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                            <h2 class="text-xs font-bold text-slate-800 flex items-center gap-2">
                                <ArchiveBoxIcon class="w-4 h-4 text-indigo-600" />
                                <span>{{ $t('pinv.purchase_invoice_lines_and_recei') }}</span>
                            </h2>
                            <ImportLines kind="purchase" @imported="importLines" />
                            <button 
                                type="button" 
                                @click="addItem"
                                class="px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition-colors flex items-center gap-1 border border-indigo-200/60"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>{{ $t('pinv.add_line') }}</span>
                            </button>
                        </div>

                        <!-- Wide lines scroll inside the card; `relative` keeps absolutely placed children inside the scroll box. -->
                        <div class="relative overflow-x-auto">
                            <table class="w-full text-right text-xs">
                                <thead>
                                    <tr class="text-slate-400 font-bold border-b border-slate-100 pb-2">
                                        <th class="py-2.5 px-2 text-center w-8">#</th>
                                        <th class="py-2.5 px-3 min-w-[240px]">{{ $t('pinv.product_item') }}</th>
                                        <th class="py-2.5 px-2 w-24">{{ $t('pinv.quantity') }}</th>
                                        <th class="py-2.5 px-2 w-28">{{ $t('pinv.purchase_price') }}</th>
                                        <th class="py-2.5 px-2 w-16 text-center">{{ $t('pinv.tax') }}</th>
                                        <th class="py-2.5 px-3 w-28 text-end">{{ $t('pinv.total') }}</th>
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
                                                    @focus="openProductList(item, $event)"
                                                    @input="openProductList(item, $event)"
                                                    :placeholder="$t('pinv.choose_or_type_a_product_name_to')"
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
                                                :style="productListStyle"
                                                data-product-list
                                                class="fixed bg-white border border-slate-200 rounded-2xl shadow-xl z-50 max-h-64 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                            >
                                                <!-- Create Product Action ONLY on Typing Non-existent product -->
                                                <div 
                                                    v-if="isNonExistingProduct(item.item_name)"
                                                    @click.stop="openNewProductModal(idx, item.item_name)"
                                                    class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <CubeIcon class="w-4 h-4 stroke-2" />
                                                        <span>{{ $t('pinv.create_named', { name: item.item_name.trim() }) }}</span>
                                                    </div>
                                                    <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">{{ $t('pinv.item_window') }}</span>
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
                                                                <span v-if="prod.sku" class="font-mono">{{ $t('pinv.code_n', { n: prod.sku }) }}</span>
                                                                <span v-if="prod.barcode" class="font-mono">{{ $t('pinv.barcode_n', { n: prod.barcode }) }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="font-bold text-indigo-600 font-mono">
                                                                {{ formatCurrency(prod.purchase_price) }} ﷼
                                                            </span>
                                                            <div class="text-3xs text-slate-400">{{ $t('pinv.purchase_price') }}</div>
                                                        </div>
                                                    </div>

                                                    <div v-if="getFilteredProducts(item.item_name).length === 0" class="p-2.5 text-center text-xs text-slate-400">
                                                        {{ $t('pinv.no_matching_item_press_the_creat') }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-2.5 px-2">
                                            <input 
                                                v-model.number="item.quantity" 
                                                type="text" inputmode="decimal" data-num 
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
                                                type="text" inputmode="decimal" data-num 
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

                        <!-- Analytical dimensions: the cost centre and project this bill's cost belongs to -->
                        <div v-if="costCenters.length || projects.length" class="pt-3 border-t border-slate-100" data-invoice-dimensions>
                            <DimensionFields v-model:cost-center="form.cost_center_id" v-model:project="form.project_id" :policy="dimensionPolicy" :cost-centers="costCenters" :projects="projects" :errors="form.errors" />
                        </div>

                        <!-- Notes -->
                        <div class="pt-3 border-t border-slate-100">
                            <label class="block text-2xs font-bold text-slate-600 mb-1">{{ $t('pinv.invoice_notes_or_supply_terms') }}</label>
                            <textarea 
                                v-model="form.notes" 
                                rows="2" 
                                :placeholder="$t('pinv.write_any_special_terms_or_deliv')" 
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
                            <span>{{ $t('pinv.account_and_tax_summary') }}</span>
                        </h2>

                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between py-1 text-slate-600">
                                <span>{{ $t('pinv.total_excluding_tax') }}</span>
                                <span class="font-mono font-bold">{{ formatCurrency(subtotal) }} ﷼</span>
                            </div>
                            <div class="flex justify-between py-1 text-slate-600">
                                <span>{{ $taxLabel() }}:</span>
                                <span class="font-mono font-bold text-indigo-600">{{ formatCurrency(taxAmount) }} ﷼</span>
                            </div>
                            <div class="flex justify-between py-2 border-t border-slate-200 text-sm font-black text-slate-900">
                                <span>{{ $t('pinv.final_total') }}</span>
                                <span class="font-mono text-indigo-700">{{ formatCurrency(total) }} ﷼</span>
                            </div>
                        </div>

                        <!-- Payment Status & Method -->
                        <div class="pt-4 border-t border-slate-100 space-y-3 text-xs">
                            <div class="flex items-center justify-between">
                                <label class="block font-bold text-slate-700">{{ $t('pinv.payments_at_issue') }}</label>
                                <button type="button" @click="addPayment" class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold" data-add-payment>{{ $t('pinv.payment') }}</button>
                            </div>
                            <p v-if="!form.payments.length" class="text-slate-500">{{ $t('pinv.the_invoice_is_fully_on_credit_a') }}</p>
                            <div v-for="(p, i) in form.payments" :key="i" class="grid grid-cols-12 gap-2 items-end p-2 rounded-xl bg-slate-50 border border-slate-100" data-payment-row>
                                <select v-model="p.method" class="col-span-4 rounded-lg border border-slate-200 ps-2 pe-8 py-1.5 font-medium">
                                    <option value="cash">{{ $t('pinv.cash') }}</option>
                                    <option value="bank_transfer">{{ $t('pinv.bank_transfer') }}</option>
                                    <option value="card">{{ $t('pinv.card_mada') }}</option>
                                </select>
                                <div class="col-span-4"><SelectCreate v-model="p.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('pinv.cash_box_bank')" /></div>
                                <input v-model="p.amount" type="text" inputmode="decimal" data-num class="col-span-3 rounded-lg border border-slate-200 px-2 py-1.5 font-mono font-bold text-emerald-600" />
                                <button type="button" @click="form.payments.splice(i, 1)" class="col-span-1 text-rose-500 font-bold">×</button>
                            </div>
                            <div v-if="form.payments.length" class="flex justify-between text-slate-600">
                                <span>{{ $t('pinv.paid') }} <b class="font-mono text-emerald-600">{{ formatCurrency(paidNow) }}</b></span>
                                <span>{{ $t('pinv.remaining_to_the_supplier') }} <b class="font-mono" :class="remainingNow < 0 ? 'text-rose-600' : 'text-slate-800'">{{ formatCurrency(remainingNow) }}</b></span>
                            </div>
                            <p v-if="form.errors.payments" class="text-rose-600 font-bold">{{ form.errors.payments }}</p>
                        </div>

                        <div class="pt-2">
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
                            >
                                <CheckIcon class="w-4 h-4 stroke-2" />
                                <span>{{ $t('pinv.save_the_invoice_and_receive_the') }}</span>
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
                            <h3 class="text-sm font-black text-slate-900">{{ $t('pinv.add_a_new_supplier') }}</h3>
                            <p class="text-3xs text-slate-500">{{ $t('pinv.record_a_supplier_and_use_him_in') }}</p>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.supplier_contact_name') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newSupplierForm.name"
                                type="text" 
                                required
                                :placeholder="$t('pinv.e_g_advanced_supplies_co_ahmad_m')"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-bold"
                            />
                            <p v-if="supplierModalErrors.name" class="text-3xs text-rose-600 mt-1 font-semibold">{{ supplierModalErrors.name[0] }}</p>
                        </div>

                        <!-- Company Name -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.company_establishment_name') }}</label>
                            <input 
                                v-model="newSupplierForm.company_name"
                                type="text" 
                                :placeholder="$t('pinv.company_trade_name')"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.phone_mobile_number') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.tax_number_15_digits') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.commercial_register') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.email') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.opening_balance_sar') }}</label>
                            <input 
                                v-model.number="newSupplierForm.opening_balance"
                                type="text" inputmode="decimal" data-num 
                                step="0.01"
                                placeholder="0.00"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Address -->
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.address_city') }}</label>
                            <input 
                                v-model="newSupplierForm.address"
                                type="text" 
                                :placeholder="$t('pinv.city_district_street')"
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
                        {{ $t('pinv.cancel') }}
                    </button>
                    <button 
                        type="button"
                        @click="submitNewSupplier"
                        :disabled="isSavingSupplier || !newSupplierForm.name.trim()"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-2 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="isSavingSupplier" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4 stroke-2" />
                        <span>{{ $t('pinv.save_and_approve_the_supplier') }}</span>
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
                            <h3 class="text-sm font-black text-slate-900">{{ $t('pinv.add_a_new_stock_item_product') }}</h3>
                            <p class="text-3xs text-slate-500">{{ $t('pinv.enter_the_item_details_it_is_ins') }}</p>
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
                            {{ $t('pinv.stock_product') }}
                        </button>
                        <button 
                            type="button"
                            @click="newProductForm.type = 'service'"
                            :class="newProductForm.type === 'service' ? 'bg-white text-indigo-700 shadow-xs font-bold' : 'text-slate-600 font-medium'"
                            class="flex-1 py-1.5 rounded-lg text-xs transition text-center"
                        >
                            {{ $t('pinv.service_consultation') }}
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name Arabic -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.item_name_arabic') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newProductForm.name_ar"
                                type="text" 
                                required
                                :placeholder="$t('pinv.e_g_5w_30_engine_oil')"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-bold"
                            />
                            <p v-if="productModalErrors.name_ar" class="text-3xs text-rose-600 mt-1 font-semibold">{{ productModalErrors.name_ar[0] }}</p>
                        </div>

                        <!-- Name English -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.item_name_english') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.category') }}</label>
                            <SelectCreate v-model="newProductForm.category_id" :options="categoriesList" entity="category" label="name_ar" :placeholder="$t('pinv.no_category_general')" />
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.unit') }}</label>
                            <SelectCreate v-model="newProductForm.unit_id" :options="unitsList" entity="unit" :label="(u) => `${u.name_ar} (${u.code || u.name_en || '-'})`" :placeholder="$t('pinv.choose_the_unit')" />
                        </div>

                        <!-- Purchase Price -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.purchase_price_cost_sar') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="newProductForm.purchase_price"
                                type="text" inputmode="decimal" data-num 
                                step="0.01" 
                                min="0" 
                                required
                                placeholder="0.00"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold text-indigo-700"
                            />
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.suggested_selling_price_sar') }}</label>
                            <input 
                                v-model.number="newProductForm.selling_price"
                                type="text" inputmode="decimal" data-num 
                                step="0.01" 
                                min="0" 
                                :placeholder="$t('pinv.0_00_optional_equals_the_purchas')"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Tax Rate -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.tax_rate') }}</label>
                            <input 
                                v-model.number="newProductForm.tax_rate"
                                type="text" inputmode="decimal" data-num 
                                step="0.01" 
                                min="0" 
                                placeholder="15"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.item_code_sku') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.barcode') }}</label>
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
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('pinv.item_description_extra_details') }}</label>
                            <textarea 
                                v-model="newProductForm.description"
                                rows="2" 
                                :placeholder="$t('pinv.item_specifications')" 
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
                        {{ $t('pinv.cancel') }}
                    </button>
                    <button 
                        type="button"
                        @click="submitNewProduct"
                        :disabled="isSavingProduct || !newProductForm.name_ar.trim()"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-2 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="isSavingProduct" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4 stroke-2" />
                        <span>{{ $t('pinv.save_and_insert_into_the_invoice') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
