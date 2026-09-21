<script setup>
import { ref, computed } from 'vue';
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
    TrashIcon,
    EyeIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    entries: Object,
    stats: Object,
    accounts: Array,
    filters: Object,
});

const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');

const form = useForm({
    date: new Date().toISOString().split('T')[0],
    reference: '',
    description: '',
    lines: [
        { account_id: props.accounts?.[0]?.id || '', description: '', debit: 0, credit: 0 },
        { account_id: props.accounts?.[1]?.id || '', description: '', debit: 0, credit: 0 },
    ],
});

const addLine = () => {
    form.lines.push({
        account_id: props.accounts?.[0]?.id || '',
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
        { account_id: props.accounts?.[0]?.id || '', description: '', debit: 0, credit: 0 },
        { account_id: props.accounts?.[1]?.id || '', description: '', debit: 0, credit: 0 },
    ];
    isAddModalOpen.value = true;
};

const submitEntry = () => {
    if (!isBalanced.value) return;

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
</script>

<template>
    <AppLayout>
        <Head title="القيود اليومية - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إنشاء قيد يومية جديد</span>
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
                        <span class="text-slate-900 font-semibold">القيود اليومية</span>
                    </nav>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي القيود اليومية</span>
                        <h3 class="text-lg font-black font-mono text-slate-900">{{ stats?.total_entries || 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BookOpenIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">حجم التعاملات المقيدة</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_volume) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <CheckCircleIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">القيود المرحلة والمعتمدة</span>
                        <h3 class="text-lg font-black text-indigo-600">{{ stats?.posted_count || 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BookOpenIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="bg-white p-3 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                <div class="relative w-full sm:w-80">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                    <input 
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text" 
                        placeholder="بحث برقم القيد، البيان، المرجع..."
                        class="w-full ps-9 pe-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                    />
                </div>
            </div>

            <!-- Journal Entries Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-3 px-4 text-start">رقم القيد</th>
                                <th class="py-3 px-4 text-start">التاريخ</th>
                                <th class="py-3 px-4 text-start">بيان القيد العام</th>
                                <th class="py-3 px-4 text-start">المرجع</th>
                                <th class="py-3 px-4 text-end">إجمالي المدين</th>
                                <th class="py-3 px-4 text-end">إجمالي الدائن</th>
                                <th class="py-3 px-4 text-center">الحالة</th>
                                <th class="py-3 px-4 text-center">عرض السند</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600">
                                    {{ entry.entry_number }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    {{ entry.date ? entry.date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-900 max-w-sm truncate">
                                    {{ entry.description }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-500">
                                    {{ entry.reference || '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_debit) }} ﷼
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_credit) }} ﷼
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-3xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        مرحل
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <Link 
                                        :href="route('accounting.journals.show', entry.id)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-3xs font-bold text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
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

                    <form @submit.prevent="submitEntry" class="space-y-4 text-xs flex-1 overflow-y-auto pe-1">
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
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs"
                                            >
                                                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                    {{ acc.code }} - {{ acc.name_ar }}
                                                </option>
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
                                        <td colspan="2" class="p-2 text-start">
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
