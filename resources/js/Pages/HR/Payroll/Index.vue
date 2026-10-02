<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BanknotesIcon,
    XMarkIcon,
    CheckBadgeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    payrolls: Object,
    stats: Object,
    employees: Array,
});

const isAddModalOpen = ref(false);

const form = useForm({
    month: new Date().getMonth() + 1,
    year: new Date().getFullYear(),
    notes: '',
});

const openAddModal = () => {
    form.reset();
    form.month = new Date().getMonth() + 1;
    form.year = new Date().getFullYear();
    isAddModalOpen.value = true;
};

const submitPayroll = () => {
    form.post(route('hr.payroll.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const months = [
    { num: 1, name: 'يناير' },
    { num: 2, name: 'فبراير' },
    { num: 3, name: 'مارس' },
    { num: 4, name: 'أبريل' },
    { num: 5, name: 'مايو' },
    { num: 6, name: 'يونيو' },
    { num: 7, name: 'يوليو' },
    { num: 8, name: 'أغسطس' },
    { num: 9, name: 'سبتمبر' },
    { num: 10, name: 'أكتوبر' },
    { num: 11, name: 'نوفمبر' },
    { num: 12, name: 'ديسمبر' },
];
</script>

<template>
    <AppLayout>
        <Head title="مسيرات الرواتب - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('hr.payroll.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">الموارد البشرية</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">مسيرات الرواتب</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>توليد مسير رواتب جديد</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">إجمالي مسيرات الرواتب</span>
                        <h3 class="text-lg font-black text-slate-900">{{ stats?.total_payrolls_count || 0 }} مسير</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">إجمالي المبالغ المنصرفة</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_paid_amount) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <CheckBadgeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">مسير رواتب الشهر الحالي</span>
                        <h3 class="text-lg font-black font-mono text-indigo-600">
                            {{ formatCurrency(stats?.current_month_payroll) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Payrolls Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">رقم المسير</th>
                                <th class="py-4 px-6 text-start">الشهر / السنة</th>
                                <th class="py-4 px-6 text-start">عدد الموظفين</th>
                                <th class="py-4 px-6 text-end">إجمالي الأساسي</th>
                                <th class="py-4 px-6 text-end">إجمالي البدلات</th>
                                <th class="py-4 px-6 text-end">صافي المسير</th>
                                <th class="py-4 px-6 text-start">تاريخ التحويل</th>
                                <th class="py-4 px-6 text-center">نظام حماية الأجور WPS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="pay in payrolls.data" :key="pay.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ pay.payroll_number }}
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ months.find(m => m.num === pay.month)?.name || pay.month }} {{ pay.year }}
                                </td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ pay.items?.length || 0 }} موظف
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(pay.total_basic) }} ﷼
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(pay.total_allowances) }} ﷼
                                </td>
                                <td class="py-4 px-6 font-mono font-black text-indigo-600 text-end">
                                    {{ formatCurrency(pay.net_total) }} ﷼
                                </td>
                                <td class="py-4 px-6 font-mono text-3xs text-slate-500">
                                    {{ pay.payment_date ? pay.payment_date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-3xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        معتمد للصرف
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!payrolls.data || payrolls.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا توجد مسيرات رواتب مسجلة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="payrolls?.links && payrolls.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ payrolls.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ payrolls.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ payrolls.total }}</span> مسير
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in payrolls.links" :key="i">
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

            <!-- Generate Payroll Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BanknotesIcon class="w-5 h-5 text-indigo-600" />
                            <span>توليد مسير رواتب جديد لجميع الموظفين النشطين</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitPayroll" class="space-y-4 text-xs">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الشهر <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.month" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option v-for="m in months" :key="m.num" :value="m.num">{{ m.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">السنة <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.year" 
                                    type="number" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" 
                                />
                            </div>
                        </div>

                        <div class="p-3 bg-indigo-50/50 rounded-xl border border-indigo-100 text-3xs text-indigo-900 space-y-1">
                            <div class="flex items-center justify-between font-bold">
                                <span>عدد الموظفين المشمولين في المسير:</span>
                                <span>{{ employees.length }} موظف نشط</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>إجمالي صافي الرواتب المستحقة:</span>
                                <span class="font-mono font-bold">{{ formatCurrency(employees.reduce((acc, e) => acc + parseFloat(e.total_salary), 0)) }} ﷼</span>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">ملاحظات المسير</label>
                            <textarea 
                                v-model="form.notes" 
                                rows="2" 
                                placeholder="أي ملاحظات إدارية عن هذا المسير..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                            ></textarea>
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
                                توليد واعتماد المسير
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
