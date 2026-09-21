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
    BookOpenIcon,
    XMarkIcon,
    FolderIcon,
    DocumentIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    parentAccounts: Array,
    stats: Object,
    filters: Object,
});

const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedType = ref(props.filters?.type || '');

const form = useForm({
    code: '',
    name_ar: '',
    name_en: '',
    parent_id: '',
    type: 'asset',
    nature: 'debit',
    is_parent: false,
    balance: '0.00',
});

const onParentChange = () => {
    if (form.parent_id) {
        const parent = props.parentAccounts.find(p => p.id === form.parent_id);
        if (parent) {
            form.type = parent.type;
            form.nature = ['asset', 'expense'].includes(parent.type) ? 'debit' : 'credit';
        }
    }
};

const openAddModal = () => {
    form.reset();
    isAddModalOpen.value = true;
};

const submitAccount = () => {
    form.post(route('accounting.accounts.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const handleSearch = () => {
    router.get(route('accounting.accounts.index'), {
        search: search.value || undefined,
        type: selectedType.value || undefined,
    }, { preserveState: true, replace: true });
};

const setTypeFilter = (type) => {
    selectedType.value = type;
    handleSearch();
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const typeLabels = {
    asset: 'أصول',
    liability: 'خصوم والتزامات',
    equity: 'حقوق ملكية',
    revenue: 'إيرادات',
    expense: 'مصروفات',
};

const typeBadgeColors = {
    asset: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    liability: 'bg-amber-50 text-amber-700 border-amber-200',
    equity: 'bg-purple-50 text-purple-700 border-purple-200',
    revenue: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    expense: 'bg-rose-50 text-rose-700 border-rose-200',
};
</script>

<template>
    <AppLayout>
        <Head title="دليل الحسابات - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة حساب جديد</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleSearch"
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
                        <span class="text-slate-900 font-semibold">دليل الحسابات</span>
                    </nav>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-2xs space-y-1 cursor-pointer hover:border-indigo-300 transition" @click="setTypeFilter('asset')">
                    <span class="text-3xs font-bold text-slate-400">الأصول (Assets)</span>
                    <h3 class="text-sm sm:text-base font-black font-mono text-indigo-600 truncate">
                        {{ formatCurrency(stats?.total_assets) }} ﷼
                    </h3>
                </div>

                <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-2xs space-y-1 cursor-pointer hover:border-amber-300 transition" @click="setTypeFilter('liability')">
                    <span class="text-3xs font-bold text-slate-400">الخصوم (Liabilities)</span>
                    <h3 class="text-sm sm:text-base font-black font-mono text-amber-600 truncate">
                        {{ formatCurrency(stats?.total_liabilities) }} ﷼
                    </h3>
                </div>

                <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-2xs space-y-1 cursor-pointer hover:border-purple-300 transition" @click="setTypeFilter('equity')">
                    <span class="text-3xs font-bold text-slate-400">حقوق الملكية (Equity)</span>
                    <h3 class="text-sm sm:text-base font-black font-mono text-purple-600 truncate">
                        {{ formatCurrency(stats?.total_equity) }} ﷼
                    </h3>
                </div>

                <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-2xs space-y-1 cursor-pointer hover:border-emerald-300 transition" @click="setTypeFilter('revenue')">
                    <span class="text-3xs font-bold text-slate-400">الإيرادات (Revenues)</span>
                    <h3 class="text-sm sm:text-base font-black font-mono text-emerald-600 truncate">
                        {{ formatCurrency(stats?.total_revenues) }} ﷼
                    </h3>
                </div>

                <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-2xs space-y-1 cursor-pointer hover:border-rose-300 transition" @click="setTypeFilter('expense')">
                    <span class="text-3xs font-bold text-slate-400">المصروفات (Expenses)</span>
                    <h3 class="text-sm sm:text-base font-black font-mono text-rose-600 truncate">
                        {{ formatCurrency(stats?.total_expenses) }} ﷼
                    </h3>
                </div>
            </div>

            <!-- Search and Filter Tabs -->
            <div class="bg-white p-3 rounded-2xl border border-slate-100 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                    <input 
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text" 
                        placeholder="بحث برقم الكود، أو اسم الحساب..."
                        class="w-full ps-9 pe-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                    />
                </div>

                <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto pb-1 sm:pb-0">
                    <button 
                        @click="setTypeFilter('')"
                        :class="[!selectedType ? 'bg-indigo-600 text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100', 'px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition']"
                    >
                        الكل ({{ stats?.total_accounts_count }})
                    </button>
                    <button 
                        v-for="(label, key) in typeLabels" 
                        :key="key"
                        @click="setTypeFilter(key)"
                        :class="[selectedType === key ? 'bg-indigo-600 text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100', 'px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition']"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>

            <!-- Accounts Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-3 px-4 text-start">رمز الحساب</th>
                                <th class="py-3 px-4 text-start">اسم الحساب المحاسبي</th>
                                <th class="py-3 px-4 text-start">النوع / التصنيف</th>
                                <th class="py-3 px-4 text-start">طبيعة الحساب</th>
                                <th class="py-3 px-4 text-start">المستوى</th>
                                <th class="py-3 px-4 text-end">الرصيد التراكمي الحسابي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="acc in accounts" 
                                :key="acc.id" 
                                :class="[
                                    acc.is_parent ? 'bg-slate-50/40 font-bold text-slate-900' : 'hover:bg-slate-50/50 text-slate-700',
                                    'transition-colors'
                                ]"
                            >
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                    {{ acc.code }}
                                </td>
                                <td class="py-3 px-4 flex items-center gap-2" :style="{ paddingInlineStart: (acc.code.length * 8) + 'px' }">
                                    <FolderIcon v-if="acc.is_parent" class="w-4 h-4 text-amber-500 flex-shrink-0" />
                                    <DocumentIcon v-else class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" />
                                    <span>{{ acc.name_ar }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span :class="[typeBadgeColors[acc.type], 'px-2 py-0.5 rounded-md text-3xs font-semibold border']">
                                        {{ typeLabels[acc.type] }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-slate-500 text-3xs">
                                    {{ acc.nature === 'debit' ? 'مدين (Debit)' : 'دائن (Credit)' }}
                                </td>
                                <td class="py-3 px-4 text-slate-400 text-3xs">
                                    {{ acc.is_parent ? 'رئيسي / مجلد' : 'حساب فرعي نهائي' }}
                                </td>
                                <td class="py-3 px-4 font-mono font-black text-end">
                                    <span :class="acc.balance < 0 ? 'text-rose-600' : 'text-slate-900'">
                                        {{ formatCurrency(acc.balance) }} <span class="text-3xs font-normal">﷼</span>
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!accounts || accounts.length === 0">
                                <td colspan="6" class="py-12 text-center text-slate-400">
                                    لا توجد حسابات مسجلة مطابقة للبحث.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Account Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BookOpenIcon class="w-5 h-5 text-indigo-600" />
                            <span>إضافة حساب جديد في شجرة الحسابات</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitAccount" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">الحساب الأب / التابع له</label>
                            <select 
                                v-model="form.parent_id" 
                                @change="onParentChange"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">حساب رئيسي مستوى أول</option>
                                <option v-for="p in parentAccounts" :key="p.id" :value="p.id">
                                    {{ p.code }} - {{ p.name_ar }} ({{ typeLabels[p.type] }})
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">رمز الحساب (الكود) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.code" 
                                    type="text" 
                                    required 
                                    placeholder="مثال: 1114 أو 527"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">طبيعة الحساب <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.nature" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="debit">مدين (Debit)</option>
                                    <option value="credit">دائن (Credit)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اسم الحساب بالعربية <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar" 
                                type="text" 
                                required 
                                placeholder="مثال: بنك الإنماء - الحساب التجاري"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">نوع الحساب <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="asset">أصول</option>
                                    <option value="liability">خصوم والتزامات</option>
                                    <option value="equity">حقوق ملكية</option>
                                    <option value="revenue">إيرادات</option>
                                    <option value="expense">مصروفات</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الرصيد الافتتاحي (﷼)</label>
                                <input 
                                    v-model="form.balance" 
                                    type="number" 
                                    step="0.01" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500 text-end" 
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pt-2">
                            <input 
                                v-model="form.is_parent" 
                                id="is_parent" 
                                type="checkbox" 
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <label for="is_parent" class="font-medium text-slate-700 cursor-pointer">
                                حساب رئيسي يتفرع منه حسابات أخرى (مجلد)
                            </label>
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
                                حفظ الحساب
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
