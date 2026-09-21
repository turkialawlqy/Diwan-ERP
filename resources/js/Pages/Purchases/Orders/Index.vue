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
    DocumentCheckIcon,
    ClockIcon,
    CheckCircleIcon,
    ArrowRightCircleIcon,
    TruckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    orders: Object,
    filters: Object,
    stats: Object,
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');

const handleSearch = () => {
    router.get(route('purchases.orders.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilter = () => {
    search.value = '';
    status.value = '';
    handleSearch();
};

const convertToInvoice = (order) => {
    if (confirm(`هل أنت متأكد من تحويل أمر الشراء رقم "${order.order_number}" إلى فاتورة شراء رسمية وتوريد البضاعة للمخزون؟`)) {
        router.post(route('purchases.orders.convert', order.id));
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
        <Head title="أوامر الشراء - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-black text-slate-800 flex items-center gap-2">
                        <DocumentCheckIcon class="w-6 h-6 text-indigo-600" />
                        <span>أوامر الشراء (Purchase Orders)</span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        إصدار ومتابعة طلبات الشراء الصادرة للموردين والتحويل المباشر لفواتير شراء
                    </p>
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

                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-slate-400">المشتريات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">أوامر الشراء</span>
                    </div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">إجمالي أوامر الشراء</p>
                    <h3 class="text-lg font-black text-slate-900 mt-0.5">{{ stats?.total_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">الأوامر المعلقة</p>
                    <h3 class="text-lg font-black text-amber-600 mt-0.5">{{ stats?.pending_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">الأوامر المعتمدة</p>
                    <h3 class="text-lg font-black text-indigo-600 mt-0.5">{{ stats?.approved_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-2xs font-bold text-slate-400">إجمالي قيمة الأوامر</p>
                    <h3 class="text-lg font-black text-emerald-600 font-mono mt-0.5">{{ formatCurrency(stats?.total_orders_value) }} ﷼</h3>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200/80 text-slate-500 font-bold">
                                <th class="py-3 px-4">رقم الأمر</th>
                                <th class="py-3 px-4">المورد</th>
                                <th class="py-3 px-4">تاريخ الطلب</th>
                                <th class="py-3 px-4">التسليم المتوقع</th>
                                <th class="py-3 px-4">القيمة الإجمالية</th>
                                <th class="py-3 px-4 text-center">الحالة</th>
                                <th class="py-3 px-4 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="order in orders.data" 
                                :key="order.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600">
                                    {{ order.order_number }}
                                </td>
                                <td class="py-3.5 px-4 font-bold text-slate-900">
                                    {{ order.supplier?.name || '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    {{ String(order.order_date).substring(0, 10) }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    {{ order.expected_delivery_date ? String(order.expected_delivery_date).substring(0, 10) : '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-900">
                                    {{ formatCurrency(order.total) }} <span class="text-3xs font-normal">﷼</span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span 
                                        v-if="order.status === 'converted_to_invoice'"
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-purple-50 text-purple-700 border border-purple-200/60"
                                    >
                                        <CheckCircleIcon class="w-3.5 h-3.5" />
                                        <span>تم التحويل لفاتورة</span>
                                    </span>
                                    <span 
                                        v-else-if="order.status === 'approved'"
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200/60"
                                    >
                                        <CheckCircleIcon class="w-3.5 h-3.5" />
                                        <span>معتمد</span>
                                    </span>
                                    <span 
                                        v-else
                                        class="px-2.5 py-0.5 rounded-full text-3xs font-bold inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200/60"
                                    >
                                        <ClockIcon class="w-3.5 h-3.5" />
                                        <span>قيد الانتظار</span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <button 
                                        v-if="order.status !== 'converted_to_invoice'"
                                        @click="convertToInvoice(order)"
                                        class="px-3 py-1 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-3xs transition-colors inline-flex items-center gap-1"
                                    >
                                        <ArrowRightCircleIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                                        <span>تحويل لفاتورة شراء</span>
                                    </button>
                                    <span v-else class="text-3xs text-slate-400">
                                        مكتمل
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!orders.data || orders.data.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    لا توجد أوامر شراء مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
