<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    DocumentCheckIcon,
    ClockIcon,
    CheckCircleIcon,
    ArrowRightCircleIcon,
    TruckIcon,
    FunnelIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    orders: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
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
        <Head title="أوامر الشراء" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
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
                        <span class="text-slate-500">المشتريات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">أوامر الشراء</span>
                    </nav>
                </div>

                <!-- Action & Filter Buttons -->
                <div class="flex items-center gap-2">
                    <Link v-if="$can('purchases.orders.create')" :href="route('purchases.orders.create')" class="px-3.5 py-2.5 rounded-xl bg-indigo-600 text-white text-xs font-bold flex items-center gap-2"><PlusIcon class="w-4 h-4" /><span>أمر شراء</span></Link>
                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        :class="[
                            isFilterOpen ? 'bg-indigo-50 border-indigo-200 text-indigo-600' : 'bg-white border-slate-200/80 text-slate-600 hover:bg-slate-50',
                            'px-3.5 py-2.5 rounded-xl border text-xs font-bold transition flex items-center gap-2 shadow-xs'
                        ]"
                    >
                        <FunnelIcon class="w-4 h-4" />
                        <span>تصفية</span>
                    </button>
                </div>
            </div>

            <!-- Collapsible Filter Panel -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div v-show="isFilterOpen" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <!-- Search Query -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">بحث بأمر الشراء أو المورد</label>
                            <div class="relative">
                                <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                                <input 
                                    v-model="search"
                                    @keyup.enter="handleSearch"
                                    type="text" 
                                    placeholder="رقم الأمر، اسم المورد..." 
                                    class="w-full ps-9 pe-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                                />
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">حالة الأمر</label>
                            <select 
                                v-model="status" 
                                @change="handleSearch"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                            >
                                <option value="">كل الحالات</option>
                                <option value="pending">قيد الانتظار</option>
                                <option value="approved">معتمد</option>
                                <option value="converted_to_invoice">تم التحويل لفاتورة</option>
                                <option value="draft">مسودة</option>
                                <option value="pending_approval">بانتظار الاعتماد</option>
                                <option value="partially_received">مستلم جزئياً</option>
                                <option value="received">مستلم</option>
                                <option value="cancelled">ملغى</option>
                            </select>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-end gap-2">
                            <button 
                                @click="handleSearch"
                                class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-xs"
                            >
                                تطبيق
                            </button>
                            <button 
                                @click="resetFilter"
                                class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition"
                                title="إعادة تعيين"
                            >
                                <ArrowPathIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">إجمالي أوامر الشراء</span>
                    <h3 class="text-xl font-black text-slate-900 mt-1">{{ stats?.total_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">الأوامر المعلقة</span>
                    <h3 class="text-xl font-black text-amber-600 mt-1">{{ stats?.pending_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">الأوامر المعتمدة</span>
                    <h3 class="text-xl font-black text-indigo-600 mt-1">{{ stats?.approved_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">إجمالي قيمة الأوامر</span>
                    <h3 class="text-xl font-black text-emerald-600 font-mono mt-1">{{ formatCurrency(stats?.total_orders_value) }} <span class="text-xs font-normal text-slate-400">﷼</span></h3>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6">رقم الأمر</th>
                                <th class="py-4 px-6">المورد</th>
                                <th class="py-4 px-6">تاريخ الطلب</th>
                                <th class="py-4 px-6">التسليم المتوقع</th>
                                <th class="py-4 px-6">القيمة الإجمالية</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="order in orders.data" 
                                :key="order.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ order.order_number }}
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ order.supplier?.name || '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ String(order.order_date).substring(0, 10) }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ order.expected_delivery_date ? String(order.expected_delivery_date).substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900">
                                    {{ formatCurrency(order.total) }} <span class="text-xs font-normal">﷼</span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <StatusBadge :status="order.status" />
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link :href="route('purchases.orders.show', order.id)" class="px-3 py-1 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs me-1">فتح</Link>
                                    <button 
                                        v-if="['pending', 'approved'].includes(order.status) && $can('purchases.orders.convert')"
                                        @click="convertToInvoice(order)"
                                        class="px-3 py-1 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors inline-flex items-center gap-1"
                                    >
                                        <ArrowRightCircleIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                                        <span>تحويل لفاتورة شراء</span>
                                    </button>
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

                <!-- Pagination -->
                <div v-if="orders?.links && orders.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ orders.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ orders.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ orders.total }}</span> أمر
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in orders.links" :key="i">
                            <Link 
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50',
                                    'min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label"
                            />
                            <span 
                                v-else
                                class="min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
