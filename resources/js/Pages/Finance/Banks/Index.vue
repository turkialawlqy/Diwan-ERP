<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BuildingLibraryIcon,
    BanknotesIcon,
    XMarkIcon,
    TrashIcon,
    CreditCardIcon,
    WalletIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    stats: Object,
    chartAccounts: Array,
});

const isAddModalOpen = ref(false);

const form = useForm({
    name: '',
    type: 'bank',
    bank_name: '',
    account_number: '',
    iban: '',
    currency: usePage().props.tenant?.currency || 'SAR',
    opening_balance: '0.00',
    account_id: props.chartAccounts?.[0]?.id || '',
});

const openAddModal = () => {
    form.reset();
    form.account_id = props.chartAccounts?.[0]?.id || '';
    isAddModalOpen.value = true;
};

const submitAccount = () => {
    form.post(route('finance.banks.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const deleteAccount = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف الحساب "${name}"؟`)) {
        router.delete(route('finance.banks.destroy', id));
    }
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
        <Head title="الخزائن والحسابات البنكية" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('finance.banks.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المالية</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الخزائن والبنوك</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة حساب بنكي / خزينة</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">إجمالي السيولة النقدية</span>
                        <h3 class="text-lg font-black font-mono text-indigo-600">
                            {{ formatCurrency(stats?.total_liquidity) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <WalletIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">أرصدة الحسابات البنكية ({{ stats?.bank_count || 0 }})</span>
                        <h3 class="text-lg font-black font-mono text-sky-600">
                            {{ formatCurrency(stats?.total_bank_balance) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                        <BuildingLibraryIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">أرصدة الخزائن النقدية ({{ stats?.cash_count || 0 }})</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_cash_balance) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Accounts Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="acc in accounts" 
                    :key="acc.id"
                    class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs hover:shadow-md transition-shadow relative overflow-hidden flex flex-col justify-between"
                >
                    <div class="space-y-3">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div 
                                    :class="[
                                        acc.type === 'bank' ? 'bg-sky-50 text-sky-600' : 'bg-emerald-50 text-emerald-600',
                                        'w-10 h-10 rounded-xl flex items-center justify-center'
                                    ]"
                                >
                                    <BuildingLibraryIcon v-if="acc.type === 'bank'" class="w-5 h-5" />
                                    <BanknotesIcon v-else class="w-5 h-5" />
                                </div>
                                <div>
                                    <h4 class="font-bold text-slate-900 text-xs sm:text-sm">{{ acc.name }}</h4>
                                    <span class="text-3xs font-semibold text-slate-400">
                                        {{ acc.type === 'bank' ? 'حساب بنكي جاري' : 'خزينة عهدة نقدية' }}
                                    </span>
                                </div>
                            </div>

                            <button 
                                @click="deleteAccount(acc.id, acc.name)"
                                class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                title="حذف"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>

                        <!-- Bank Details -->
                        <div v-if="acc.type === 'bank'" class="space-y-1.5 p-3 bg-slate-50 rounded-xl text-3xs font-mono">
                            <div class="flex items-center justify-between text-slate-500">
                                <span>البنك:</span>
                                <span class="font-sans font-bold text-slate-800">{{ acc.bank_name || '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between text-slate-500">
                                <span>رقم الحساب:</span>
                                <span class="font-bold text-slate-800">{{ acc.account_number || '-' }}</span>
                            </div>
                            <div v-if="acc.iban" class="flex flex-col gap-0.5 text-slate-500 pt-1 border-t border-slate-200/60">
                                <span>IBAN:</span>
                                <span class="font-bold text-slate-800 break-all select-all">{{ acc.iban }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Current Balance Ribbon -->
                    <div class="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-3xs font-bold text-slate-400">الرصيد الفعلي الحالي:</span>
                        <span class="font-mono font-black text-base text-slate-900">
                            {{ formatCurrency(acc.current_balance) }} <span class="text-xs font-normal">﷼</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Add Bank Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BuildingLibraryIcon class="w-5 h-5 text-indigo-600" />
                            <span>إضافة حساب بنكي أو خزينة جديدة</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitAccount" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اسم الحساب / الخزينة <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required 
                                placeholder="مثال: حساب مصرف الراجحي الرئيسي أو خزينة الرياض"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">النوع <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="bank">حساب بنكي</option>
                                    <option value="cash">خزينة نقدية (درج كاش)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الرصيد الافتتاحي (﷼) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.opening_balance" 
                                    type="number" 
                                    step="0.01" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500 text-end" 
                                />
                            </div>
                        </div>

                        <!-- If Bank -->
                        <div v-if="form.type === 'bank'" class="space-y-3 p-3 bg-sky-50/50 rounded-xl border border-sky-100">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">اسم البنك</label>
                                <input 
                                    v-model="form.bank_name" 
                                    type="text" 
                                    placeholder="مثال: مصرف الراجحي، بنك الرياض، الأهلي..."
                                    class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">رقم الحساب</label>
                                    <input 
                                        v-model="form.account_number" 
                                        type="text" 
                                        placeholder="رقم الحساب المصرفي"
                                        class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">رقم الآيبان (IBAN)</label>
                                    <input 
                                        v-model="form.iban" 
                                        type="text" 
                                        placeholder="SA......................"
                                        class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                    />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">ربط مع حساب بدليل الحسابات (اختياري)</label>
                            <select 
                                v-model="form.account_id" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">بدون ربط مباشر</option>
                                <option v-for="acc in chartAccounts" :key="acc.id" :value="acc.id">
                                    {{ acc.code }} - {{ acc.name_ar }}
                                </option>
                            </select>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                            <button 
                                type="button" 
                                @click="isAddModalOpen = false"
                                class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium"
                            >
                                إلغاء
                            </button>
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                إضافة الحساب
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
