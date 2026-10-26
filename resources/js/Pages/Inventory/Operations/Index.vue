<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ArrowsRightLeftIcon,
    AdjustmentsHorizontalIcon,
    BuildingStorefrontIcon,
    CubeIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    stocks: Object,
    products: Array,
    warehouses: Array,
    stats: Object,
});

const isTransferModalOpen = ref(false);
const isAdjustModalOpen = ref(false);

const transferForm = useForm({
    product_id: '',
    from_warehouse_id: '',
    to_warehouse_id: '',
    quantity: 1,
    notes: '',
});

const adjustForm = useForm({
    product_id: '',
    warehouse_id: '',
    type: 'add',
    quantity: 1,
    reason: '',
});

const openTransferModal = () => {
    transferForm.reset();
    transferForm.product_id = props.products?.[0]?.id || '';
    transferForm.from_warehouse_id = props.warehouses?.[0]?.id || '';
    transferForm.to_warehouse_id = props.warehouses?.[1]?.id || '';
    isTransferModalOpen.value = true;
};

const openAdjustModal = (stock = null) => {
    adjustForm.reset();
    if (stock) {
        adjustForm.product_id = stock.product_id;
        adjustForm.warehouse_id = stock.warehouse_id;
    } else {
        adjustForm.product_id = props.products?.[0]?.id || '';
        adjustForm.warehouse_id = props.warehouses?.[0]?.id || '';
    }
    isAdjustModalOpen.value = true;
};

const submitTransfer = () => {
    transferForm.post(route('inventory.operations.transfer'), {
        onSuccess: () => {
            isTransferModalOpen.value = false;
            transferForm.reset();
        }
    });
};

const submitAdjust = () => {
    adjustForm.post(route('inventory.operations.adjust'), {
        onSuccess: () => {
            isAdjustModalOpen.value = false;
            adjustForm.reset();
        }
    });
};
</script>

<template>
    <AppLayout>
        <Head title="عمليات وحركات المخزون" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('inventory.operations.index')"
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
                        <span class="text-slate-500">المخزون</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">حركات المخزون</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="() => openAdjustModal()"
                        class="px-4 py-2.5 bg-white border border-slate-200/80 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-bold shadow-xs transition-all flex items-center gap-1.5"
                    >
                        <AdjustmentsHorizontalIcon class="w-4 h-4 text-slate-600" />
                        <span>تسوية / جرد سريع</span>
                    </button>
                    <button 
                        @click="openTransferModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <ArrowsRightLeftIcon class="w-4 h-4 stroke-2" />
                        <span>تحويل بين المستودعات</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">إجمالي الوحدات بالمخازن</p>
                        <h3 class="text-xl font-black text-slate-900">{{ stats?.total_units ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">المستودعات الفعالة</p>
                        <h3 class="text-xl font-black text-emerald-600">{{ stats?.warehouses_count ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">الأصناف الموزعة</p>
                        <h3 class="text-xl font-black text-slate-800">{{ stats?.stocked_products_count ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Stock Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="px-6 py-4">المنتج</th>
                                <th class="px-6 py-4">المستودع</th>
                                <th class="px-6 py-4 text-center">الكمية المتوفرة</th>
                                <th class="px-6 py-4 text-center">آخر تحديث</th>
                                <th class="px-6 py-4 text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="stock in stocks?.data || []" 
                                :key="stock.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                            <CubeIcon class="w-4 h-4" />
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900">{{ stock.product?.name_ar }}</p>
                                            <p class="text-[10px] text-slate-400 font-mono">{{ stock.product?.sku }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-slate-700">
                                        <BuildingStorefrontIcon class="w-3.5 h-3.5 text-slate-400" />
                                        <span>{{ stock.warehouse?.name_ar }}</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span 
                                        :class="stock.quantity > 5 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'"
                                        class="font-mono font-bold px-3 py-1 rounded-full text-xs border"
                                    >
                                        {{ stock.quantity }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center text-slate-400 font-mono text-[11px]" dir="ltr">
                                    {{ new Date(stock.updated_at).toLocaleDateString('ar-SA') }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button 
                                        @click="openAdjustModal(stock)"
                                        class="px-2.5 py-1 text-slate-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg text-xs font-bold transition inline-flex items-center gap-1"
                                    >
                                        <AdjustmentsHorizontalIcon class="w-3.5 h-3.5" />
                                        <span>تسوية</span>
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="!stocks?.data || stocks.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <CubeIcon class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <span>لا توجد أرصدة مسجلة في المستودعات حالياً</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="stocks?.links && stocks.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ stocks.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ stocks.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ stocks.total }}</span> صنف
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in stocks.links" :key="i">
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

        <!-- Transfer Modal -->
        <div v-if="isTransferModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">تحويل مخزون بين المستودعات</h3>
                    <button @click="isTransferModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitTransfer" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">اختر المنتج *</label>
                        <select 
                            v-model="transferForm.product_id"
                            required
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        >
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name_ar }} ({{ p.sku }})</option>
                        </select>
                        <p v-if="transferForm.errors.product_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.product_id }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">من مستودع *</label>
                            <select 
                                v-model="transferForm.from_warehouse_id"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            >
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option>
                            </select>
                            <p v-if="transferForm.errors.from_warehouse_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.from_warehouse_id }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">إلى مستودع *</label>
                            <select 
                                v-model="transferForm.to_warehouse_id"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            >
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option>
                            </select>
                            <p v-if="transferForm.errors.to_warehouse_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.to_warehouse_id }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الكمية المحولة *</label>
                        <input 
                            v-model="transferForm.quantity" 
                            type="number" 
                            step="0.01"
                            min="0.01"
                            required
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                        />
                        <p v-if="transferForm.errors.quantity" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.quantity }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات التحويل</label>
                        <input 
                            v-model="transferForm.notes" 
                            type="text" 
                            placeholder="سبب التحويل أو رقم الإذن"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        />
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isTransferModalOpen = false"
                            class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="transferForm.processing"
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition disabled:opacity-50"
                        >
                            تنفيذ التحويل
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Adjust Modal -->
        <div v-if="isAdjustModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">تسوية كمية مخزون (جرد يدوي)</h3>
                    <button @click="isAdjustModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitAdjust" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المنتج *</label>
                        <select 
                            v-model="adjustForm.product_id"
                            required
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        >
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name_ar }} ({{ p.sku }})</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المستودع *</label>
                        <select 
                            v-model="adjustForm.warehouse_id"
                            required
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        >
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">نوع العملية *</label>
                            <select 
                                v-model="adjustForm.type"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                            >
                                <option value="add">إضافة كمية (+)</option>
                                <option value="subtract">خصم كمية (-)</option>
                                <option value="set">تعيين الرصيد الفعلي (=)</option>
                                <option value="damage">إتلاف / تالف (-)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الكمية *</label>
                            <input 
                                v-model="adjustForm.quantity" 
                                type="number" 
                                step="0.01"
                                min="0"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">السبب / المبرر</label>
                        <input 
                            v-model="adjustForm.reason" 
                            type="text" 
                            placeholder="تالف، زيادة جردية، عينة..."
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        />
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isAdjustModalOpen = false"
                            class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="adjustForm.processing"
                            class="px-5 py-2 text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition disabled:opacity-50"
                        >
                            تحديث المخزون
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
