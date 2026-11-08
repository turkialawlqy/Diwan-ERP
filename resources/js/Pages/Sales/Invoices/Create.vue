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
import { today } from '@/utils/format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    costCenters: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    dimensionPolicy: { type: Object, default: () => ({}) },
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
    invoice: { type: Object, default: null }, // editing an existing invoice
});
const editing = props.invoice;


// Tax rules of the chosen branch (its country): default rate of new lines and the totals label.
const selectedBranchId = ref(props.invoice?.branch_id || props.defaultBranchId || props.branches?.[0]?.id || '');
const branchTax = computed(() => props.branches?.find((b) => Number(b.id) === Number(selectedBranchId.value))?.tax);
const lineRate = () => Number(branchTax.value?.rate ?? props.defaultTaxRate ?? taxRate());

// Local state for dynamic lookup lists
const customersList = ref([...(props.customers || [])]);
if (props.invoice?.customer && !customersList.value.some((c) => c.id === props.invoice.customer_id)) customersList.value.unshift(props.invoice.customer);
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
    invoice_date: today(),
    due_date: today(),
    supply_date: today(),
    payment_method: 'bank_transfer',
    currency_code: '',
    exchange_rate: '',
    payment_status: 'paid',
    bank_account_id: '',
    paid_amount: 0,
    notes: 'شكراً لتعاملكم معنا.',
    cost_center_id: '',
    project_id: '',
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
// Editing: the invoice as saved (same number; saving undoes and re-posts it).
if (editing) {
    const d = (v) => (v ? String(v).substring(0, 10) : '');
    Object.assign(form, {
        customer_id: editing.customer_id,
        branch_id: editing.branch_id,
        invoice_number: editing.invoice_number,
        reference_number: editing.reference_number || '',
        invoice_date: d(editing.invoice_date),
        due_date: d(editing.due_date),
        supply_date: d(editing.supply_date),
        payment_method: editing.payment_method || 'credit',
        currency_code: editing.currency_code || '',
        exchange_rate: editing.exchange_rate || '',
        payment_status: editing.payment_status || 'unpaid',
        bank_account_id: editing.bank_account_id || '',
        paid_amount: Number(editing.paid_amount) || 0,
        notes: editing.notes || '',
        cost_center_id: editing.cost_center_id || '',
        project_id: editing.project_id || '',
        terms: editing.terms || '',
        items: (editing.items || []).map((i) => ({
            product_id: i.product_id, item_name: i.item_name, description: i.description || '', quantity: Number(i.quantity),
            unit_price: Number(i.unit_price), discount: Number(i.discount) || 0, tax_rate: Number(i.tax_rate), isDropdownOpen: false,
        })),
    });
    form.defaults();
}
useUnsavedGuard(form);

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
            productModalErrors.value = { general: [err.response?.data?.message || t('invc.item_save_error')] };
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
    if (editing) form.put(route('sales.invoices.update', editing.id));
    else form.post(route('sales.invoices.store'));
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
    const rows = lines.map((l) => ({ product_id: l.product_id, item_name: l.item_name, description: l.description || '', quantity: l.quantity, unit_price: l.unit_price, discount: l.discount, tax_rate: l.tax_rate, isDropdownOpen: false }));
    form.items = replace ? rows : [...form.items.filter((i) => i.item_name || i.product_id), ...rows];
};
</script>

<template>
    <AppLayout own-errors>
        <Head :title="editing ? $t('invc.edit_invoice', { number: editing.invoice_number }) : $t('invc.create_a_new_sales_invoice')" />

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
            <PageHeader :title="editing ? $t('invc.edit_invoice', { number: editing.invoice_number }) : $t('invc.create_invoice')" :crumbs="[{ label: editing ? $t('invc.edit_invoice_2') : $t('invc.create_invoice') }]">
<template #actions>
<Link 
                        :href="route('sales.invoices.index')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 font-medium text-xs sm:text-sm transition-all"
                    >
                        {{ $t('invc.cancel') }}
                    </Link>
                    <button 
                        @click="submitInvoice"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all disabled:opacity-50"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>{{ form.processing ? $t('invc.issuing') : $t('invc.save_and_issue_the_invoice') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- Validation Errors Alert -->
            <div v-if="Object.keys(form.errors).length > 0" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold">
                <p class="font-bold mb-1">{{ $t('invc.please_correct_the_following_err') }}</p>
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
                            <span>{{ $t('invc.invoice_basics') }}</span>
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Customer Searchable Combobox with Instant Create Button -->
                            <div class="sm:col-span-2 relative customer-combobox-wrapper">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                        <span>{{ $t('invc.customer') }}</span>
                                        <span class="text-rose-500">*</span>
                                        <span :title="$t('invc.search_for_a_customer_or_type_hi')" class="cursor-help text-slate-400 hover:text-slate-600">
                                            <InformationCircleIcon class="w-3.5 h-3.5" />
                                        </span>
                                    </label>
                                    <span v-if="selectedCustomer" class="text-xs text-slate-500 font-medium">
                                        {{ $t('invc.current_balance') }} <strong class="text-indigo-600">{{ formatCurrency(selectedCustomer.current_balance) }} ﷼</strong>
                                    </span>
                                </div>

                                <div class="relative">
                                    <input 
                                        type="text" 
                                        v-model="customerSearch"
                                        @focus="isCustomerDropdownOpen = true"
                                        @input="isCustomerDropdownOpen = true"
                                        :placeholder="$t('invc.search_by_customer_number_name_m')"
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
                                            <span>{{ $t('invc.create_named', { name: customerSearch.trim() || $t('invc.new_customer_q') }) }}</span>
                                        </div>
                                        <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">{{ $t('invc.add_window') }}</span>
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
                                                    <span v-if="c.tax_number">{{ $t('invc.tax_n', { n: c.tax_number }) }}</span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-3xs font-mono font-semibold text-slate-500">
                                                    {{ formatCurrency(c.current_balance) }} ﷼
                                                </span>
                                            </div>
                                        </div>

                                        <div v-if="filteredCustomers.length === 0" class="p-3 text-center text-xs text-slate-400">
                                            {{ $t('invc.no_customer_with_this_name_press') }}
                                        </div>
                                    </div>
                                </div>
                            </div>


                            <!-- Invoice Number -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.invoice_number') }} <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.invoice_number"
                                    type="text" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono font-bold text-indigo-700 bg-slate-50/50"
                                />
                            </div>

                            <!-- Reference Number -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.reference_purchase_order_number') }}</label>
                                <input 
                                    v-model="form.reference_number"
                                    type="text" 
                                    :placeholder="$t('invc.e_g_po_2026_09')"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Invoice Date -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.issue_date') }} <span class="text-rose-500">*</span></label>
                                <DatePicker 
                                    v-model="form.invoice_date" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" />
                            </div>

                            <!-- Supply Date -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.supply_date_zatca') }}</label>
                                <DatePicker 
                                    v-model="form.supply_date" 
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" />
                            </div>

                            <div class="sm:col-span-2">
                                <CurrencyPicker v-model:code="form.currency_code" v-model:rate="form.exchange_rate" :errors="form.errors" />
                            </div>

                            <!-- Payment Method -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.payment_method') }} <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_method" 
                                    @change="syncSelectedFinancialAccount"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-semibold"
                                >
                                    <option value="bank_transfer">{{ $t('invc.bank_transfer') }}</option>
                                    <option value="cash">{{ $t('invc.cash') }}</option>
                                    <option value="card">{{ $t('invc.mada_network_card') }}</option>
                                    <option value="credit">{{ $t('invc.credit_receivable') }}</option>
                                </select>
                            </div>

                            <!-- Payment Status -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">{{ $t('invc.payment_status') }} <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.payment_status" 
                                    @change="syncSelectedFinancialAccount"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-semibold"
                                >
                                    <option value="paid">{{ $t('invc.fully_paid') }}</option>
                                    <option value="partially_paid">{{ $t('invc.partially_paid') }}</option>
                                    <option value="unpaid">{{ $t('invc.unpaid_credit') }}</option>
                                </select>
                            </div>

                            <!-- Receiving Bank Account / Cash Treasury Selector -->
                            <div v-if="form.payment_status !== 'unpaid' && form.payment_method !== 'credit'" class="sm:col-span-2 bg-indigo-50/40 p-4 rounded-xl border border-indigo-100 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                        <BuildingOfficeIcon v-if="form.payment_method === 'bank_transfer' || form.payment_method === 'card'" class="w-4 h-4 text-indigo-600" />
                                        <BanknotesIcon v-else class="w-4 h-4 text-emerald-600" />
                                        <span>{{ form.payment_method === 'cash' ? $t('invc.cash_box_receiving_the_money') : $t('invc.bank_account_transferred_to_rece') }}</span>
                                        <span class="text-rose-500">*</span>
                                    </label>
                                    <span v-if="selectedBankAccountModel" class="text-2xs font-normal text-slate-500">
                                        {{ $t('invc.current_book_balance') }} <strong class="text-indigo-600 font-mono font-bold">{{ formatCurrency(selectedBankAccountModel.current_balance) }} ﷼</strong>
                                    </span>
                                </div>
                                <select 
                                    v-model="form.bank_account_id"
                                    class="w-full text-xs sm:text-sm rounded-xl border border-indigo-200 bg-white py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 font-bold text-slate-800 shadow-2xs"
                                    required
                                >
                                    <option value="" disabled>{{ $t('invc.choose_the_bank_account_or_cash') }}</option>
                                    <option 
                                        v-for="acc in activeFinancialAccounts" 
                                        :key="acc.id" 
                                        :value="acc.id"
                                    >
                                        {{ acc.name }} 
                                        {{ acc.account_number ? `(${acc.bank_name || $t('invc.account_no')}: ${acc.account_number})` : '' }} 
                                        - {{ $t('invc.balance_label') }} {{ formatCurrency(acc.current_balance) }} ﷼
                                    </option>
                                </select>
                                <p class="text-3xs text-slate-500">
                                    {{ form.payment_method === 'cash' 
                                        ? $t('invc.the_journal_entry_is_recorded_an') 
                                        : $t('invc.the_journal_entry_is_recorded_an_2') 
                                    }}
                                </p>
                            </div>

                            <!-- Partially Paid Amount Breakdown (if selected) -->
                            <div v-if="form.payment_status === 'partially_paid'" class="sm:col-span-2 bg-amber-50/50 p-4 rounded-xl border border-amber-200 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-amber-900 mb-1.5">{{ $t('invc.amount_paid_now_cash_to_account') }}</label>
                                        <input 
                                            v-model.number="form.paid_amount"
                                            type="text" inputmode="decimal" data-num 
                                            step="0.01" 
                                            min="0" 
                                            :max="grandTotal"
                                            class="w-full text-xs sm:text-sm rounded-xl border border-amber-300 px-3 py-2.5 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 bg-white font-mono font-bold text-amber-900"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-amber-900 mb-1.5">{{ $t('invc.remaining_amount_on_credit_as_a') }}</label>
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
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                            <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                <span>{{ $t('invc.invoice_lines_and_items') }}</span>
                                <span class="px-2 py-0.5 rounded-full text-2xs bg-indigo-50 text-indigo-700 font-bold">
                                    {{ $t('invc.n_lines', { n: form.items.length }) }}
                                </span>
                            </h2>
                            <ImportLines kind="sales" :customer-id="form.customer_id" @imported="importLines" />
                            <button 
                                type="button"
                                @click="addItem"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors"
                            >
                                <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                <span>{{ $t('invc.add_item') }}</span>
                            </button>
                        </div>

                        <!-- Items Table: wide lines scroll inside the card; `relative` keeps absolutely placed children inside the scroll box. -->
                        <div class="relative overflow-x-auto">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-500 font-bold">
                                        <th class="py-2.5 px-2 text-center w-8">#</th>
                                        <th class="py-2.5 px-3 min-w-[240px]">{{ $t('invc.product_item') }}</th>
                                        <th class="py-2.5 px-3 min-w-[150px]">{{ $t('invc.description') }}</th>
                                        <th class="py-2.5 px-2 w-20">{{ $t('invc.quantity') }}</th>
                                        <th class="py-2.5 px-2 w-24">{{ $t('invc.price') }}</th>
                                        <th class="py-2.5 px-2 w-20">{{ $t('invc.discount') }}</th>
                                        <th class="py-2.5 px-2 w-16 text-center">{{ $t('invc.tax') }}</th>
                                        <th class="py-2.5 px-3 w-28 text-end">{{ $t('invc.total') }}</th>
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
                                                    @focus="openProductList(item, $event)"
                                                    @input="openProductList(item, $event)"
                                                    :placeholder="$t('invc.choose_or_create_a_product')"
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
                                                :style="productListStyle"
                                                data-product-list
                                                class="fixed bg-white border border-slate-200 rounded-2xl shadow-xl z-40 max-h-64 overflow-y-auto p-1.5 divide-y divide-slate-100"
                                            >
                                                <!-- Create Product Button (Matches Screenshot) -->
                                                <div 
                                                    @click="openNewProductModal(idx, item.item_name)"
                                                    class="p-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold flex items-center justify-between cursor-pointer transition shadow-xs mb-1"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <PlusIcon class="w-4 h-4 stroke-2" />
                                                        <span>{{ $t('invc.create_named', { name: item.item_name.trim() || $t('invc.new_item_q') }) }}</span>
                                                    </div>
                                                    <span class="text-3xs bg-white/20 px-2 py-0.5 rounded-full font-medium">{{ $t('invc.item_window') }}</span>
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
                                                                <span v-if="prod.sku" class="font-mono">{{ $t('invc.code_n', { n: prod.sku }) }}</span>
                                                                <span v-if="prod.unit">({{ prod.unit.name_ar }})</span>
                                                            </div>
                                                        </div>
                                                        <div class="font-bold text-indigo-600 font-mono">
                                                            {{ formatCurrency(prod.selling_price) }} ﷼
                                                        </div>
                                                    </div>

                                                    <div v-if="getFilteredProducts(item.item_name).length === 0" class="p-2.5 text-center text-xs text-slate-400">
                                                        {{ $t('invc.no_matching_item_press_the_creat') }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Item Description -->
                                        <td class="py-3 px-3">
                                            <input 
                                                v-model="item.description"
                                                type="text" 
                                                :placeholder="$t('invc.line_description')"
                                                class="w-full text-2xs rounded-xl border border-slate-200 px-2.5 py-2 text-slate-600 focus:border-indigo-500"
                                            />
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.quantity"
                                                type="text" inputmode="decimal" data-num 
                                                step="0.01" 
                                                min="0.01" 
                                                class="w-full text-xs text-center rounded-xl border border-slate-200 px-2 py-2 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>

                                        <!-- Unit Price -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.unit_price"
                                                type="text" inputmode="decimal" data-num 
                                                step="0.01" 
                                                min="0" 
                                                class="w-full text-xs text-start rounded-xl border border-slate-200 px-2 py-2 focus:border-indigo-500 font-mono font-bold"
                                            />
                                        </td>

                                        <!-- Discount -->
                                        <td class="py-3 px-2">
                                            <input 
                                                v-model.number="item.discount"
                                                type="text" inputmode="decimal" data-num 
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
                                                :title="$t('invc.delete_line')"
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
                            <span>{{ $t('invc.add_a_new_invoice_line') }}</span>
                        </button>
                    </div>

                    <!-- Analytical dimensions: the cost centre and project this invoice's revenue belongs to -->
                    <div v-if="costCenters.length || projects.length" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs" data-invoice-dimensions>
                        <DimensionFields v-model:cost-center="form.cost_center_id" v-model:project="form.project_id" :policy="dimensionPolicy" :cost-centers="costCenters" :projects="projects" :errors="form.errors" />
                    </div>

                    <!-- Notes & Terms -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-2">
                            <label class="block text-xs font-bold text-slate-700">{{ $t('invc.invoice_notes') }}</label>
                            <textarea 
                                v-model="form.notes"
                                rows="3"
                                class="w-full text-xs rounded-xl border border-slate-200 p-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none text-slate-600"
                            ></textarea>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-2">
                            <label class="block text-xs font-bold text-slate-700">{{ $t('invc.terms_and_conditions') }}</label>
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
                            {{ $t('invc.accounts_and_tax_summary') }}
                        </h3>

                        <div class="space-y-3 text-xs">
                            <!-- Subtotal -->
                            <div class="flex items-center justify-between text-slate-600">
                                <span>{{ $t('invc.subtotal_before_tax') }}</span>
                                <span class="font-bold text-slate-800 font-mono">{{ formatCurrency(subtotal) }} ﷼</span>
                            </div>

                            <!-- Discount -->
                            <div v-if="totalDiscount > 0" class="flex items-center justify-between text-rose-600">
                                <span>{{ $t('invc.total_discount') }}</span>
                                <span class="font-bold font-mono">- {{ formatCurrency(totalDiscount) }} ﷼</span>
                            </div>

                            <!-- VAT -->
                            <div class="flex items-center justify-between text-slate-600">
                                <div class="flex items-center gap-1">
                                    <span>{{ $t('invc.value_added_tax') }}</span>
                                    <span class="px-1.5 py-0.5 rounded text-3xs font-bold bg-indigo-50 text-indigo-700">{{ lineRate() }}%</span>
                                </div>
                                <span class="font-bold text-slate-800 font-mono">{{ formatCurrency(totalTax) }} ﷼</span>
                            </div>

                            <!-- Divider -->
                            <div class="border-t border-slate-200 pt-3">
                                <div class="flex items-center justify-between text-slate-900">
                                    <span class="text-sm font-black">{{ $t('invc.total_including_tax') }}</span>
                                    <span class="text-base font-black text-indigo-600 font-mono">{{ formatCurrency(grandTotal) }} ﷼</span>
                                </div>
                            </div>

                            <!-- Payment breakdown if not fully paid -->
                            <div v-if="form.payment_status !== 'paid'" class="bg-slate-50 rounded-xl p-3 space-y-2 border border-slate-100">
                                <div class="flex items-center justify-between text-2xs text-slate-500">
                                    <span>{{ $t('invc.paid') }}</span>
                                    <span class="font-bold text-emerald-600 font-mono">{{ formatCurrency(form.paid_amount) }} ﷼</span>
                                </div>
                                <div class="flex items-center justify-between text-2xs text-slate-500">
                                    <span>{{ $t('invc.remaining_credit') }}</span>
                                    <span class="font-bold text-rose-600 font-mono">{{ formatCurrency(remainingBalance) }} ﷼</span>
                                </div>
                            </div>
                        </div>

                        <!-- ZATCA Badge Info -->
                        <div class="p-3 rounded-xl bg-emerald-50/70 border border-emerald-200/60 text-emerald-900 text-2xs space-y-1.5">
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                <span>{{ $t('invc.compliant_with_zatca') }}</span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                {{ $t('invc.a_qr_code_compliant_with_phases') }}
                            </p>
                        </div>

                        <!-- Big Submit Button -->
                        <button 
                            @click="submitInvoice"
                            :disabled="form.processing"
                            class="w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center justify-center gap-2 disabled:opacity-50"
                        >
                            <CheckIcon class="w-4 h-4 stroke-2" />
                            <span>{{ form.processing ? $t('invc.saving') : $t('invc.save_and_issue_the_invoice') }}</span>
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
                            <h3 class="text-sm font-black text-slate-900">{{ $t('invc.add_a_new_item_product') }}</h3>
                            <p class="text-3xs text-slate-500">{{ $t('invc.enter_the_item_details_and_it_is') }}</p>
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
                            {{ $t('invc.stock_product') }}
                        </button>
                        <button 
                            type="button"
                            @click="newProductForm.type = 'service'"
                            :class="newProductForm.type === 'service' ? 'bg-white text-indigo-700 shadow-xs font-bold' : 'text-slate-600 font-medium'"
                            class="flex-1 py-1.5 rounded-lg text-xs transition text-center"
                        >
                            {{ $t('invc.service_consultation') }}
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name Arabic -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.item_name_arabic') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="newProductForm.name_ar"
                                type="text" 
                                required
                                :placeholder="$t('invc.e_g_55_inch_smart_screen')"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-bold"
                            />
                            <p v-if="productModalErrors.name_ar" class="text-3xs text-rose-600 mt-1 font-semibold">{{ productModalErrors.name_ar[0] }}</p>
                        </div>

                        <!-- Name English -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.item_name_english') }}</label>
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
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.category') }}</label>
                            <SelectCreate v-model="newProductForm.category_id" :options="categoriesList" entity="category" label="name_ar" :placeholder="$t('invc.no_category_general')" />
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.unit') }}</label>
                            <SelectCreate v-model="newProductForm.unit_id" :options="unitsList" entity="unit" :label="(u) => `${u.name_ar} (${u.code || u.name_en || '-'})`" :placeholder="$t('invc.choose_the_unit')" />
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.default_selling_price_sar') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="newProductForm.selling_price"
                                type="text" inputmode="decimal" data-num 
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
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.purchase_price_cost_sar') }}</label>
                            <input 
                                v-model.number="newProductForm.purchase_price"
                                type="text" inputmode="decimal" data-num 
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Tax Rate -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.tax_rate') }}</label>
                            <input 
                                v-model.number="newProductForm.tax_rate"
                                type="text" inputmode="decimal" data-num 
                                step="0.01"
                                min="0"
                                placeholder="15"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.item_code_sku') }}</label>
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
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.barcode') }}</label>
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
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.opening_stock_quantity') }}</label>
                            <input 
                                v-model.number="newProductForm.initial_stock"
                                type="text" inputmode="decimal" data-num 
                                step="0.01"
                                min="0"
                                placeholder="0"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono"
                            />
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('invc.item_description_extra_details') }}</label>
                        <textarea 
                            v-model="newProductForm.description"
                            rows="2"
                            :placeholder="$t('invc.short_description_of_the_item_sp')"
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
                        {{ $t('invc.cancel') }}
                    </button>
                    <button 
                        type="button"
                        @click="submitNewProduct"
                        :disabled="isSavingProduct || !newProductForm.name_ar"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-2 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="isSavingProduct" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4 stroke-2" />
                        <span>{{ $t('invc.save_and_insert_into_the_invoice') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
