<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { taxRate } from '@/utils/tax';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    BanknotesIcon,
    XMarkIcon,
    TrashIcon,
    PrinterIcon,
    PencilSquareIcon,
    FunnelIcon,
    ArrowTrendingDownIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    expenses: Object,
    stats: Object,
    expenseAccounts: Array,
    bankAccounts: Array,
    filters: Object,
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedCategory = ref(props.filters?.category || '');

const deleteExpense = (id, number) => {
    if (confirm(`هل أنت متأكد من حذف سند المصروف رقم "${number}"؟`)) {
        router.delete(route('finance.expenses.destroy', id));
    }
};

const handleSearch = () => {
    router.get(route('finance.expenses.index'), {
        search: search.value || undefined,
        category: selectedCategory.value || undefined,
    }, { preserveState: true, replace: true });
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
        <Head title="سندات المصروفات" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader year-scoped title="المصروفات" :crumbs="[{ label: 'المالية' }, { label: 'المصروفات' }]">
<template #actions>
<button 
                        @click="handleSearch"
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button 
                        @click.stop="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-all duration-150 shadow-xs border',
                            isFilterOpen || search || selectedCategory
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-slate-100/90 border-slate-200 text-slate-700 hover:bg-slate-200/80'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-indigo-600" />
                        <span>البحث والتصفية</span>
                    </button>

                    <Link v-if="$can('finance.expenses.create')" :href="route('finance.expenses.create')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>تسجيل مصروف جديد</span>
                    </Link>
<ImportButton v-if="$can('finance.expenses.create')" type="expenses" title="استيراد المصروفات من Excel" hint="تُرحَّل بتواريخها دون أي حركة مخزون." />
</template>
</PageHeader>

            <!-- Collapsible Search & Filter Panel -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div v-if="isFilterOpen" class="bg-white rounded-2xl border border-indigo-100/80 p-5 shadow-sm space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">بحث برقم السند، البيان، المستفيد</label>
                            <input 
                                v-model="search"
                                @keyup.enter="handleSearch"
                                type="text" 
                                placeholder="اكتب للبحث..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">التصنيف</label>
                            <select 
                                v-model="selectedCategory"
                                @change="handleSearch"
                                class="w-full text-xs rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">جميع التصنيفات</option>
                                <option v-for="cat in stats?.categories || []" :key="cat" :value="cat">{{ cat }}</option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button 
                                @click="handleSearch"
                                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors shadow-xs"
                            >
                                تطبيق التصفية
                            </button>
                            <button 
                                @click="search = ''; selectedCategory = ''; handleSearch()"
                                class="px-4 py-2.5 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                            >
                                إعادة ضبط
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي المصروفات</p>
                    <p class="text-xl sm:text-2xl font-black text-rose-600">
                        {{ formatCurrency(stats?.total_expenses) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span>
                    </p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $taxLabel() }}</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">
                        {{ formatCurrency(stats?.total_tax) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span>
                    </p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">عدد سندات المصروف</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.count || 0 }}</p>
                </div>
            </div>

            <!-- Expenses Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">رقم السند</th>
                                <th class="py-4 px-6 text-start">البيان / الوصف</th>
                                <th class="py-4 px-6 text-start">التصنيف</th>
                                <th class="py-4 px-6 text-start">المدفوع له / المستفيد</th>
                                <th class="py-4 px-6 text-start">الحساب / الخزينة</th>
                                <th class="py-4 px-6 text-start">التاريخ</th>
                                <th class="py-4 px-6 text-end">المبلغ</th>
                                <th class="py-4 px-6 text-end">الضريبة {{ $taxRate() }}%</th>
                                <th class="py-4 px-6 text-end">الإجمالي</th>
                                <th class="py-4 px-6 text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="exp in expenses.data" :key="exp.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6 font-mono font-bold text-rose-600">
                                    {{ exp.expense_number }}
                                </td>
                                <td class="py-4 px-6 font-medium text-slate-900 max-w-xs truncate">
                                    {{ exp.title }}
                                </td>
                                <td class="py-4 px-6">
                                    <span class="px-2.5 py-0.5 rounded-full text-2xs font-bold bg-slate-100 text-slate-700">
                                        {{ exp.category }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ exp.vendor_name || '-' }}
                                </td>
                                <td class="py-4 px-6 text-slate-600 text-xs">
                                    {{ exp.bank_account?.name || 'خزينة' }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-500">
                                    {{ exp.expense_date ? exp.expense_date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(exp.amount) }}
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-500 text-end">
                                    {{ formatCurrency(exp.tax_amount) }}
                                </td>
                                <td class="py-4 px-6 font-mono font-black text-rose-600 text-end">
                                    {{ formatCurrency(exp.total_amount) }} <span class="text-3xs font-normal">﷼</span>
                                </td>
                                <td class="py-4 px-6 text-center whitespace-nowrap">
                                    <a :href="route('documents.pdf', { type: 'expense', id: exp.id })" target="_blank" class="p-1.5 text-slate-400 hover:text-indigo-600 rounded-lg hover:bg-indigo-50 transition inline-block" title="طباعة السند" data-row-print><PrinterIcon class="w-4 h-4" /></a>
                                    <Link v-if="$can('finance.expenses.edit')" :href="route('finance.expenses.edit', exp.id)" class="p-1.5 text-slate-400 hover:text-indigo-600 rounded-lg hover:bg-indigo-50 transition inline-block" title="تعديل" data-row-edit><PencilSquareIcon class="w-4 h-4" /></Link>
                                    <button 
                                        @click="deleteExpense(exp.id, exp.expense_number)"
                                        class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                        title="حذف"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!expenses.data || expenses.data.length === 0">
                                <td colspan="10" class="py-12 text-center text-slate-400">
                                    لا توجد سندات مصروفات مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="expenses?.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ expenses.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="expenses.per_page"
                            @change="router.get(route('finance.expenses.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ expenses.current_page }} من {{ expenses.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="expenses.prev_page_url" 
                                :href="expenses.prev_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                            <span class="w-7 h-7 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-bold flex items-center justify-center">
                                {{ expenses.current_page }}
                            </span>
                            <Link 
                                v-if="expenses.next_page_url" 
                                :href="expenses.next_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
