<script setup>
import { taxRate } from '@/utils/tax';
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import axios from 'axios';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import BrandLogo from '../../Components/BrandLogo.vue';
import QRCode from 'qrcode';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    MinusIcon,
    TrashIcon,
    CheckCircleIcon,
    PrinterIcon,
    XMarkIcon,
    ArrowLeftOnRectangleIcon,
    CreditCardIcon,
    BanknotesIcon,
    CubeIcon,
    ArrowPathIcon,
    ShieldCheckIcon,
    ArrowsPointingOutIcon,
    DocumentCheckIcon,
    UserIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    boxes: { type: Array, default: () => [] },
    session: Object,
    products: Array,
    categories: Array,
    customers: Array,
    company: Object,
});

const page = usePage();
const barcodeInput = ref(null);
const searchQuery = ref('');
const selectedCategoryId = ref('all');
const selectedCustomerId = ref(props.customers?.[0]?.id || null);

// Cart State
const cart = ref([]);
const isCashModalOpen = ref(false);
const isCloseSessionModalOpen = ref(false);
const isReceiptModalOpen = ref(false);
const tenderAmount = ref(0);
const latestOrder = ref(null);
const receiptQrUrl = ref('');

// Products are searched on the server (the catalogue can hold millions of items);
// the page only ever keeps one bounded result set.
const products = ref(props.products || []);
const isSearching = ref(false);
let searchTimer = null;
let searchSeq = 0;

const fetchProducts = async (params = {}) => {
    const seq = ++searchSeq;
    isSearching.value = true;
    try {
        const { data } = await axios.get(route('pos.products.search'), {
            params: {
                q: searchQuery.value.trim() || undefined,
                category_id: selectedCategoryId.value !== 'all' ? selectedCategoryId.value : undefined,
                ...params,
            },
        });
        if (seq === searchSeq) products.value = data.data;
        return data.data;
    } finally {
        if (seq === searchSeq) isSearching.value = false;
    }
};

watch([searchQuery, selectedCategoryId], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => fetchProducts(), 250);
});

const filteredProducts = computed(() => products.value);

// Cart Calculations
const cartSubtotal = computed(() => {
    return cart.value.reduce((acc, item) => acc + (item.quantity * item.unit_price), 0);
});

const cartTax = computed(() => {
    return cartSubtotal.value * taxRate() / 100;
});

const cartTotal = computed(() => {
    return cartSubtotal.value + cartTax.value;
});

const changeDue = computed(() => {
    return Math.max(0, tenderAmount.value - cartTotal.value);
});

// Add product to cart
const addToCart = (product) => {
    const existing = cart.value.find(item => item.product_id === product.id);
    if (existing) {
        existing.quantity += 1;
    } else {
        cart.value.push({
            product_id: product.id,
            item_name: product.name_ar,
            unit_price: Number(product.selling_price) || 0,
            quantity: 1,
            tax_rate: taxRate(),
            discount: 0,
        });
    }
};

// Barcode Scan / Enter handler
const handleBarcodeEnter = async () => {
    const query = searchQuery.value.trim();
    if (!query) return;

    const isExact = (p) => (p.barcode && p.barcode === query) || (p.sku && p.sku.toLowerCase() === query.toLowerCase());
    let matched = products.value.find(isExact);

    if (!matched) {
        clearTimeout(searchTimer);
        const results = await fetchProducts({ q: query, exact: 1, category_id: undefined });
        matched = results.find(isExact);
    }

    if (matched) {
        addToCart(matched);
        searchQuery.value = '';
    }
};

const updateQty = (index, delta) => {
    cart.value[index].quantity += delta;
    if (cart.value[index].quantity <= 0) {
        cart.value.splice(index, 1);
    }
};

const removeFromCart = (index) => {
    cart.value.splice(index, 1);
};

const clearCart = () => {
    cart.value = [];
};

// Quick Tender Buttons (+10, +50, +100, +500)
const addTender = (val) => {
    tenderAmount.value = (Number(tenderAmount.value) || 0) + val;
};

const setExactTender = () => {
    tenderAmount.value = Math.ceil(cartTotal.value);
};

// Checkout
const openCashCheckout = () => {
    if (cart.value.length === 0) return;
    tenderAmount.value = Math.ceil(cartTotal.value);
    isCashModalOpen.value = true;
};

// Split tender: part cash, part card.
const isSplitModalOpen = ref(false);
const splitCard = ref(0);
const splitCash = ref(0);
const openSplitCheckout = () => {
    if (cart.value.length === 0) return;
    splitCard.value = 0;
    splitCash.value = Math.ceil(cartTotal.value);
    isSplitModalOpen.value = true;
};
const submitSplit = () => submitOrder('multiple', (Number(splitCash.value) || 0) + (Number(splitCard.value) || 0), { cash_amount: Number(splitCash.value) || 0, card_amount: Number(splitCard.value) || 0 });

const submitOrder = (paymentMethod, paid, tender = {}) => {
    if (cart.value.length === 0) return;

    router.post(route('pos.order.process'), {
        session_id: props.session.id,
        customer_id: selectedCustomerId.value,
        payment_method: paymentMethod,
        paid_amount: paid,
        ...tender,
        discount: 0,
        items: cart.value,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            isCashModalOpen.value = false;
            isSplitModalOpen.value = false;
            const ord = page.props.flash?.latest_order;
            if (ord) {
                latestOrder.value = ord;
                generateReceiptQr(ord.zatca_qr);
                isReceiptModalOpen.value = true;
            }
            clearCart();
        }
    });
};

const generateReceiptQr = async (qrString) => {
    if (!qrString) return;
    try {
        receiptQrUrl.value = await QRCode.toDataURL(qrString, {
            width: 140,
            margin: 1,
            color: { dark: '#000000', light: '#ffffff' },
        });
    } catch (e) {
        console.error('Failed to generate receipt QR', e);
    }
};

const printReceipt = () => {
    window.print();
};

// Close Session Form
const closeSessionForm = useForm({
    closing_cash: 0,
    deposit_bank_account_id: '',
    notes: '',
});

const submitCloseSession = () => {
    closeSessionForm.post(route('pos.session.close', props.session.id));
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

onMounted(() => {
    nextTick(() => {
        barcodeInput.value?.focus();
    });
});
</script>

<template>
    <div class="h-screen flex flex-col bg-slate-100 text-slate-800 font-sans select-none overflow-hidden">
        <Head title="نقطة البيع" />

        <!-- 1. Top Navbar Header -->
        <header class="bg-indigo-700 text-white px-4 py-2.5 flex items-center justify-between shadow-md z-20 flex-shrink-0">
            <!-- Left (Branding & Register) -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-white/10 flex items-center justify-center font-black text-sm">
                        OS
                    </span>
                    <div>
                        <h1 class="text-sm font-black leading-tight">نقطة البيع (POS)</h1>
                        <p class="text-3xs text-indigo-200">{{ $page.props.brand?.name }} - {{ props.session.register?.name || 'جهاز افتراضي' }}</p>
                    </div>
                </div>
                <div class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 text-2xs font-bold border border-emerald-400/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>الجلسة نشطة</span>
                </div>
            </div>

            <!-- Center (Customer Quick Selector) -->
            <div class="flex items-center gap-2 max-w-xs w-full">
                <select 
                    v-model="selectedCustomerId" 
                    class="w-full text-xs rounded-xl bg-indigo-800/80 border-indigo-600 text-white py-1.5 px-3 focus:ring-1 focus:ring-white"
                >
                    <option :value="null">عميل نقدي (عام)</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </div>

            <!-- Right (Session Actions) -->
            <div class="flex items-center gap-2">
                <Link 
                    :href="route('dashboard')"
                    class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold transition-colors flex items-center gap-1.5"
                    title="العودة للوحة التحكم"
                >
                    <ArrowLeftOnRectangleIcon class="w-4 h-4 rtl:rotate-180" />
                    <span class="hidden md:inline">لوحة التحكم</span>
                </Link>

                <button 
                    @click="isCloseSessionModalOpen = true"
                    class="px-3 py-1.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold shadow-xs transition-colors"
                >
                    إغلاق الجلسة
                </button>
            </div>
        </header>

        <!-- 2. Main Work Area (Split Grid) -->
        <div class="flex-1 flex flex-col md:flex-row overflow-hidden">
            <!-- Center & Products Showcase Area (70%) -->
            <div class="flex-1 flex flex-col p-4 overflow-hidden space-y-3">
                <!-- Search & Barcode Scan Bar -->
                <div class="flex items-center gap-2 bg-white rounded-2xl p-2 border border-slate-200 shadow-2xs">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="w-5 h-5 text-slate-400 absolute start-3 top-2.5" />
                        <input 
                            ref="barcodeInput"
                            v-model="searchQuery"
                            @keyup.enter="handleBarcodeEnter"
                            type="text" 
                            placeholder="امسح الباركود أو ابحث عن المنتج / الخدمة..." 
                            class="w-full text-xs sm:text-sm ps-10 pe-4 py-2 rounded-xl border-none focus:ring-0 text-slate-800 font-medium placeholder-slate-400"
                        />
                    </div>
                </div>

                <!-- Category Pills -->
                <div class="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none flex-shrink-0">
                    <button 
                        @click="selectedCategoryId = 'all'"
                        :class="[
                            'px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all shadow-2xs',
                            selectedCategoryId === 'all' 
                                ? 'bg-indigo-600 text-white shadow-indigo-500/20' 
                                : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200'
                        ]"
                    >
                        الكل ({{ products.length }})
                    </button>
                    <button 
                        v-for="cat in categories" 
                        :key="cat.id"
                        @click="selectedCategoryId = cat.id"
                        :class="[
                            'px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all shadow-2xs',
                            selectedCategoryId === cat.id 
                                ? 'bg-indigo-600 text-white shadow-indigo-500/20' 
                                : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200'
                        ]"
                    >
                        {{ cat.name_ar }}
                    </button>
                </div>

                <!-- Products Grid -->
                <div class="flex-1 overflow-y-auto pr-1">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <div 
                            v-for="p in filteredProducts" 
                            :key="p.id"
                            @click="addToCart(p)"
                            class="bg-white rounded-2xl p-3.5 border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-indigo-300 transition-all cursor-pointer flex flex-col justify-between group active:scale-98"
                        >
                            <div>
                                <div class="flex items-start justify-between gap-1 mb-2">
                                    <span class="text-3xs font-mono text-slate-400">
                                        {{ p.sku || 'SKU' }}
                                    </span>
                                    <span 
                                        v-if="p.type === 'product'"
                                        :class="[
                                            'px-1.5 py-0.5 rounded text-3xs font-bold',
                                            p.total_stock <= 5 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-600'
                                        ]"
                                    >
                                        {{ Number(p.total_stock) }} متوفر
                                    </span>
                                    <span v-else class="px-1.5 py-0.5 rounded text-3xs font-bold bg-amber-50 text-amber-700">
                                        خدمة
                                    </span>
                                </div>
                                <h3 class="text-xs font-bold text-slate-800 line-clamp-2 group-hover:text-indigo-600 transition-colors leading-relaxed">
                                    {{ p.name_ar }}
                                </h3>
                            </div>

                            <div class="pt-3 border-t border-slate-100 mt-2 flex items-center justify-between">
                                <span class="text-sm font-black text-indigo-600 font-mono">
                                    {{ formatCurrency(p.selling_price) }} <span class="text-3xs font-normal">﷼</span>
                                </span>
                                <span class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                                    <PlusIcon class="w-3.5 h-3.5 stroke-2" />
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Cart & Summary Drawer (30%) -->
            <div class="w-full md:w-96 bg-white border-s border-slate-200 flex flex-col h-full shadow-lg z-10 flex-shrink-0">
                <!-- Cart Header -->
                <div class="p-3.5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-bold text-slate-800">سلة المبيعات</h2>
                        <span class="px-2 py-0.5 rounded-full text-2xs font-bold bg-indigo-50 text-indigo-700">
                            {{ cart.length }} عناصر
                        </span>
                    </div>
                    <button 
                        v-if="cart.length > 0"
                        @click="clearCart"
                        class="text-2xs font-bold text-rose-500 hover:text-rose-700 transition-colors"
                    >
                        تفريغ السلة
                    </button>
                </div>

                <!-- Cart Items List -->
                <div class="flex-1 overflow-y-auto p-3 space-y-2 divide-y divide-slate-100">
                    <div 
                        v-for="(item, idx) in cart" 
                        :key="idx"
                        class="pt-2 first:pt-0 flex items-center justify-between gap-2"
                    >
                        <div class="flex-1 min-w-0">
                            <h4 class="text-xs font-bold text-slate-800 truncate">{{ item.item_name }}</h4>
                            <p class="text-2xs font-mono text-slate-400">
                                {{ formatCurrency(item.unit_price) }} ﷼ × {{ item.quantity }}
                            </p>
                        </div>

                        <!-- Quantity Control -->
                        <div class="flex items-center gap-1 bg-slate-100 rounded-lg p-0.5">
                            <button 
                                @click="updateQty(idx, -1)"
                                class="w-5 h-5 rounded bg-white text-slate-600 hover:text-rose-600 flex items-center justify-center font-bold text-xs shadow-2xs"
                            >
                                <MinusIcon class="w-3 h-3" />
                            </button>
                            <span class="w-6 text-center text-xs font-bold font-mono text-slate-800">
                                {{ item.quantity }}
                            </span>
                            <button 
                                @click="updateQty(idx, 1)"
                                class="w-5 h-5 rounded bg-white text-slate-600 hover:text-indigo-600 flex items-center justify-center font-bold text-xs shadow-2xs"
                            >
                                <PlusIcon class="w-3 h-3" />
                            </button>
                        </div>

                        <!-- Line Total -->
                        <div class="text-end min-w-[60px]">
                            <span class="text-xs font-bold text-slate-800 font-mono">
                                {{ formatCurrency(item.quantity * item.unit_price * (1 + $taxRate() / 100)) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="cart.length === 0" class="h-full flex flex-col items-center justify-center text-slate-400 py-16">
                        <CubeIcon class="w-12 h-12 text-slate-200 mb-2 stroke-1" />
                        <p class="text-xs font-medium">السلة فارغة</p>
                        <p class="text-2xs text-slate-300">انقر على أي منتج أو امسح الباركود لإضافته</p>
                    </div>
                </div>

                <!-- Cart Totals & Checkout -->
                <div class="p-4 border-t border-slate-200 bg-slate-50/70 space-y-3">
                    <div class="space-y-1.5 text-xs text-slate-600">
                        <div class="flex items-center justify-between">
                            <span>المجموع غير شامل الضريبة</span>
                            <span class="font-mono font-bold text-slate-800">{{ formatCurrency(cartSubtotal) }} ﷼</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ $taxLabel() }}</span>
                            <span class="font-mono font-bold text-slate-800">{{ formatCurrency(cartTax) }} ﷼</span>
                        </div>
                        <div class="border-t border-slate-200 pt-2 flex items-center justify-between text-slate-900 font-black">
                            <span class="text-sm">المجموع الكلي</span>
                            <span class="text-lg text-indigo-700 font-mono">{{ formatCurrency(cartTotal) }} ﷼</span>
                        </div>
                    </div>

                    <!-- Payment Action Buttons -->
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <!-- Cash Button -->
                        <button 
                            @click="openCashCheckout"
                            :disabled="cart.length === 0"
                            class="py-3 px-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-xs transition-colors disabled:opacity-40"
                        >
                            <BanknotesIcon class="w-4 h-4 stroke-2" />
                            <span>نقداً (Cash)</span>
                        </button>

                        <!-- Card Button -->
                        <button 
                            @click="submitOrder('card', cartTotal)"
                            :disabled="cart.length === 0"
                            class="py-3 px-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-xs transition-colors disabled:opacity-40"
                        >
                            <CreditCardIcon class="w-4 h-4 stroke-2" />
                            <span>مدى / بطاقة</span>
                        </button>
                        <button
                            @click="openSplitCheckout"
                            :disabled="cart.length === 0"
                            class="col-span-2 py-2 px-2 rounded-xl border border-slate-200 text-slate-700 font-bold text-xs disabled:opacity-40"
                        >
                            دفع مجزأ (نقد + بطاقة)
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Cash Payment Modal Dialog -->
        <div 
            v-if="isCashModalOpen"
            class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div class="bg-white rounded-3xl max-w-md w-full p-6 space-y-5 shadow-2xl border border-slate-100 animate-in zoom-in-95">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                        <BanknotesIcon class="w-5 h-5 text-emerald-600" />
                        <span>الدفع نقداً</span>
                    </h3>
                    <button @click="isCashModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Total Amount Due -->
                <div class="bg-indigo-50 rounded-2xl p-4 text-center">
                    <p class="text-2xs font-bold text-indigo-600 mb-1">المبلغ المطلوب سداده</p>
                    <p class="text-2xl font-black text-indigo-900 font-mono">{{ formatCurrency(cartTotal) }} <span class="text-xs font-normal">﷼</span></p>
                </div>

                <!-- Tender Amount Input -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">المبلغ المستلم من العميل</label>
                    <input 
                        v-model.number="tenderAmount"
                        type="number" 
                        step="1" 
                        class="w-full text-center text-xl font-black font-mono rounded-xl border border-slate-200 py-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                </div>

                <!-- Quick Tender Pills (+10, +50, +100, +500) -->
                <div class="grid grid-cols-4 gap-2">
                    <button 
                        v-for="amt in [10, 50, 100, 500]" 
                        :key="amt"
                        @click="addTender(amt)"
                        type="button"
                        class="py-2 rounded-xl bg-slate-100 hover:bg-indigo-50 text-slate-700 hover:text-indigo-700 font-bold text-xs transition-colors"
                    >
                        +{{ amt }}
                    </button>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <button 
                        type="button" 
                        @click="setExactTender"
                        class="text-xs font-bold text-indigo-600 hover:underline"
                    >
                        المبلغ بالتحديد
                    </button>

                    <!-- Change Due -->
                    <div class="text-end">
                        <span class="text-2xs text-slate-400">المتبقي للعميل: </span>
                        <span class="text-sm font-black font-mono text-emerald-600">{{ formatCurrency(changeDue) }} ﷼</span>
                    </div>
                </div>

                <!-- Confirm & Issue Receipt -->
                <button 
                    @click="submitOrder('cash', tenderAmount)"
                    :disabled="tenderAmount < cartTotal"
                    class="w-full py-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm shadow-xs transition-all disabled:opacity-50"
                >
                    تأكيد الدفع وإصدار الفاتورة
                </button>
            </div>
        </div>

        <!-- 4. Thermal 80mm ZATCA Receipt Modal -->
        <div 
            v-if="isReceiptModalOpen && latestOrder"
            class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
        >
            <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-4 shadow-2xl border border-slate-100 animate-in zoom-in-95">
                <!-- Actions Bar -->
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 print:hidden">
                    <span class="text-xs font-bold text-emerald-700 flex items-center gap-1">
                        <CheckCircleIcon class="w-4 h-4" />
                        <span>تم إصدار الفاتورة بنجاح</span>
                    </span>
                    <button @click="isReceiptModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Printable 80mm Thermal Receipt Sheet -->
                <div id="thermal-receipt" class="p-4 border border-dashed border-slate-300 rounded-xl text-center text-xs space-y-3 bg-white text-slate-900 font-mono">
                    <!-- Company Logo & Name -->
                    <div class="space-y-1">
                        <h2 class="font-black text-sm text-slate-900">{{ company?.name || $page.props.brand?.name }}</h2>
                        <p class="text-3xs text-slate-500">فاتورة ضريبية مبسطة | SIMPLIFIED TAX INVOICE</p>
                        <p class="text-3xs text-slate-600 font-bold">الرقم الضريبي: {{ company?.tax_number || '300000000000003' }}</p>
                    </div>

                    <div class="border-t border-slate-200 pt-2 text-3xs text-start space-y-0.5">
                        <p>رقم الفاتورة: <strong>{{ latestOrder.order_number }}</strong></p>
                        <p>التاريخ: {{ new Date(latestOrder.created_at).toLocaleString('ar-SA') }}</p>
                        <p>طريقة الدفع: {{ ({ cash: 'نقداً', card: 'بطاقة مدى', multiple: 'مجزأ (نقد + بطاقة)' })[latestOrder.payment_method] || latestOrder.payment_method }}</p>
                    </div>

                    <!-- Items Table -->
                    <div class="overflow-x-auto"><table class="w-full text-right text-3xs border-y border-slate-200 py-1 my-1">
                        <thead>
                            <tr class="font-bold border-b border-slate-100">
                                <th class="py-1">البند</th>
                                <th class="text-center">الكمية</th>
                                <th class="text-end">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in latestOrder.items" :key="item.id">
                                <td class="py-1">{{ item.item_name }}</td>
                                <td class="text-center font-bold">{{ Number(item.quantity) }}</td>
                                <td class="text-end font-bold">{{ formatCurrency(item.total) }} ﷼</td>
                            </tr>
                        </tbody>
                    </table></div>

                    <!-- Totals -->
                    <div class="space-y-1 text-3xs pt-1 border-t border-slate-200 text-start">
                        <div class="flex justify-between">
                            <span>المجموع الخاضع للضريبة:</span>
                            <span class="font-bold">{{ formatCurrency(latestOrder.subtotal) }} ﷼</span>
                        </div>
                        <div class="flex justify-between">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-bold">{{ formatCurrency(latestOrder.tax_amount) }} ﷼</span>
                        </div>
                        <div class="flex justify-between text-xs font-black border-t border-slate-300 pt-1">
                            <span>المجموع الكلي:</span>
                            <span>{{ formatCurrency(latestOrder.total) }} ﷼</span>
                        </div>
                    </div>

                    <!-- ZATCA QR Code -->
                    <div class="pt-2 flex flex-col items-center justify-center">
                        <img v-if="receiptQrUrl" :src="receiptQrUrl" class="w-28 h-28" alt="ZATCA QR" />
                        <span class="text-3xs text-slate-400 mt-1">رمز التحقق الإلكتروني ZATCA</span>
                    </div>

                    <p class="text-3xs text-slate-400 pt-1">شكراً لزيارتكم</p>
                </div>

                <!-- Print & New Order Actions -->
                <div class="flex items-center gap-2 print:hidden">
                    <button 
                        @click="printReceipt"
                        class="flex-1 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-xs"
                    >
                        <PrinterIcon class="w-4 h-4" />
                        <span>طباعة الإيصال</span>
                    </button>
                    <button 
                        @click="isReceiptModalOpen = false"
                        class="py-2.5 px-4 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs"
                    >
                        طلب جديد
                    </button>
                </div>
            </div>
        </div>

        <!-- Split tender modal -->
        <div v-if="isSplitModalOpen" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-4 shadow-2xl border border-slate-100 text-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-black text-slate-800">دفع مجزأ — الإجمالي {{ formatCurrency(cartTotal) }} ﷼</h3>
                    <button @click="isSplitModalOpen = false" class="text-slate-400 hover:text-slate-600"><XMarkIcon class="w-4 h-4" /></button>
                </div>
                <label class="block font-bold text-slate-700">بطاقة / مدى</label>
                <input v-model.number="splitCard" type="number" step="0.01" min="0" :max="cartTotal" @input="splitCash = Math.max(0, Math.round((cartTotal - (Number(splitCard) || 0)) * 100) / 100)" class="w-full text-center text-lg font-mono font-bold rounded-xl border border-slate-200 py-2" />
                <label class="block font-bold text-slate-700">نقداً</label>
                <input v-model.number="splitCash" type="number" step="0.01" min="0" class="w-full text-center text-lg font-mono font-bold rounded-xl border border-slate-200 py-2" />
                <p class="text-slate-500">الباقي للعميل: <strong class="font-mono">{{ formatCurrency(Math.max(0, (Number(splitCash) || 0) + (Number(splitCard) || 0) - cartTotal)) }}</strong> ﷼</p>
                <button @click="submitSplit" :disabled="(Number(splitCash) || 0) + (Number(splitCard) || 0) < cartTotal - 0.001 || (Number(splitCard) || 0) > cartTotal" class="w-full py-2.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40">إتمام الدفع</button>
            </div>
        </div>

        <!-- 5. Close Session Modal -->
        <div 
            v-if="isCloseSessionModalOpen"
            class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-4 shadow-2xl border border-slate-100">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-black text-slate-800">إغلاق جلسة الكاشير</h3>
                    <button @click="isCloseSessionModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-4 h-4" />
                    </button>
                </div>

                <form @submit.prevent="submitCloseSession" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">المبلغ الفعلي الموجود في الدرج ({{ $cur() }})</label>
                        <input 
                            v-model.number="closeSessionForm.closing_cash"
                            type="number" 
                            step="0.01" 
                            required
                            class="w-full text-center text-lg font-mono font-bold rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500"
                        />
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">توريد النقدية إلى (اختياري)</label>
                        <select v-model="closeSessionForm.deposit_bank_account_id" class="w-full rounded-xl border border-slate-200 p-2 text-xs">
                            <option value="">تبقى في درج نقطة البيع</option>
                            <option v-for="b in boxes" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <p v-if="closeSessionForm.errors.deposit_bank_account_id" class="text-rose-600 mt-1">{{ closeSessionForm.errors.deposit_bank_account_id }}</p>
                        <p class="text-3xs text-slate-500 mt-1">أي عجز أو زيادة عن المتوقع يُقيَّد تلقائياً في حساب «عجز وزيادة الصندوق».</p>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">ملاحظات الإغلاق</label>
                        <textarea 
                            v-model="closeSessionForm.notes"
                            rows="2"
                            class="w-full rounded-xl border border-slate-200 p-2 text-xs"
                        ></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <button 
                            type="button" 
                            @click="isCloseSessionModalOpen = false"
                            class="flex-1 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="closeSessionForm.processing"
                            class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-xs disabled:opacity-50"
                        >
                            إغلاق وتسوية
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #thermal-receipt, #thermal-receipt * {
        visibility: visible;
    }
    #thermal-receipt {
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }
}
</style>
