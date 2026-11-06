<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
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
    PencilSquareIcon,
    CreditCardIcon,
    WalletIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    stats: Object,
    chartAccounts: Array,
});

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
            <PageHeader title="الخزائن والبنوك" :crumbs="[{ label: 'المالية' }, { label: 'الخزائن والبنوك' }]">
<template #actions>
<Link 
                        :href="route('finance.banks.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
<Link v-if="$can('finance.banks.create')" :href="route('finance.banks.create')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة حساب بنكي / خزينة</span>
                    </Link>
                <ImportButton v-if="$can('finance.banks.create')" type="bank_accounts" title="استيراد الحسابات البنكية من Excel" />
</template>
</PageHeader>

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

                            <span class="flex items-center">
                            <Link v-if="$can('finance.banks.edit')" :href="route('finance.banks.edit', acc.id)" class="p-1.5 text-slate-400 hover:text-indigo-600 rounded-lg hover:bg-indigo-50 transition" title="تعديل" data-row-edit><PencilSquareIcon class="w-4 h-4" /></Link>
                            <button 
                                @click="deleteAccount(acc.id, acc.name)"
                                class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                title="حذف"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                            </span>
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
        </div>
    </AppLayout>
</template>
