<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    PrinterIcon,
    ArrowLeftIcon,
    ArrowPathIcon,
    DocumentTextIcon,
    UserIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    client: Object,
    transactions: Array,
    openingBalance: Number,
    closingBalance: Number,
});

const totalDebits = computed(() => {
    return (props.transactions || []).reduce((sum, tx) => sum + (Number(tx.debit) || 0), 0);
});

const totalCredits = computed(() => {
    return (props.transactions || []).reduce((sum, tx) => sum + (Number(tx.credit) || 0), 0);
});

const formatCurrency = (val) => {
    if (val === null || val === undefined) return '0.00 ﷼';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ﷼';
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    try {
        return new Date(dateStr).toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return dateStr;
    }
};

const printStatement = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <Head :title="`كشف حساب - ${client.name}`" />

        <div class="space-y-5 w-full" dir="rtl">
            <!-- Breadcrumbs Ribbon & Print Actions (Hidden on Print) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 print:hidden">
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <Link :href="route('sales.clients.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">
                            العملاء
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">كشف حساب: {{ client.name }}</span>
                    </div>

                    <ShareDocument type="statement" :id="client.id" />

                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('sales.clients.index')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50 text-xs font-semibold flex items-center gap-1.5 transition-colors shadow-xs"
                    >
                        <ArrowLeftIcon class="w-4 h-4 rtl:rotate-180" />
                        <span>العودة لقائمة العملاء</span>
                    </Link>

                    <button
                        type="button"
                        @click="printStatement"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 flex items-center gap-2 transition-all"
                    >
                        <PrinterIcon class="w-4 h-4" />
                        <span>طباعة كشف الحساب</span>
                    </button>
                </div>
            </div>

            <!-- KPI Mini Summary Bar (Hidden on Print) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 print:hidden">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">الرصيد الافتتاحي</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800 font-mono">{{ formatCurrency(openingBalance) }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي الفواتير (مدين)</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800 font-mono">{{ formatCurrency(totalDebits) }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي السدادات (دائن)</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600 font-mono">{{ formatCurrency(totalCredits) }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">صافي الرصيد المستحق</p>
                    <p 
                        class="text-xl sm:text-2xl font-black font-mono"
                        :class="[closingBalance > 0 ? 'text-rose-600' : (closingBalance < 0 ? 'text-emerald-600' : 'text-slate-700')]"
                    >
                        {{ formatCurrency(closingBalance) }}
                    </p>
                </div>
            </div>

            <!-- Statement Document Card -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs p-6 sm:p-8 space-y-6 print:border-none print:shadow-none print:p-0">
                <!-- Header: Client & Statement Summary -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 pb-6 border-b border-slate-100">
                    <div class="space-y-1">
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl sm:text-2xl font-black text-slate-900">كشف حساب عميل</h1>
                            <span class="text-xs px-2.5 py-1 rounded-full bg-indigo-50 border border-indigo-200 text-indigo-700 font-bold font-mono">
                                #{{ client.code || ('1-' + String(client.id).padStart(6, '0')) }}
                            </span>
                        </div>
                        <p class="text-sm font-bold text-slate-700">{{ client.name }}</p>
                        <p v-if="client.tax_number" class="text-xs text-slate-500 font-mono">
                            الرقم الضريبي: {{ client.tax_number }}
                        </p>
                        <p v-if="client.phone" class="text-xs text-slate-400 font-mono">
                            الهاتف: {{ client.phone }}
                        </p>
                    </div>

                    <!-- Balance Summary Badge -->
                    <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 flex items-center gap-6">
                        <div>
                            <span class="block text-2xs text-slate-500 font-bold mb-1">الرصيد الختامي المستحق</span>
                            <span
                                class="text-2xl font-black font-mono"
                                :class="[closingBalance > 0 ? 'text-rose-600' : (closingBalance < 0 ? 'text-emerald-600' : 'text-slate-700')]"
                            >
                                {{ formatCurrency(closingBalance) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="border border-slate-200/80 rounded-2xl overflow-hidden shadow-2xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-right border-collapse text-xs sm:text-sm">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                    <th class="py-4 px-6">التاريخ</th>
                                    <th class="py-4 px-6">نوع الحركة</th>
                                    <th class="py-4 px-6">رقم المرجع</th>
                                    <th class="py-4 px-6">البيان / ملاحظات</th>
                                    <th class="py-4 px-6 text-center">مدين (﷼)</th>
                                    <th class="py-4 px-6 text-center">دائن (﷼)</th>
                                    <th class="py-4 px-6 text-center">الرصيد (﷼)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="(tx, idx) in transactions"
                                    :key="idx"
                                    class="hover:bg-slate-50/70 transition-colors"
                                >
                                    <td class="py-4 px-6 text-slate-600 font-mono whitespace-nowrap">
                                        {{ formatDate(tx.date) }}
                                    </td>
                                    <td class="py-4 px-6 font-semibold text-slate-800">
                                        {{ tx.type_ar }}
                                    </td>
                                    <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                        {{ tx.reference }}
                                    </td>
                                    <td class="py-4 px-6 text-slate-600 max-w-xs truncate">
                                        {{ tx.notes || '-' }}
                                    </td>
                                    <td class="py-4 px-6 font-mono text-center font-bold text-slate-800">
                                        {{ tx.debit > 0 ? formatCurrency(tx.debit) : '-' }}
                                    </td>
                                    <td class="py-4 px-6 font-mono text-center font-bold text-emerald-600">
                                        {{ tx.credit > 0 ? formatCurrency(tx.credit) : '-' }}
                                    </td>
                                    <td class="py-4 px-6 font-mono text-center font-black text-slate-900 bg-slate-50/50">
                                        {{ formatCurrency(tx.balance) }}
                                    </td>
                                </tr>

                                <tr v-if="!transactions || transactions.length === 0">
                                    <td colspan="7" class="py-12 text-center text-slate-400">
                                        لا توجد أية حركات مسجلة للعميل خلال هذه الفترة
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Footer Summary Totals -->
                <div class="p-4 rounded-xl bg-slate-50/70 border border-slate-200/80 flex flex-wrap items-center justify-between gap-4 text-xs font-bold text-slate-700">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500">الرصيد الافتتاحي:</span>
                        <span class="font-mono text-slate-900">{{ formatCurrency(openingBalance) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500">إجمالي مدين:</span>
                        <span class="font-mono text-slate-900">{{ formatCurrency(totalDebits) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500">إجمالي دائن:</span>
                        <span class="font-mono text-emerald-600">{{ formatCurrency(totalCredits) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500">صافي الرصيد الحالي:</span>
                        <span class="font-mono text-base font-black text-indigo-600">{{ formatCurrency(closingBalance) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
