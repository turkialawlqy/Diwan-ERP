<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import { notify } from '@/composables/useDialog';
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    BookOpenIcon,
    XMarkIcon,
    FolderIcon,
    FolderOpenIcon,
    DocumentIcon,
    DocumentTextIcon,
    PencilSquareIcon,
    TrashIcon,
    ArrowsPointingOutIcon,
    ArrowsPointingInIcon,
    PlusCircleIcon,
    ArrowTopRightOnSquareIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    parentAccounts: Array,
    stats: Object,
    filters: Object,
});

// State for Modals
const isAddModalOpen = ref(false);
const isEditModalOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedType = ref(props.filters?.type || '');

// Form for Adding
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

// Form for Editing
const editForm = useForm({
    id: null,
    code: '',
    name_ar: '',
    name_en: '',
    parent_id: '',
    type: 'asset',
    nature: 'debit',
    is_parent: false,
    balance: '0.00',
    is_active: true,
});

// Tree Expansion State
// Default: expand all parent accounts initially
const expandedNodes = ref(new Set(
    props.accounts.filter(a => a.is_parent || (a.children_count && a.children_count > 0)).map(a => a.id)
));

// Fast Parent Lookup Map
const parentMap = computed(() => {
    const map = {};
    (props.accounts || []).forEach(acc => {
        map[acc.id] = acc;
    });
    return map;
});

// Toggle a single parent node
const toggleNode = (id) => {
    if (expandedNodes.value.has(id)) {
        expandedNodes.value.delete(id);
    } else {
        expandedNodes.value.add(id);
    }
};

// Expand All
const expandAll = () => {
    (props.accounts || []).forEach(acc => {
        if (acc.is_parent || acc.children_count > 0) {
            expandedNodes.value.add(acc.id);
        }
    });
};

// Collapse All
const collapseAll = () => {
    expandedNodes.value.clear();
};

// Check if a row should be visible based on expanded ancestor parents
const isRowVisible = (acc) => {
    // If user is searching or filtering, display all matching rows
    if (search.value.trim() || selectedType.value) {
        return true;
    }
    if (!acc.parent_id) {
        return true;
    }
    let currentParentId = acc.parent_id;
    while (currentParentId) {
        if (!expandedNodes.value.has(currentParentId)) {
            return false;
        }
        const p = parentMap.value[currentParentId];
        currentParentId = p ? p.parent_id : null;
    }
    return true;
};

// Calculate Account Hierarchy Level
const getAccountLevel = (acc) => {
    let level = 1;
    let currentParentId = acc.parent_id;
    while (currentParentId) {
        level++;
        const p = parentMap.value[currentParentId];
        currentParentId = p ? p.parent_id : null;
    }
    return level;
};

// Calculate Effective Balance (sum of child leaves for parent accounts, or direct balance for leaf accounts)
const getAccountEffectiveBalance = (acc) => {
    if (!acc.is_parent && (!acc.children_count || acc.children_count === 0)) {
        return Number(acc.balance || 0);
    }
    let sum = 0;
    const findDescendants = (parentId) => {
        (props.accounts || []).forEach(child => {
            if (child.parent_id === parentId) {
                if (!child.is_parent && (!child.children_count || child.children_count === 0)) {
                    sum += Number(child.balance || 0);
                } else {
                    findDescendants(child.id);
                }
            }
        });
    };
    findDescendants(acc.id);
    return sum;
};

// Suggest next available child code based on parent
const suggestChildCode = (parentId) => {
    if (!parentId) return '';
    const parent = parentMap.value[parentId];
    if (!parent) return '';
    
    // Find existing direct children
    const siblings = (props.accounts || []).filter(a => a.parent_id === parentId);
    if (siblings.length === 0) {
        return `${parent.code}01`;
    }
    
    // Try to find the highest sibling code
    const codes = siblings.map(s => {
        const num = parseInt(s.code, 10);
        return isNaN(num) ? 0 : num;
    }).sort((a, b) => b - a);
    
    if (codes.length > 0 && codes[0] > 0) {
        return String(codes[0] + 1);
    }
    return `${parent.code}${siblings.length + 1}`;
};

// Add Root Account
const openAddModal = () => {
    form.reset();
    form.parent_id = '';
    form.type = 'asset';
    form.nature = 'debit';
    form.is_parent = false;
    isAddModalOpen.value = true;
};

// Add Sub-Account under a specific parent
const openAddSubAccountModal = (parentAcc) => {
    form.reset();
    form.parent_id = parentAcc.id;
    form.type = parentAcc.type;
    form.nature = parentAcc.nature;
    form.is_parent = false;
    form.code = suggestChildCode(parentAcc.id);
    
    // Ensure parent is expanded so the newly added child will be visible
    expandedNodes.value.add(parentAcc.id);
    isAddModalOpen.value = true;
};

const onParentChange = () => {
    if (form.parent_id) {
        const parent = parentMap.value[form.parent_id];
        if (parent) {
            form.type = parent.type;
            form.nature = parent.nature;
            if (!form.code) {
                form.code = suggestChildCode(parent.id);
            }
        }
    }
};

const submitAccount = () => {
    form.post(route('accounting.accounts.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

// Edit Modal Handlers
const openEditModal = (acc) => {
    editForm.id = acc.id;
    editForm.code = acc.code;
    editForm.name_ar = acc.name_ar;
    editForm.name_en = acc.name_en || '';
    editForm.parent_id = acc.parent_id || '';
    editForm.type = acc.type;
    editForm.nature = acc.nature;
    editForm.is_parent = Boolean(acc.is_parent);
    editForm.balance = acc.balance;
    editForm.is_active = Boolean(acc.is_active);
    isEditModalOpen.value = true;
};

const submitEditAccount = () => {
    editForm.put(route('accounting.accounts.update', editForm.id), {
        onSuccess: () => {
            isEditModalOpen.value = false;
        }
    });
};

// Delete Handler
const deleteAccount = (acc) => {
    if (acc.children_count > 0) {
        notify('لا يمكن حذف هذا الحساب لوجود حسابات فرعية تابعة له. يرجى حذفها أو نقلها أولاً.');
        return;
    }
    if (acc.journal_lines_count > 0) {
        notify('لا يمكن حذف هذا الحساب لوجود قيود يومية وعمليات محاسبية مسجلة عليه.');
        return;
    }

    if (confirm(`هل أنت متأكد من حذف الحساب "${acc.code} - ${acc.name_ar}"؟`)) {
        router.delete(route('accounting.accounts.destroy', acc.id), {
            preserveScroll: true,
        });
    }
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
        <Head title="دليل الحسابات" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <ImportButton v-if="$can('accounting.accounts.create')" type="accounts" title="استيراد دليل الحسابات من Excel" hint="الحساب الجديد يُنشأ تحت أبيه (parent_code) ويرث نوعه. الحساب الموجود يُحدّث اسمه فقط." />
                    <button 
                        @click="handleSearch"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المحاسبة</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">دليل الحسابات</span>
                    </nav>
                </div>

                <!-- Action Buttons & Tree Controls -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="expandAll"
                        class="px-3.5 py-2.5 bg-white border border-slate-200/80 text-slate-700 hover:text-indigo-600 hover:bg-slate-50 rounded-xl text-xs font-semibold shadow-xs transition flex items-center gap-1.5"
                        title="توسيع كافة فروع شجرة الحسابات"
                    >
                        <ArrowsPointingOutIcon class="w-4 h-4" />
                        <span>توسيع الكل</span>
                    </button>

                    <button 
                        @click="collapseAll"
                        class="px-3.5 py-2.5 bg-white border border-slate-200/80 text-slate-700 hover:text-indigo-600 hover:bg-slate-50 rounded-xl text-xs font-semibold shadow-xs transition flex items-center gap-1.5"
                        title="طي كافة فروع شجرة الحسابات"
                    >
                        <ArrowsPointingInIcon class="w-4 h-4" />
                        <span>طي الكل</span>
                    </button>

                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة حساب رئيسي جديد</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-indigo-300 transition" @click="setTypeFilter('asset')">
                    <span class="text-xs font-bold text-slate-500">الأصول (Assets)</span>
                    <h3 class="text-lg font-black font-mono text-indigo-600 truncate">
                        {{ formatCurrency(stats?.total_assets) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-amber-300 transition" @click="setTypeFilter('liability')">
                    <span class="text-xs font-bold text-slate-500">الخصوم (Liabilities)</span>
                    <h3 class="text-lg font-black font-mono text-amber-600 truncate">
                        {{ formatCurrency(stats?.total_liabilities) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-purple-300 transition" @click="setTypeFilter('equity')">
                    <span class="text-xs font-bold text-slate-500">حقوق الملكية (Equity)</span>
                    <h3 class="text-lg font-black font-mono text-purple-600 truncate">
                        {{ formatCurrency(stats?.total_equity) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-emerald-300 transition" @click="setTypeFilter('revenue')">
                    <span class="text-xs font-bold text-slate-500">الإيرادات (Revenues)</span>
                    <h3 class="text-lg font-black font-mono text-emerald-600 truncate">
                        {{ formatCurrency(stats?.total_revenues) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-rose-300 transition" @click="setTypeFilter('expense')">
                    <span class="text-xs font-bold text-slate-500">المصروفات (Expenses)</span>
                    <h3 class="text-lg font-black font-mono text-rose-600 truncate">
                        {{ formatCurrency(stats?.total_expenses) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>
            </div>

            <!-- Search and Filter Tabs -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
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

            <!-- Accounts Tree Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start w-32">رمز الحساب</th>
                                <th class="py-4 px-6 text-start">شجرة الحسابات المحاسبية</th>
                                <th class="py-4 px-6 text-start w-28">النوع</th>
                                <th class="py-4 px-6 text-start w-28">طبيعة الحساب</th>
                                <th class="py-4 px-6 text-start w-24">المستوى</th>
                                <th class="py-4 px-6 text-end w-36">الرصيد التراكمي</th>
                                <th class="py-4 px-6 text-center w-56">إجراءات الحساب</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="acc in accounts" :key="acc.id">
                                <tr 
                                    v-if="isRowVisible(acc)"
                                    :class="[
                                        acc.is_parent || acc.children_count > 0 
                                            ? 'bg-slate-50/60 font-bold text-slate-900 hover:bg-slate-100/60' 
                                            : 'hover:bg-slate-50/80 text-slate-700',
                                        'transition-colors group'
                                    ]"
                                >
                                    <!-- Account Code -->
                                    <td class="py-3 px-4 font-mono font-bold text-indigo-600 whitespace-nowrap">
                                        {{ acc.code }}
                                    </td>

                                    <!-- Tree Name with Expand/Collapse and Hierarchy Indentation -->
                                    <td class="py-3 px-4">
                                        <div 
                                            class="flex items-center gap-2"
                                            :style="{ paddingInlineStart: ((getAccountLevel(acc) - 1) * 24) + 'px' }"
                                        >
                                            <!-- Collapse / Expand Toggle Button for Parents -->
                                            <button 
                                                v-if="acc.is_parent || acc.children_count > 0"
                                                @click.stop="toggleNode(acc.id)"
                                                class="w-5 h-5 flex items-center justify-center rounded-md hover:bg-slate-200/80 text-slate-500 transition-colors flex-shrink-0"
                                                :title="expandedNodes.has(acc.id) ? 'طي هذا الفرع' : 'توسيع هذا الفرع'"
                                            >
                                                <ChevronDownIcon 
                                                    :class="[
                                                        expandedNodes.has(acc.id) ? 'rotate-0' : '-rotate-90',
                                                        'w-3.5 h-3.5 text-slate-600 transition-transform duration-200'
                                                    ]" 
                                                />
                                            </button>
                                            <span v-else class="w-5 flex-shrink-0"></span>

                                            <!-- Folder / File Icon -->
                                            <template v-if="acc.is_parent || acc.children_count > 0">
                                                <FolderOpenIcon 
                                                    v-if="expandedNodes.has(acc.id)" 
                                                    class="w-4 h-4 text-amber-500 flex-shrink-0" 
                                                />
                                                <FolderIcon 
                                                    v-else 
                                                    class="w-4 h-4 text-amber-500 flex-shrink-0" 
                                                />
                                            </template>
                                            <DocumentIcon v-else class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" />

                                            <!-- Account Name -->
                                            <span class="truncate" :title="acc.name_ar">
                                                {{ acc.name_ar }}
                                            </span>

                                            <!-- Sub-accounts count badge if parent -->
                                            <span 
                                                v-if="acc.children_count > 0" 
                                                class="text-3xs font-semibold px-1.5 py-0.2 rounded-full bg-slate-200/70 text-slate-600"
                                                :title="`${acc.children_count} حسابات متفرعة`"
                                            >
                                                {{ acc.children_count }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Account Type Badge -->
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <span :class="[typeBadgeColors[acc.type], 'px-2 py-0.5 rounded-md text-3xs font-semibold border']">
                                            {{ typeLabels[acc.type] }}
                                        </span>
                                    </td>

                                    <!-- Nature (Debit/Credit) -->
                                    <td class="py-3 px-4 text-slate-500 text-3xs whitespace-nowrap">
                                        {{ acc.nature === 'debit' ? 'مدين (Debit)' : 'دائن (Credit)' }}
                                    </td>

                                    <!-- Hierarchy Level -->
                                    <td class="py-3 px-4 text-slate-400 text-3xs whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 font-mono">
                                            مستوى {{ getAccountLevel(acc) }}
                                        </span>
                                    </td>

                                    <!-- Running Balance -->
                                    <td class="py-3 px-4 font-mono font-black text-end whitespace-nowrap">
                                        <span :class="[
                                            getAccountEffectiveBalance(acc) < 0 ? 'text-rose-600' : (acc.is_parent || acc.children_count > 0 ? 'text-indigo-700' : 'text-slate-900')
                                        ]">
                                            {{ formatCurrency(getAccountEffectiveBalance(acc)) }} <span class="text-3xs font-normal">﷼</span>
                                        </span>
                                    </td>

                                    <!-- Action Buttons Column -->
                                    <td class="py-2.5 px-4 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            <!-- 1. زر إضافة حساب فرعي (Add Sub-Account) -->
                                            <button 
                                                @click.stop="openAddSubAccountModal(acc)"
                                                class="px-2 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-3xs font-bold transition flex items-center gap-1 border border-indigo-200/60 shadow-2xs"
                                                title="إضافة حساب فرعي متفرع من هذا الحساب"
                                            >
                                                <PlusCircleIcon class="w-3.5 h-3.5" />
                                                <span>فرعي</span>
                                            </button>

                                            <!-- 2. زر كشف الحساب للحسابات الفرعية (Statement of Account) -->
                                            <Link 
                                                v-if="!acc.is_parent || acc.children_count === 0"
                                                :href="route('accounting.ledger.index', { account_id: acc.id })"
                                                class="px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg text-3xs font-bold transition flex items-center gap-1 border border-emerald-200/60 shadow-2xs"
                                                title="عرض كشف الحساب ودفتر الأستاذ التفصيلي"
                                            >
                                                <DocumentTextIcon class="w-3.5 h-3.5" />
                                                <span>كشف الحساب</span>
                                            </Link>

                                            <!-- 3. زر تعديل الحساب (Edit) -->
                                            <button 
                                                @click.stop="openEditModal(acc)"
                                                class="p-1 text-slate-500 hover:text-indigo-600 hover:bg-slate-100 rounded-lg transition"
                                                title="تعديل بيانات الحساب"
                                            >
                                                <PencilSquareIcon class="w-4 h-4" />
                                            </button>

                                            <!-- 4. زر حذف الحساب (Delete) -->
                                            <button 
                                                @click.stop="deleteAccount(acc)"
                                                :disabled="acc.children_count > 0 || acc.journal_lines_count > 0"
                                                :class="[
                                                    acc.children_count > 0 || acc.journal_lines_count > 0 
                                                        ? 'text-slate-300 cursor-not-allowed' 
                                                        : 'text-slate-400 hover:text-rose-600 hover:bg-rose-50',
                                                    'p-1 rounded-lg transition'
                                                ]"
                                                :title="
                                                    acc.children_count > 0 
                                                        ? 'لا يمكن حذف الحساب لوجود حسابات فرعية تحته' 
                                                        : (acc.journal_lines_count > 0 ? 'لا يمكن حذف الحساب لوجود قيود مسجلة عليه' : 'حذف الحساب')
                                                "
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!accounts || accounts.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    لا توجد حسابات مسجلة مطابقة للبحث.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal: Add New Account / Sub-Account -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BookOpenIcon class="w-5 h-5 text-indigo-600" />
                            <span>{{ form.parent_id ? 'إضافة حساب فرعي جديد' : 'إضافة حساب في شجرة الحسابات' }}</span>
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
                                <option value="">حساب رئيسي مستوى أول (بدون حساب أب)</option>
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
                                    placeholder="مثال: 110101 أو 527"
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
                                placeholder="مثال: بنك الإنماء - الحساب الجاري"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اسم الحساب بالإنجليزية (اختياري)</label>
                            <input 
                                v-model="form.name_en" 
                                type="text" 
                                placeholder="مثال: Alinma Bank - Current Account"
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

            <!-- Modal: Edit Account -->
            <div 
                v-if="isEditModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <PencilSquareIcon class="w-5 h-5 text-indigo-600" />
                            <span>تعديل بيانات الحساب المحاسبي</span>
                        </h2>
                        <button @click="isEditModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEditAccount" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">الحساب الأب / التابع له</label>
                            <select 
                                v-model="editForm.parent_id" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                            >
                                <option value="">حساب رئيسي مستوى أول (بدون حساب أب)</option>
                                <option 
                                    v-for="p in parentAccounts" 
                                    :key="p.id" 
                                    :value="p.id"
                                    :disabled="p.id === editForm.id"
                                >
                                    {{ p.code }} - {{ p.name_ar }} ({{ typeLabels[p.type] }})
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">رمز الحساب (الكود) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="editForm.code" 
                                    type="text" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">طبيعة الحساب <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="editForm.nature" 
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
                                v-model="editForm.name_ar" 
                                type="text" 
                                required 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اسم الحساب بالإنجليزية</label>
                            <input 
                                v-model="editForm.name_en" 
                                type="text" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">نوع الحساب <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="editForm.type" 
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
                                <label class="block font-medium text-slate-700 mb-1">الرصيد الحسابي (﷼)</label>
                                <input 
                                    v-model="editForm.balance" 
                                    type="number" 
                                    step="0.01" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500 text-end" 
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-6 pt-2">
                            <div class="flex items-center gap-2">
                                <input 
                                    v-model="editForm.is_parent" 
                                    id="edit_is_parent" 
                                    type="checkbox" 
                                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <label for="edit_is_parent" class="font-medium text-slate-700 cursor-pointer">
                                    حساب رئيسي (مجلد)
                                </label>
                            </div>

                            <div class="flex items-center gap-2">
                                <input 
                                    v-model="editForm.is_active" 
                                    id="edit_is_active" 
                                    type="checkbox" 
                                    class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                />
                                <label for="edit_is_active" class="font-medium text-slate-700 cursor-pointer">
                                    نشط ومتاح للعمليات
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                            <button 
                                type="button" 
                                @click="isEditModalOpen = false"
                                class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium"
                            >
                                إلغاء
                            </button>
                            <button 
                                type="submit" 
                                :disabled="editForm.processing"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                حفظ التعديلات
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
