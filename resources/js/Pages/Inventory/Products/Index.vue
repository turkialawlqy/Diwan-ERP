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
    PencilSquareIcon,
    TrashIcon,
    XMarkIcon,
    ChevronLeftIcon,
    CubeIcon,
    TagIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Object,
    categories: Array,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const categoryId = ref(props.filters?.category_id || '');
const activeMenu = ref(null);

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const closeMenu = () => {
    activeMenu.value = null;
};

const applyFilters = () => {
    router.get(route('inventory.products.index'), {
        search: search.value || undefined,
        category_id: categoryId.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilters = () => {
    search.value = '';
    categoryId.value = '';
    router.get(route('inventory.products.index'), {}, { preserveState: true, replace: true });
};

const deleteProduct = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف المنتج "${name}"؟`)) {
        router.delete(route('inventory.products.destroy', id), {
            onSuccess: () => closeMenu(),
        });
    }
};

const formatCurrency = (val) => {
    if (!val || Number(val) === 0) return '-';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ﷼';
};
</script>

<template>
    <AppLayout>
        <Head title="دليل المنتجات والمخزون" />

        <div class="space-y-5" @click="closeMenu">
            <!-- Top Breadcrumbs & Action Bar (Exact match to live screenshot) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs Ribbon -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المنتجات</span>
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

                <!-- Action Buttons -->
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

                    <!-- Add Product Button -->
                    <Link 
                        :href="route('inventory.products.create')"
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
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Search Keyword -->
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">اسم المنتج، الرمز (SKU)، أو الباركود</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                placeholder="بحث عن منتج أو خدمة..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Category Filter -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">التصنيف</label>
                            <select 
                                v-model="categoryId" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">جميع التصنيفات</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                    {{ cat.name_ar }}
                                </option>
                            </select>
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

            <!-- Products Table Container (Matches inventory_products_page_1789920455492.png) -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">الاسم</th>
                                <th class="py-4 px-6 text-start">المخزون</th>
                                <th class="py-4 px-6 text-start">سعر الشراء</th>
                                <th class="py-4 px-6 text-start">سعر البيع</th>
                                <th class="py-4 px-6 text-center w-12">
                                    <span class="sr-only">إجراءات</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                            <tr 
                                v-for="p in products.data" 
                                :key="p.id"
                                class="hover:bg-slate-50/70 transition-colors group"
                            >
                                <!-- Name & Code -->
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-2">
                                        <Link 
                                            :href="route('inventory.products.edit', p.id)"
                                            class="font-bold text-slate-800 hover:text-indigo-600 transition-colors"
                                        >
                                            {{ p.name_ar }}
                                        </Link>
                                        <span class="text-xs font-mono text-slate-400">
                                            #1-{{ String(p.id).padStart(6, '0') }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 text-2xs text-slate-400 mt-1">
                                        <span v-if="p.category" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">
                                            {{ p.category.name_ar }}
                                        </span>
                                        <span v-if="p.sku" class="font-mono">
                                            SKU: {{ p.sku }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Stock -->
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <div v-if="p.type === 'service'">
                                        <span class="text-slate-400 font-medium">-</span>
                                    </div>
                                    <div v-else class="flex items-center gap-1.5 font-bold font-mono">
                                        <span :class="p.total_stock <= 5 ? 'text-amber-600' : 'text-slate-700'">
                                            {{ Number(p.total_stock) }}
                                        </span>
                                        <span class="text-xs font-normal text-slate-400">
                                            {{ p.unit?.name_ar || 'حبة' }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Purchase Price -->
                                <td class="py-4 px-6 whitespace-nowrap text-slate-600 font-bold font-mono">
                                    {{ formatCurrency(p.purchase_price) }}
                                </td>

                                <!-- Selling Price -->
                                <td class="py-4 px-6 whitespace-nowrap text-indigo-700 font-black font-mono">
                                    {{ formatCurrency(p.selling_price) }}
                                </td>

                                <!-- Actions Dropdown -->
                                <td class="py-4 px-6 text-center relative" @click.stop>
                                    <button 
                                        @click="toggleMenu(p.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                        title="خيارات"
                                    >
                                        <EllipsisVerticalIcon class="w-5 h-5" />
                                    </button>

                                    <!-- Popover Menu -->
                                    <div 
                                        v-if="activeMenu === p.id"
                                        class="absolute start-0 sm:start-auto end-6 top-12 w-44 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-30 animate-in fade-in zoom-in-95 duration-100 text-xs font-medium text-slate-700"
                                    >
                                        <Link 
                                            :href="route('inventory.products.edit', p.id)"
                                            class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-indigo-600 transition-colors text-start"
                                        >
                                            <PencilSquareIcon class="w-4 h-4 text-slate-400" />
                                            <span>تعديل المنتج</span>
                                        </Link>
                                        <div class="h-px bg-slate-100 my-1"></div>
                                        <button 
                                            @click="deleteProduct(p.id, p.name_ar)"
                                            class="w-full flex items-center gap-2 px-4 py-2 text-rose-600 hover:bg-rose-50 transition-colors text-start"
                                        >
                                            <TrashIcon class="w-4 h-4 text-rose-500" />
                                            <span>حذف المنتج</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!products.data || products.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <CubeIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">لا توجد منتجات مطابقة</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Live-Style Pagination Footer -->
                <div class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ products.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="products.per_page"
                            @change="router.get(route('inventory.products.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <!-- Page Navigation -->
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ products.current_page }} من {{ products.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="products.prev_page_url" 
                                :href="products.prev_page_url"
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
                                {{ products.current_page }}
                            </span>

                            <Link 
                                v-if="products.next_page_url" 
                                :href="products.next_page_url"
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
