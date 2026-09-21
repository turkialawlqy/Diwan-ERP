<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    ArrowUturnLeftIcon,
    ArrowPathIcon,
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
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-slate-500">المبيعات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المرتجعات</span>
                    </div>

                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Returns Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 font-bold">
                                <th class="py-4 px-6">رقم الإشعار الدائن</th>
                                <th class="py-4 px-6">العميل</th>
                                <th class="py-4 px-6">الفاتورة الأصلية</th>
                                <th class="py-4 px-6">المبلغ المسترد</th>
                                <th class="py-4 px-6">التاريخ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in returns.data" :key="r.id" class="hover:bg-slate-50/70">
                                <td class="py-4 px-6 font-bold text-slate-800 font-mono">{{ r.credit_note_number }}</td>
                                <td class="py-4 px-6 font-semibold text-slate-700">{{ r.customer?.name || '-' }}</td>
                                <td class="py-4 px-6 font-mono text-indigo-600 font-bold">{{ r.invoice?.invoice_number || '-' }}</td>
                                <td class="py-4 px-6 font-black text-rose-600 font-mono">{{ formatCurrency(r.total) }} ﷼</td>
                                <td class="py-4 px-6 text-slate-500">{{ r.credit_note_date }}</td>
                            </tr>
                            <tr v-if="!returns.data || returns.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <ArrowUturnLeftIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد مرتجعات مسجلة</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
