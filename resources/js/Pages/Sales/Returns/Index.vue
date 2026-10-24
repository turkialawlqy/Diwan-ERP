<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    ArrowUturnLeftIcon,
    ArrowPathIcon,
    PlusIcon,
    EyeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    returns: Object,
    filters: Object,
    stats: Object,
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="مرتجعات المبيعات والإشعارات الدائنة" />

        <div class="space-y-5">
            <!-- Breadcrumbs Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-slate-500">المبيعات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المرتجعات والإشعارات الدائنة</span>
                    </div>

                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <Link 
                        v-if="$canRoute('sales.returns.create')"
                        :href="route('sales.returns.create')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-bold text-xs sm:text-sm bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إصدار إشعار دائن جديد</span>
                    </Link>
                </div>
            </div>

            <!-- Summary KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي الإشعارات الدائنة</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_returns || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي المبالغ المستردة</p>
                    <p class="text-xl sm:text-2xl font-black text-rose-600">{{ formatCurrency(stats?.total_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Returns Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">رقم الإشعار الدائن</th>
                                <th class="py-4 px-6 text-start">العميل</th>
                                <th class="py-4 px-6 text-start">الفاتورة الأصلية</th>
                                <th class="py-4 px-6 text-start">المبلغ المسترد</th>
                                <th class="py-4 px-6 text-start">التاريخ</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center w-12"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in returns.data" :key="r.id" class="hover:bg-slate-50/70">
                                <td class="py-4 px-6 font-bold text-slate-800 font-mono">
                                    <Link :href="route('sales.returns.show', r.id)" class="hover:text-indigo-600 transition-colors">
                                        {{ r.credit_note_number }}
                                    </Link>
                                </td>
                                <td class="py-4 px-6 font-semibold text-slate-700">{{ r.customer?.name || '-' }}</td>
                                <td class="py-4 px-6 font-mono text-indigo-600 font-bold">
                                    <Link v-if="r.invoice_id" :href="route('sales.invoices.show', r.invoice_id)" class="hover:underline">
                                        {{ r.invoice?.invoice_number || '-' }}
                                    </Link>
                                    <span v-else class="text-slate-400">-</span>
                                </td>
                                <td class="py-4 px-6 font-black text-rose-600 font-mono">{{ formatCurrency(r.total) }} ﷼</td>
                                <td class="py-4 px-6 text-slate-500">{{ r.credit_note_date }}</td>
                                <td class="py-4 px-6 text-center">
                                    <span 
                                        v-if="r.status === 'refunded'"
                                        class="inline-block px-2.5 py-0.5 rounded-full text-2xs font-bold bg-purple-50 text-purple-700 border border-purple-200"
                                    >
                                        مسترد نقداً
                                    </span>
                                    <span 
                                        v-else-if="r.status === 'approved'"
                                        class="inline-block px-2.5 py-0.5 rounded-full text-2xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"
                                    >
                                        معتمد بالحساب
                                    </span>
                                    <span 
                                        v-else
                                        class="inline-block px-2.5 py-0.5 rounded-full text-2xs font-bold bg-slate-100 text-slate-600"
                                    >
                                        مسودة
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link 
                                        :href="route('sales.returns.show', r.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-slate-100 inline-block transition-colors"
                                        title="عرض الإشعار والطباعة"
                                    >
                                        <EyeIcon class="w-4 h-4" />
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!returns.data || returns.data.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <ArrowUturnLeftIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد مرتجعات مسجلة</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="returns.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ returns.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="returns.per_page"
                            @change="router.get(route('sales.returns.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ returns.current_page }} من {{ returns.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="returns.prev_page_url" 
                                :href="returns.prev_page_url"
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
                                {{ returns.current_page }}
                            </span>
                            <Link 
                                v-if="returns.next_page_url" 
                                :href="returns.next_page_url"
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
