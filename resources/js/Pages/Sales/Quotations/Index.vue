<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
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
    ArrowRightCircleIcon,
    ChevronLeftIcon,
    DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    quotations: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const activeMenu = ref(null);

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const closeMenu = () => {
    activeMenu.value = null;
};

const convertToInvoice = (quotation) => {
    if (confirm(`هل ترغب في تحويل عرض السعر ${quotation.quotation_number} إلى فاتورة ضريبية رسمية؟`)) {
        router.post(route('sales.quotations.convert', quotation.id));
    }
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
        <Head title="عروض الأسعار" />

        <div class="space-y-5" @click="closeMenu">
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
                        <span class="text-indigo-600 font-bold">عروض الأسعار</span>
                    </div>

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

                    <ImportButton v-if="$can('sales.quotations.create')" type="quotations" title="استيراد عروض أسعار من Excel" hint="سطر لكل بند؛ الأسطر التي تحمل نفس quotation_ref تكوّن عرضاً واحداً. العميل برمزه (customer_code) أو اسمه. تُنشأ العروض كمسودات." />
                    <Link 
                        v-if="$canRoute('sales.quotations.create')"
                        :href="route('sales.quotations.create')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-bold text-xs sm:text-sm bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إنشاء عرض سعر</span>
                    </Link>
                </div>
            </div>

            <!-- Mini Summary Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي عروض الأسعار</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">العروض المقبولة</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.accepted || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">عروض قيد الانتظار</p>
                    <p class="text-xl sm:text-2xl font-black text-amber-500">{{ stats?.pending || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي القيمة التقديرية</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ formatCurrency(stats?.total_value) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Quotations Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">عرض السعر</th>
                                <th class="py-4 px-6 text-start">الإجمالي</th>
                                <th class="py-4 px-6 text-start">تاريخ العرض</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center w-12"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                            <tr 
                                v-for="q in quotations.data" 
                                :key="q.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="py-4 px-6">
                                    <div class="flex flex-col">
                                        <span class="font-black text-slate-800">
                                            {{ q.quotation_number }}
                                        </span>
                                        <span class="text-xs text-slate-400 font-medium mt-0.5">
                                            {{ q.customer?.name || 'عميل' }}
                                        </span>
                                    </div>
                                </td>

                                <td class="py-4 px-6 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 font-bold text-slate-800">
                                        <span class="text-slate-400 font-semibold text-xs">﷼</span>
                                        <span>{{ formatCurrency(q.total) }}</span>
                                    </div>
                                </td>

                                <td class="py-4 px-6 whitespace-nowrap text-slate-500 font-medium">
                                    {{ formatDateArabic(q.quotation_date) }}
                                </td>

                                <td class="py-4 px-6 text-center whitespace-nowrap">
                                    <span 
                                        v-if="q.status === 'converted'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200"
                                    >
                                        تم التحويل لفاتورة
                                    </span>
                                    <span 
                                        v-else-if="q.status === 'accepted'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"
                                    >
                                        مقبول
                                    </span>
                                    <span 
                                        v-else
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200"
                                    >
                                        مسودة / قيد المراجعة
                                    </span>
                                </td>

                                <td class="py-4 px-6 text-center relative" @click.stop>
                                    <button 
                                        @click="toggleMenu(q.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                    >
                                        <EllipsisVerticalIcon class="w-5 h-5" />
                                    </button>

                                    <div 
                                        v-if="activeMenu === q.id"
                                        class="absolute start-0 sm:start-auto end-6 top-12 w-48 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-30 text-xs font-medium text-slate-700"
                                    >
                                        <Link 
                                            :href="route('sales.quotations.show', q.id)"
                                            class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 transition-colors text-start text-slate-700 font-bold"
                                        >
                                            <EyeIcon class="w-4 h-4 text-slate-400" />
                                            <span>عرض العرض والطباعة</span>
                                        </Link>

                                        <button 
                                            v-if="q.status !== 'converted'"
                                            @click="convertToInvoice(q); closeMenu()"
                                            class="w-full flex items-center gap-2 px-4 py-2 hover:bg-indigo-50 hover:text-indigo-600 transition-colors text-start font-bold text-indigo-700"
                                        >
                                            <ArrowRightCircleIcon class="w-4 h-4" />
                                            <span>تحويل إلى فاتورة مبيعات</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!quotations.data || quotations.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <DocumentDuplicateIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد عروض أسعار مسجلة</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="quotations.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ quotations.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="quotations.per_page"
                            @change="router.get(route('sales.quotations.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ quotations.current_page }} من {{ quotations.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="quotations.prev_page_url" 
                                :href="quotations.prev_page_url"
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
                                {{ quotations.current_page }}
                            </span>
                            <Link 
                                v-if="quotations.next_page_url" 
                                :href="quotations.next_page_url"
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
