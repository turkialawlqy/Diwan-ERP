<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    EllipsisVerticalIcon,
    EyeIcon,
    TrashIcon,
    ChevronLeftIcon,
    DocumentTextIcon,
    TruckIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoices: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const activeMenu = ref(null);

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const applyFilters = () => {
    router.get(route('purchases.invoices.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilters = () => {
    search.value = '';
    status.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    router.get(route('purchases.invoices.index'), {}, { preserveState: true, replace: true });
};

const deleteInvoice = (inv) => {
    if (confirm(`هل أنت متأكد من حذف فاتورة الشراء رقم "${inv.invoice_number}" وتراجع كميات المخزون؟`)) {
        router.delete(route('purchases.invoices.destroy', inv.id));
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
        <Head title="فواتير المشتريات - الأستاذ" />

        <div class="space-y-5" @click="activeMenu = null">
            <!-- 1. Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('purchases.invoices.create')"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة فاتورة شراء</span>
                    </Link>

                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        :class="[
                            'px-4 py-2 rounded-xl text-xs font-semibold border transition-colors flex items-center gap-1.5',
                            isFilterOpen || search || status
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-bold' 
                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4" />
                        <span>البحث والتصفية</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="applyFilters"
                        class="p-2 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-2xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-slate-400">المشتريات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">فواتير الشراء</span>
                    </div>
                </div>
            </div>

            <!-- Filter Panel -->
            <div v-show="isFilterOpen" class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">بحث برقم الفاتورة أو المورد</label>
                        <input 
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text" 
                            placeholder="BILL-26... أو اسم المورد" 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        />
                    </div>
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">حالة السداد</label>
                        <select 
                            v-model="status" 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        >
                            <option value="">جميع الحالات</option>
                            <option value="paid">مدفوعة بالكامل</option>
                            <option value="partially_paid">مدفوعة جزئياً</option>
                            <option value="unpaid">غير مدفوعة (مستحقة)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">من تاريخ</label>
                        <input 
                            v-model="dateFrom"
                            type="date" 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        />
                    </div>
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">إلى تاريخ</label>
                        <input 
                            v-model="dateTo"
                            type="date" 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        />
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button 
                        @click="resetFilters"
                        class="px-4 py-1.5 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl text-xs font-semibold"
                    >
                        إلغاء التصفية
                    </button>
                    <button 
                        @click="applyFilters"
                        class="px-5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold"
                    >
                        تطبيق الفلتر
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">إجمالي الفواتير</p>
                    <h3 class="text-lg font-black text-slate-900 mt-0.5">{{ stats?.total_invoices_count || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">إجمالي المشتريات</p>
                    <h3 class="text-lg font-black text-slate-900 font-mono mt-0.5">{{ formatCurrency(stats?.total_purchases_amount) }} ﷼</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">المسدد للموردين</p>
                    <h3 class="text-lg font-black text-emerald-600 font-mono mt-0.5">{{ formatCurrency(stats?.total_paid_amount) }} ﷼</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">المستحق المتبقي</p>
                    <h3 class="text-lg font-black text-rose-600 font-mono mt-0.5">{{ formatCurrency(stats?.total_due_amount) }} ﷼</h3>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200/80 text-slate-500 font-bold">
                                <th class="py-3 px-4">رقم الفاتورة</th>
                                <th class="py-3 px-4">المورد</th>
                                <th class="py-3 px-4">المستودع</th>
                                <th class="py-3 px-4">تاريخ الفاتورة</th>
                                <th class="py-3 px-4">الإجمالي شامل الضريبة</th>
                                <th class="py-3 px-4">المسدد / المتبقي</th>
                                <th class="py-3 px-4 text-center">حالة السداد</th>
                                <th class="py-3 px-4 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="inv in invoices.data" 
                                :key="inv.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600">
                                    <Link :href="route('purchases.invoices.show', inv.id)" class="hover:underline">
                                        {{ inv.invoice_number }}
                                    </Link>
                                    <div v-if="inv.supplier_invoice_number" class="text-4xs text-slate-400 font-sans">
                                        مرجع: {{ inv.supplier_invoice_number }}
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-bold text-slate-900">
                                    {{ inv.supplier?.name || '-' }}
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    {{ inv.warehouse?.name_ar || 'المستودع الرئيسي' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    {{ String(inv.invoice_date).substring(0, 10) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-900">
                                    {{ formatCurrency(inv.total) }} <span class="text-3xs font-normal">﷼</span>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs">
                                    <div class="text-emerald-700 font-bold">{{ formatCurrency(inv.paid_amount) }} ﷼</div>
                                    <div v-if="inv.remaining_amount > 0" class="text-rose-600 font-bold">متبقي: {{ formatCurrency(inv.remaining_amount) }} ﷼</div>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span 
                                        v-if="inv.payment_status === 'paid'"
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                                    >
                                        <CheckCircleIcon class="w-3.5 h-3.5" />
                                        <span>مدفوعة</span>
                                    </span>
                                    <span 
                                        v-else-if="inv.payment_status === 'partially_paid'"
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200/60"
                                    >
                                        <ClockIcon class="w-3.5 h-3.5" />
                                        <span>مدفوعة جزئياً</span>
                                    </span>
                                    <span 
                                        v-else
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200/60"
                                    >
                                        <ExclamationCircleIcon class="w-3.5 h-3.5" />
                                        <span>مستحقة الدفع</span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <Link 
                                            :href="route('purchases.invoices.show', inv.id)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                                            title="عرض الفاتورة"
                                        >
                                            <EyeIcon class="w-4 h-4" />
                                        </Link>
                                        <button 
                                            @click="deleteInvoice(inv)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"
                                            title="حذف"
                                        >
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!invoices.data || invoices.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا توجد فواتير مشتريات مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
