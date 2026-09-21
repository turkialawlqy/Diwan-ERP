<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    BanknotesIcon,
    ArrowPathIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    payments: Object,
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
        <Head title="سندات القبض والدفعات" />

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
                        <span class="text-indigo-600 font-bold">المدفوعات</span>
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

            <!-- Payments Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 font-bold">
                                <th class="py-4 px-6">رقم السند</th>
                                <th class="py-4 px-6">العميل</th>
                                <th class="py-4 px-6">المبلغ</th>
                                <th class="py-4 px-6">طريقة الدفع</th>
                                <th class="py-4 px-6">تاريخ السداد</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in payments.data" :key="p.id" class="hover:bg-slate-50/70">
                                <td class="py-4 px-6 font-bold text-slate-800 font-mono">{{ p.payment_number }}</td>
                                <td class="py-4 px-6 font-semibold text-slate-700">{{ p.customer?.name || '-' }}</td>
                                <td class="py-4 px-6 font-black text-emerald-600 font-mono">{{ formatCurrency(p.amount) }} ﷼</td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ p.payment_method === 'cash' ? 'نقداً' : p.payment_method === 'card' ? 'بطاقة / مدى' : 'تحويل بنكي' }}
                                </td>
                                <td class="py-4 px-6 text-slate-500">{{ p.payment_date }}</td>
                            </tr>
                            <tr v-if="!payments.data || payments.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <BanknotesIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد سندات قبض مسجلة حالياً</p>
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
