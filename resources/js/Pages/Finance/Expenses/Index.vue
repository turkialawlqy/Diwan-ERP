<script setup>
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
const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedCategory = ref(props.filters?.category || '');

const form = useForm({
    title: '',
    category: 'مصروفات عامة',
    account_id: props.expenseAccounts?.[0]?.id || '',
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    amount: '',
    tax_amount: '',
    taxable: false,
    vendor_tax_number: '',
    expense_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    reference_number: '',
    vendor_name: '',
    notes: '',
});

const onAmountChange = () => {
    if (form.amount && form.taxable) {
        form.tax_amount = (parseFloat(form.amount) * taxRate() / 100).toFixed(2);
    } else {
        form.tax_amount = '0.00';
    }
};

const openAddModal = () => {
    form.reset();
    form.account_id = props.expenseAccounts?.[0]?.id || '';
    form.bank_account_id = props.bankAccounts?.[0]?.id || '';
    form.expense_date = new Date().toISOString().split('T')[0];
    isAddModalOpen.value = true;
};

const submitExpense = () => {
    form.post(route('finance.expenses.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

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
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs Ribbon -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-slate-500">المالية</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المصروفات</span>
                    </div>

                    <!-- Refresh Button -->
                    <button 
                        @click="handleSearch"
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Primary Action Buttons -->
                <div class="flex items-center gap-2">
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

                    <button 
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>تسجيل مصروف جديد</span>
                    </button>
                </div>
            </div>

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
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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
                                <td class="py-4 px-6 text-center">
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

            <!-- Add Expense Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-rose-600" />
                            <span>تسجيل سند مصروف جديد</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitExpense" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">بيان / عنوان المصروف <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.title" 
                                type="text" 
                                required 
                                placeholder="مثال: سداد فاتورة الكهرباء لشهر سبتمبر"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">التصنيف <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.category" 
                                    type="text" 
                                    required 
                                    placeholder="إيجار، رواتب، فواتير..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">تاريخ الصرف <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.expense_date" 
                                    type="date" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">المبلغ (غير شامل الضريبة) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.amount" 
                                    @input="onAmountChange"
                                    type="number" 
                                    step="0.01" 
                                    required 
                                    placeholder="0.00"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-rose-500 focus:border-rose-500 text-end" 
                                />
                            </div>
                            <div>
                                <label class="flex items-center gap-2 font-medium text-slate-700 mb-1"><input v-model="form.taxable" type="checkbox" @change="onAmountChange" class="rounded border-slate-300" /> خاضع لـ{{ $taxLabel() }}</label>
                                <input 
                                    v-model="form.tax_amount" 
                                    :disabled="!form.taxable"
                                    type="number" 
                                    step="0.01" 
                                    placeholder="0.00"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-rose-500 focus:border-rose-500 text-end" 
                                />
                            </div>
                        </div>

                        <div class="p-3 bg-rose-50/50 rounded-xl flex items-center justify-between text-xs border border-rose-100">
                            <span class="font-bold text-rose-900">المجموع الإجمالي شامل الضريبة:</span>
                            <span class="font-mono font-black text-sm text-rose-600">
                                {{ formatCurrency((parseFloat(form.amount || 0) + parseFloat(form.tax_amount || 0))) }} ﷼
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">مدفوع من (الخزينة أو البنك)</label>
                                <select 
                                    v-model="form.bank_account_id" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                >
                                    <option v-for="bank in bankAccounts" :key="bank.id" :value="bank.id">
                                        {{ bank.name }} (الرصيد: {{ formatCurrency(bank.current_balance) }} ﷼)
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">حساب المصروف في الدليل</label>
                                <select 
                                    v-model="form.account_id" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                >
                                    <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">
                                        {{ acc.code }} - {{ acc.name_ar }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الجهة المستفيدة / المدفوع له</label>
                                <input 
                                    v-model="form.vendor_name" 
                                    type="text" 
                                    placeholder="اسم الشركة أو الشخص"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500" 
                                />
                                <template v-if="form.taxable">
                                    <label class="block font-medium text-slate-700 mt-2 mb-1" for="vendor-vat">الرقم الضريبي للمورد</label>
                                    <input id="vendor-vat" v-model="form.vendor_tax_number" dir="ltr" maxlength="15" placeholder="3xxxxxxxxxxxxx3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" />
                                    <p v-if="form.errors.vendor_tax_number" class="text-rose-600 text-3xs mt-1">{{ form.errors.vendor_tax_number }}</p>
                                </template>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">طريقة الدفع</label>
                                <select 
                                    v-model="form.payment_method" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                                >
                                    <option value="bank_transfer">تحويل بنكي</option>
                                    <option value="cash">نقداً</option>
                                    <option value="card">بطاقة مدى / ائتمان</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">ملاحظات أو تفاصيل</label>
                            <textarea 
                                v-model="form.notes" 
                                rows="2" 
                                placeholder="أي تفاصيل إضافية عن السند..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-rose-500 focus:border-rose-500"
                            ></textarea>
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
                                class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-bold shadow-xs hover:shadow-rose-500/20 disabled:opacity-50"
                            >
                                حفظ السند
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
