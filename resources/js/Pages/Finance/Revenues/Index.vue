<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BanknotesIcon,
    XMarkIcon,
    TrashIcon,
    ArrowTrendingUpIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    revenues: Object,
    stats: Object,
    revenueAccounts: Array,
    bankAccounts: Array,
    filters: Object,
});

const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedCategory = ref(props.filters?.category || '');

const form = useForm({
    title: '',
    category: 'إيرادات عامة',
    account_id: props.revenueAccounts?.[0]?.id || '',
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    amount: '',
    tax_amount: '',
    revenue_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    reference_number: '',
    customer_name: '',
    notes: '',
});

const onAmountChange = () => {
    if (form.amount) {
        form.tax_amount = (parseFloat(form.amount) * 0.15).toFixed(2);
    } else {
        form.tax_amount = '0.00';
    }
};

const openAddModal = () => {
    form.reset();
    form.account_id = props.revenueAccounts?.[0]?.id || '';
    form.bank_account_id = props.bankAccounts?.[0]?.id || '';
    form.revenue_date = new Date().toISOString().split('T')[0];
    isAddModalOpen.value = true;
};

const submitRevenue = () => {
    form.post(route('finance.revenues.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const deleteRevenue = (id, number) => {
    if (confirm(`هل أنت متأكد من حذف سند الإيراد رقم "${number}"؟`)) {
        router.delete(route('finance.revenues.destroy', id));
    }
};

const handleSearch = () => {
    router.get(route('finance.revenues.index'), {
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
        <Head title="سندات الإيرادات الإضافية - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-emerald-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>تسجيل إيراد جديد</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleSearch"
                        class="p-2 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-emerald-600 hover:bg-slate-50 transition-colors shadow-2xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                        <Link :href="route('dashboard')" class="hover:text-emerald-600 transition-colors">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span>المالية</span>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-semibold">الإيرادات</span>
                    </nav>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي الإيرادات الإضافية</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_revenues) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <ArrowTrendingUpIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">ضريبة القيمة المضافة 15%</span>
                        <h3 class="text-lg font-black font-mono text-indigo-600">
                            {{ formatCurrency(stats?.total_tax) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">عدد سندات الإيراد</span>
                        <h3 class="text-lg font-black text-slate-900">{{ stats?.count || 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Search and Filter Bar -->
            <div class="bg-white p-3 rounded-2xl border border-slate-100 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                    <input 
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text" 
                        placeholder="بحث برقم السند، البيان، العميل..."
                        class="w-full ps-9 pe-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-emerald-500 focus:border-emerald-500 transition-colors"
                    />
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <select 
                        v-model="selectedCategory"
                        @change="handleSearch"
                        class="w-full sm:w-48 py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 focus:ring-emerald-500 focus:border-emerald-500"
                    >
                        <option value="">جميع التصنيفات</option>
                        <option v-for="cat in stats.categories" :key="cat" :value="cat">{{ cat }}</option>
                    </select>
                </div>
            </div>

            <!-- Revenues Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-3 px-4 text-start">رقم السند</th>
                                <th class="py-3 px-4 text-start">البيان / الوصف</th>
                                <th class="py-3 px-4 text-start">التصنيف</th>
                                <th class="py-3 px-4 text-start">العميل / الجهة الدافعة</th>
                                <th class="py-3 px-4 text-start">الحساب المودع فيه</th>
                                <th class="py-3 px-4 text-start">التاريخ</th>
                                <th class="py-3 px-4 text-end">المبلغ</th>
                                <th class="py-3 px-4 text-end">الضريبة 15%</th>
                                <th class="py-3 px-4 text-end">الإجمالي</th>
                                <th class="py-3 px-4 text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="rev in revenues.data" :key="rev.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-3.5 px-4 font-mono font-bold text-emerald-600">
                                    {{ rev.revenue_number }}
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-900 max-w-xs truncate">
                                    {{ rev.title }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="px-2.5 py-0.5 rounded-full text-3xs font-bold bg-slate-100 text-slate-700">
                                        {{ rev.category }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    {{ rev.customer_name || '-' }}
                                </td>
                                <td class="py-3.5 px-4 text-slate-600 text-3xs">
                                    {{ rev.bank_account?.name || 'خزينة' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-500">
                                    {{ rev.revenue_date ? rev.revenue_date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(rev.amount) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-slate-500 text-end">
                                    {{ formatCurrency(rev.tax_amount) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono font-black text-emerald-600 text-end">
                                    {{ formatCurrency(rev.total_amount) }} <span class="text-3xs font-normal">﷼</span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <button 
                                        @click="deleteRevenue(rev.id, rev.revenue_number)"
                                        class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                        title="حذف"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!revenues.data || revenues.data.length === 0">
                                <td colspan="10" class="py-12 text-center text-slate-400">
                                    لا توجد سندات إيرادات مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Revenue Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-emerald-600" />
                            <span>تسجيل سند إيراد جديد</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitRevenue" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">بيان / مصدر الإيراد <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.title" 
                                type="text" 
                                required 
                                placeholder="مثال: تقديم استشارات وتطوير برمجيات"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">التصنيف <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.category" 
                                    type="text" 
                                    required 
                                    placeholder="استشارات، خدمات، عوائد..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">تاريخ القبض <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.revenue_date" 
                                    type="date" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
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
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-emerald-500 focus:border-emerald-500 text-end" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">ضريبة القيمة المضافة 15%</label>
                                <input 
                                    v-model="form.tax_amount" 
                                    type="number" 
                                    step="0.01" 
                                    placeholder="0.00"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-emerald-500 focus:border-emerald-500 text-end" 
                                />
                            </div>
                        </div>

                        <div class="p-3 bg-emerald-50/50 rounded-xl flex items-center justify-between text-xs border border-emerald-100">
                            <span class="font-bold text-emerald-900">المجموع الإجمالي شامل الضريبة:</span>
                            <span class="font-mono font-black text-sm text-emerald-600">
                                {{ formatCurrency((parseFloat(form.amount || 0) + parseFloat(form.tax_amount || 0))) }} ﷼
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">إيداع في (الخزينة أو البنك)</label>
                                <select 
                                    v-model="form.bank_account_id" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option v-for="bank in bankAccounts" :key="bank.id" :value="bank.id">
                                        {{ bank.name }} (الرصيد: {{ formatCurrency(bank.current_balance) }} ﷼)
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">حساب الإيراد في الدليل</label>
                                <select 
                                    v-model="form.account_id" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option v-for="acc in revenueAccounts" :key="acc.id" :value="acc.id">
                                        {{ acc.code }} - {{ acc.name_ar }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">العميل / الجهة الدافعة</label>
                                <input 
                                    v-model="form.customer_name" 
                                    type="text" 
                                    placeholder="اسم الشركة أو العميل"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">طريقة التحصيل</label>
                                <select 
                                    v-model="form.payment_method" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option value="bank_transfer">تحويل بنكي</option>
                                    <option value="cash">نقداً</option>
                                    <option value="card">بطاقة مدى / شبكة</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">ملاحظات أو تفاصيل</label>
                            <textarea 
                                v-model="form.notes" 
                                rows="2" 
                                placeholder="أي تفاصيل إضافية عن الإيراد..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-emerald-500 focus:border-emerald-500"
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
                                class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold shadow-xs hover:shadow-emerald-500/20 disabled:opacity-50"
                            >
                                حفظ الإيراد
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
