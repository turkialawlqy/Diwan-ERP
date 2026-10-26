<script setup>
import { ref, watch } from 'vue';
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
    PrinterIcon,
    TrashIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationCircleIcon,
    XMarkIcon,
    ChevronLeftIcon,
    FunnelIcon,
    DocumentTextIcon,
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

// Close dropdown when clicking outside
const closeMenu = () => {
    activeMenu.value = null;
};

const applyFilters = () => {
    router.get(route('sales.invoices.index'), {
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
    router.get(route('sales.invoices.index'), {}, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const formatDateArabic = (dateStr) => {
    if (!dateStr) return '-';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('ar-SA', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch {
        return dateStr;
    }
};
</script>

<template>
    <AppLayout>
        <Head title="فواتير المبيعات" />

        <div class="space-y-5" @click="closeMenu">
            <!-- Top Breadcrumbs & Action Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs Ribbon -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-slate-500">المبيعات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الفواتير</span>
                    </div>

                    <!-- Refresh Button -->
                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Primary Action Buttons -->
                <div class="flex items-center gap-2">
                    <!-- Search & Filter Toggle Button -->
                    <button 
                        @click.stop="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-all duration-150 shadow-xs border',
                            isFilterOpen 
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-slate-100/90 border-slate-200 text-slate-700 hover:bg-slate-200/80'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-indigo-600" />
                        <span>البحث والتصفية</span>
                    </button>

                    <!-- Add Invoice Button -->
                    <Link 
                        v-if="$canRoute('sales.invoices.create')"
                        :href="route('sales.invoices.create')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة</span>
                    </Link>
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
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <div class="flex items-center gap-2 text-slate-800 font-bold text-sm">
                            <FunnelIcon class="w-4 h-4 text-indigo-600" />
                            <span>تصفية الفواتير المتقدمة</span>
                        </div>
                        <button @click="isFilterOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Search Keyword -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">رقم الفاتورة أو اسم العميل</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                placeholder="مثال: INV-26-1 أو شركة مدن..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Payment Status -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">حالة الدفع</label>
                            <select 
                                v-model="status" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">جميع الحالات</option>
                                <option value="paid">مدفوعة</option>
                                <option value="unpaid">غير مدفوعة</option>
                                <option value="partially_paid">مدفوعة جزئياً</option>
                            </select>
                        </div>

                        <!-- Date From -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">من تاريخ</label>
                            <input 
                                v-model="dateFrom"
                                type="date" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Date To -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">إلى تاريخ</label>
                            <input 
                                v-model="dateTo"
                                type="date" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button 
                            @click="resetFilters" 
                            class="px-4 py-2 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                        >
                            إعادة ضبط
                        </button>
                        <button 
                            @click="applyFilters" 
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors"
                        >
                            تطبيق التصفية
                        </button>
                    </div>
                </div>
            </transition>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي الفواتير</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_count || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي المبيعات</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ formatCurrency(stats?.total_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">المبالغ المحصلة</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.paid_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">المستحقات المتبقية</p>
                    <p class="text-xl sm:text-2xl font-black text-rose-500">{{ formatCurrency(stats?.unpaid_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Invoices Table Container (Matching live design) -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">الفاتورة</th>
                                <th class="py-4 px-6 text-start">الإجمالي</th>
                                <th class="py-4 px-6 text-start">تاريخ الإصدار</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center w-12">
                                    <span class="sr-only">إجراءات</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                            <tr 
                                v-for="inv in invoices.data" 
                                :key="inv.id"
                                class="hover:bg-slate-50/70 transition-colors group"
                            >
                                <!-- Invoice Info (Number & Customer) -->
                                <td class="py-4 px-6">
                                    <div class="flex flex-col">
                                        <Link 
                                            :href="route('sales.invoices.show', inv.id)"
                                            class="font-black text-slate-800 hover:text-indigo-600 transition-colors inline-block"
                                        >
                                            {{ inv.invoice_number }}
                                        </Link>
                                        <span class="text-xs text-slate-400 font-medium mt-0.5">
                                            {{ inv.customer?.name || inv.customer?.company_name || 'عميل نقدي' }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Total Amount with SAR Symbol -->
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 font-bold text-slate-800">
                                        <span class="text-slate-400 font-semibold text-xs">﷼</span>
                                        <span>{{ formatCurrency(inv.total) }}</span>
                                    </div>
                                </td>

                                <!-- Issue Date -->
                                <td class="py-4 px-6 whitespace-nowrap text-slate-500 font-medium">
                                    {{ formatDateArabic(inv.invoice_date) }}
                                </td>

                                <!-- Status Badge -->
                                <td class="py-4 px-6 text-center whitespace-nowrap">
                                    <span 
                                        v-if="inv.payment_status === 'paid'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/80"
                                    >
                                        مدفوعة
                                    </span>
                                    <span 
                                        v-else-if="inv.payment_status === 'partially_paid'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/80"
                                    >
                                        مدفوعة جزئياً
                                    </span>
                                    <span 
                                        v-else
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200/80"
                                    >
                                        غير مدفوعة
                                    </span>
                                </td>

                                <!-- Actions Dropdown -->
                                <td class="py-4 px-6 text-center relative" @click.stop>
                                    <button 
                                        @click="toggleMenu(inv.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                        title="خيارات"
                                    >
                                        <EllipsisVerticalIcon class="w-5 h-5" />
                                    </button>

                                    <!-- Popover Menu -->
                                    <div 
                                        v-if="activeMenu === inv.id"
                                        class="absolute start-0 sm:start-auto end-6 top-12 w-44 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-30 animate-in fade-in zoom-in-95 duration-100 text-xs font-medium text-slate-700"
                                    >
                                        <Link 
                                            :href="route('sales.invoices.show', inv.id)"
                                            class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-indigo-600 transition-colors"
                                        >
                                            <EyeIcon class="w-4 h-4 text-slate-400" />
                                            <span>عرض الفاتورة</span>
                                        </Link>
                                        <Link 
                                            :href="route('sales.invoices.print', inv.id)"
                                            class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-indigo-600 transition-colors"
                                        >
                                            <PrinterIcon class="w-4 h-4 text-slate-400" />
                                            <span>طباعة الفاتورة</span>
                                        </Link>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!invoices.data || invoices.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <DocumentTextIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد فواتير مطابقة للبحث</p>
                                        <Link 
                                            v-if="$canRoute('sales.invoices.create')"
                                            :href="route('sales.invoices.create')"
                                            class="mt-2 text-xs font-bold text-indigo-600 hover:text-indigo-800"
                                        >
                                            + إنشاء أول فاتورة الآن
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Live-Style Pagination Footer -->
                <div class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ invoices.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="invoices.per_page"
                            @change="router.get(route('sales.invoices.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <!-- Page Navigation -->
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ invoices.current_page }} من {{ invoices.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="invoices.prev_page_url" 
                                :href="invoices.prev_page_url"
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
                                {{ invoices.current_page }}
                            </span>

                            <Link 
                                v-if="invoices.next_page_url" 
                                :href="invoices.next_page_url"
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
