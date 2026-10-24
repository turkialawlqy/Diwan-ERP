<script setup>
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    BookOpenIcon,
    XMarkIcon,
    TrashIcon,
    EyeIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    entries: Object,
    stats: Object,
    accounts: Array,
    costCenters: Array,
    customers: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    partyAccounts: { type: Object, default: () => ({}) },
    filters: Object,
});

// Receivable / payable lines name their customer or supplier.
const partyType = (line) => props.partyAccounts?.[String(line.account_id)] || null;
const syncParty = (line) => {
    const type = partyType(line);
    if (line.party_type !== type) {
        line.party_type = type || '';
        line.party_id = '';
    }
};

const statusBadge = {
    draft: ['مسودة', 'bg-amber-50 text-amber-700 border-amber-200'],
    posted: ['مرحّل', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    reversed: ['معكوس', 'bg-rose-50 text-rose-700 border-rose-200'],
};
const typeLabels = { manual: 'يدوي', system: 'آلي', opening: 'افتتاحي', closing: 'إقفال', adjustment: 'تسوية', recurring: 'متكرر', reversal: 'عكسي' };

const isFilterOpen = ref(false);
const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');

const form = useForm({
    date: new Date().toISOString().split('T')[0],
    reference: '',
    description: '',
    entry_type: 'manual',
    save_as: 'posted',
    lines: [
        { account_id: props.accounts?.[0]?.id || '', cost_center_id: '', party_type: '', party_id: '', description: '', debit: 0, credit: 0 },
        { account_id: props.accounts?.[1]?.id || '', cost_center_id: '', party_type: '', party_id: '', description: '', debit: 0, credit: 0 },
    ],
});

const addLine = () => {
    form.lines.push({
        account_id: props.accounts?.[0]?.id || '',
        cost_center_id: '',
        party_type: '',
        party_id: '',
        description: '',
        debit: 0,
        credit: 0,
    });
};

const removeLine = (index) => {
    if (form.lines.length > 2) {
        form.lines.splice(index, 1);
    }
};

const totalDebit = computed(() => {
    return form.lines.reduce((acc, l) => acc + (parseFloat(l.debit) || 0), 0);
});

const totalCredit = computed(() => {
    return form.lines.reduce((acc, l) => acc + (parseFloat(l.credit) || 0), 0);
});

const isBalanced = computed(() => {
    return Math.abs(totalDebit.value - totalCredit.value) < 0.01 && totalDebit.value > 0;
});

const openAddModal = () => {
    form.reset();
    form.date = new Date().toISOString().split('T')[0];
    form.lines = [
        { account_id: props.accounts?.[0]?.id || '', cost_center_id: '', party_type: '', party_id: '', description: '', debit: 0, credit: 0 },
        { account_id: props.accounts?.[1]?.id || '', cost_center_id: '', party_type: '', party_id: '', description: '', debit: 0, credit: 0 },
    ];
    isAddModalOpen.value = true;
};

const submitEntry = (saveAs = 'posted') => {
    form.lines.forEach(syncParty);
    if (!isBalanced.value) return;
    form.save_as = saveAs;

    form.post(route('accounting.journals.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const handleSearch = () => {
    router.get(route('accounting.journals.index'), {
        search: search.value || undefined,
    }, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const importLines = (lines, replace) => {
    const rows = lines.map((l) => ({ account_id: l.account_id, cost_center_id: l.cost_center_id || '', party_type: '', party_id: '', description: l.description || '', debit: l.debit, credit: l.credit }));
    form.lines = replace ? rows : [...form.lines.filter((l) => Number(l.debit) || Number(l.credit)), ...rows];
};
</script>

<template>
    <AppLayout own-errors>
        <Head title="القيود اليومية" />

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
                        <span class="text-slate-500">المحاسبة</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">القيود اليومية</span>
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
                            isFilterOpen || search
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
                        <span>إنشاء قيد يومية</span>
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
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">بحث برقم القيد، البيان، المرجع</label>
                            <input 
                                v-model="search"
                                @keyup.enter="handleSearch"
                                type="text" 
                                placeholder="اكتب للبحث..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div class="flex items-end gap-2">
                            <button 
                                @click="handleSearch"
                                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors shadow-xs"
                            >
                                تطبيق التصفية
                            </button>
                            <button 
                                @click="search = ''; handleSearch()"
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
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي القيود اليومية</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_entries || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">حجم التعاملات المقيدة</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.total_volume) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">القيود المرحلة والمعتمدة</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ stats?.posted_count || 0 }}</p>
                </div>
            </div>

            <!-- Journal Entries Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">رقم القيد</th>
                                <th class="py-4 px-6 text-start">التاريخ</th>
                                <th class="py-4 px-6 text-start">بيان القيد العام</th>
                                <th class="py-4 px-6 text-start">المرجع</th>
                                <th class="py-4 px-6 text-end">إجمالي المدين</th>
                                <th class="py-4 px-6 text-end">إجمالي الدائن</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center">عرض السند</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ entry.entry_number }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ entry.date ? entry.date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 font-medium text-slate-900 max-w-sm truncate">
                                    {{ entry.description }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-500">
                                    {{ entry.reference || '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_debit) }} ﷼
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_credit) }} ﷼
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span :class="[statusBadge[entry.status]?.[1], 'px-2.5 py-0.5 rounded-full text-2xs font-bold border']">
                                        {{ statusBadge[entry.status]?.[0] || entry.status }}
                                    </span>
                                    <span class="block text-[10px] text-slate-400 mt-0.5">{{ typeLabels[entry.entry_type] || '' }}</span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link 
                                        :href="route('accounting.journals.show', entry.id)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
                                    >
                                        <EyeIcon class="w-3.5 h-3.5" />
                                        <span>عرض السند</span>
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!entries.data || entries.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا توجد قيود يومية مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="entries?.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ entries.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="entries.per_page"
                            @change="router.get(route('accounting.journals.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ entries.current_page }} من {{ entries.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="entries.prev_page_url" 
                                :href="entries.prev_page_url"
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
                                {{ entries.current_page }}
                            </span>
                            <Link 
                                v-if="entries.next_page_url" 
                                :href="entries.next_page_url"
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

            <!-- Create Journal Entry Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-3xl w-full p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] flex flex-col">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 flex-shrink-0">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BookOpenIcon class="w-5 h-5 text-indigo-600" />
                            <span>إنشاء قيد يومية محاسبي جديد (Journal Entry)</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEntry('posted')" class="space-y-4 text-xs flex-1 overflow-y-auto pe-1">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">تاريخ القيد <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.date" 
                                    type="date" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الرقم المرجعي (اختياري)</label>
                                <input 
                                    v-model="form.reference" 
                                    type="text" 
                                    placeholder="رقم شيك، إشعار، عقد..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">نوع القيد</label>
                            <select v-model="form.entry_type" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                <option value="manual">قيد يومية عادي</option>
                                <option value="opening">قيد أرصدة افتتاحية</option>
                                <option value="adjustment">قيد تسوية</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">بيان القيد العام <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.description" 
                                type="text" 
                                required 
                                placeholder="شرح القيد المحاسبي..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <!-- Lines Table -->
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <table class="w-full text-3xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-600 border-b border-slate-200 font-bold">
                                        <th class="py-2.5 px-3 text-start">الحساب المحاسبي</th>
                                        <th class="py-2.5 px-3 text-start">مركز التكلفة</th>
                                        <th class="py-2.5 px-3 text-start">شرح البند</th>
                                        <th class="py-2.5 px-3 text-end w-28">مدين (Debit)</th>
                                        <th class="py-2.5 px-3 text-end w-28">دائن (Credit)</th>
                                        <th class="py-2.5 px-2 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(line, idx) in form.lines" :key="idx" class="bg-white">
                                        <td class="p-2">
                                            <select 
                                                v-model="line.account_id" 
                                                required 
                                                @change="syncParty(line)"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs"
                                            >
                                                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                    {{ acc.code }} - {{ acc.name_ar }}
                                                </option>
                                            </select>
                                            <select v-if="partyType(line)" v-model="line.party_id" required
                                                class="mt-1 w-full py-1 px-2 bg-amber-50 border border-amber-200 rounded-lg text-3xs">
                                                <option value="">{{ partyType(line) === 'customer' ? '— اختر العميل —' : '— اختر المورد —' }}</option>
                                                <option v-for="p in (partyType(line) === 'customer' ? customers : suppliers)" :key="p.id" :value="p.id">{{ p.name }}</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            <select v-model="line.cost_center_id" class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs">
                                                <option value="">—</option>
                                                <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.code }} - {{ cc.name_ar }}</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model="line.description" 
                                                type="text" 
                                                placeholder="بيان تفصيلي"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model="line.debit" 
                                                type="number" 
                                                step="0.01" 
                                                min="0"
                                                @focus="line.credit = 0"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs font-mono text-end font-bold"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model="line.credit" 
                                                type="number" 
                                                step="0.01" 
                                                min="0"
                                                @focus="line.debit = 0"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs font-mono text-end font-bold"
                                            />
                                        </td>
                                        <td class="p-2 text-center">
                                            <button 
                                                type="button" 
                                                @click="removeLine(idx)" 
                                                :disabled="form.lines.length <= 2"
                                                class="p-1 text-slate-300 hover:text-rose-600 disabled:opacity-20"
                                            >
                                                <TrashIcon class="w-3.5 h-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 font-bold border-t border-slate-200">
                                        <td colspan="3" class="p-2 text-start">
                                            <ImportLines kind="journal" label="استيراد الأطراف من Excel" @imported="importLines" />
                                            <button 
                                                type="button" 
                                                @click="addLine" 
                                                class="text-indigo-600 hover:text-indigo-800 font-bold flex items-center gap-1"
                                            >
                                                <PlusIcon class="w-3.5 h-3.5" />
                                                <span>إضافة طرف قيد</span>
                                            </button>
                                        </td>
                                        <td class="p-2 text-end font-mono font-black text-xs text-slate-900">
                                            {{ formatCurrency(totalDebit) }} ﷼
                                        </td>
                                        <td class="p-2 text-end font-mono font-black text-xs text-slate-900">
                                            {{ formatCurrency(totalCredit) }} ﷼
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div v-if="Object.keys(form.errors).length" class="p-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs space-y-1">
                            <p v-for="(msg, key) in form.errors" :key="key">{{ msg }}</p>
                        </div>

                        <!-- Balance Verification Status -->
                        <div 
                            :class="[
                                isBalanced ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-amber-50 border-amber-200 text-amber-800',
                                'p-3 rounded-xl border flex items-center justify-between text-xs'
                            ]"
                        >
                            <div class="flex items-center gap-2">
                                <CheckCircleIcon v-if="isBalanced" class="w-5 h-5 text-emerald-600" />
                                <ExclamationTriangleIcon v-else class="w-5 h-5 text-amber-600" />
                                <span v-if="isBalanced" class="font-bold">القيد متوازن وجاهز للترحيل مباشرة.</span>
                                <span v-else class="font-bold">القيد غير متوازن! الفرق بين المدين والدائن: {{ formatCurrency(Math.abs(totalDebit - totalCredit)) }} ﷼</span>
                            </div>
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
                                type="button"
                                @click="submitEntry('draft')"
                                :disabled="!isBalanced || form.processing"
                                class="px-4 py-2 border border-amber-300 text-amber-700 rounded-xl font-bold disabled:opacity-50"
                            >
                                حفظ كمسودة
                            </button>
                            <button 
                                v-if="$can('accounting.journals.post')"
                                type="submit" 
                                :disabled="!isBalanced || form.processing"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                ترحيل القيد اليومي
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
