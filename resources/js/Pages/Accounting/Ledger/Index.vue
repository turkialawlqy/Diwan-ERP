<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BookOpenIcon,
    PrinterIcon,
    FunnelIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    selectedAccount: Object,
    lines: Array,
    summary: Object,
    filters: Object,
});

const selectedAccountId = ref(props.filters?.account_id || props.accounts?.[0]?.id || '');
const startDate = ref(props.filters?.start_date || '');
const endDate = ref(props.filters?.end_date || '');

const handleFilter = () => {
    router.get(route('accounting.ledger.index'), {
        account_id: selectedAccountId.value || undefined,
        start_date: startDate.value || undefined,
        end_date: endDate.value || undefined,
    }, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const printLedger = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <Head title="دفتر الأستاذ العام - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
                <div class="flex items-center gap-2">
                    <button 
                        @click="printLedger"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة كشف الحساب</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleFilter"
                        class="p-2 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-2xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span>المحاسبة</span>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-semibold">دفتر الأستاذ العام</span>
                    </nav>
                </div>
            </div>

            <!-- Filter Controls (Account + Date Range) -->
            <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs no-print">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div class="sm:col-span-2">
                        <label class="block text-3xs font-bold text-slate-400 mb-1">اختر الحساب المحاسبي من الدليل</label>
                        <select 
                            v-model="selectedAccountId"
                            @change="handleFilter"
                            class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-bold focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.nature === 'debit' ? 'مدين' : 'دائن' }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-3xs font-bold text-slate-400 mb-1">من تاريخ</label>
                        <input 
                            v-model="startDate" 
                            type="date" 
                            class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500" 
                        />
                    </div>

                    <div>
                        <label class="block text-3xs font-bold text-slate-400 mb-1">إلى تاريخ</label>
                        <div class="flex items-center gap-2">
                            <input 
                                v-model="endDate" 
                                type="date" 
                                class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                            <button 
                                @click="handleFilter"
                                class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1 flex-shrink-0"
                            >
                                <FunnelIcon class="w-3.5 h-3.5" />
                                <span>تصفية</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Summary Card -->
            <div v-if="selectedAccount" class="bg-white p-5 rounded-2xl border border-slate-100 shadow-2xs space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold font-mono">
                            {{ selectedAccount.code }}
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900">{{ selectedAccount.name_ar }}</h2>
                            <p class="text-3xs text-slate-400">
                                طبيعة الحساب: {{ selectedAccount.nature === 'debit' ? 'مدين (طبيعة مدينة)' : 'دائن (طبيعة دائنة)' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-6">
                        <div class="text-end">
                            <span class="text-3xs font-bold text-slate-400 block">إجمالي المدين</span>
                            <span class="text-xs sm:text-sm font-mono font-bold text-slate-800">
                                {{ formatCurrency(summary?.total_debit) }} ﷼
                            </span>
                        </div>
                        <div class="text-end">
                            <span class="text-3xs font-bold text-slate-400 block">إجمالي الدائن</span>
                            <span class="text-xs sm:text-sm font-mono font-bold text-slate-800">
                                {{ formatCurrency(summary?.total_credit) }} ﷼
                            </span>
                        </div>
                        <div class="text-end ps-4 border-s border-slate-100">
                            <span class="text-3xs font-bold text-slate-400 block">الرصيد النهائي</span>
                            <span class="text-sm sm:text-base font-mono font-black text-indigo-600">
                                {{ formatCurrency(summary?.net_balance) }} ﷼
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Ledger Transactions Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-2.5 px-3 text-start">التاريخ</th>
                                <th class="py-2.5 px-3 text-start">رقم القيد</th>
                                <th class="py-2.5 px-3 text-start">المرجع</th>
                                <th class="py-2.5 px-3 text-start">البيان والشرح</th>
                                <th class="py-2.5 px-3 text-end">مدين (Debit)</th>
                                <th class="py-2.5 px-3 text-end">دائن (Credit)</th>
                                <th class="py-2.5 px-3 text-end">الرصيد التراكمي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="line in lines" :key="line.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-3 font-mono text-3xs text-slate-600">{{ line.date }}</td>
                                <td class="py-3 px-3 font-mono font-bold text-indigo-600">{{ line.entry_number }}</td>
                                <td class="py-3 px-3 font-mono text-3xs text-slate-500">{{ line.reference || '-' }}</td>
                                <td class="py-3 px-3 font-medium text-slate-900 max-w-sm truncate">{{ line.description }}</td>
                                <td class="py-3 px-3 font-mono font-bold text-end">
                                    {{ line.debit > 0 ? formatCurrency(line.debit) : '-' }}
                                </td>
                                <td class="py-3 px-3 font-mono font-bold text-end">
                                    {{ line.credit > 0 ? formatCurrency(line.credit) : '-' }}
                                </td>
                                <td class="py-3 px-3 font-mono font-black text-end text-slate-900">
                                    {{ formatCurrency(line.running_balance) }} <span class="text-3xs font-normal">﷼</span>
                                </td>
                            </tr>
                            <tr v-if="!lines || lines.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    لا توجد حركات مقيدة على هذا الحساب في الفترة المحددة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    .no-print {
        display: none !important;
    }
}
</style>
