<script setup>
import { taxRate } from '@/utils/tax';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import VatFields from '@/Components/Products/VatFields.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    CubeIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    categories: Array,
    units: Array,
    warehouses: Array,
    suggestedSku: String,
    vatCategories: Object,
    exemptionReasons: Object,
});

const form = useForm({
    name_ar: '',
    name_en: '',
    category_id: props.categories?.[0]?.id || '',
    unit_id: props.units?.[0]?.id || '',
    sku: props.suggestedSku || '',
    barcode: '',
    purchase_price: 0,
    selling_price: 0,
    tax_rate: taxRate(),
    vat_category: 'S',
    vat_exemption_code: '',
    min_stock_alert: 5,
    track_inventory: true,
    type: 'product',
    initial_stock: 0,
    warehouse_id: props.warehouses?.[0]?.id || '',
    description: '',
});

const submit = () => {
    form.post(route('inventory.products.store'));
};
</script>

<template>
    <AppLayout>
        <Head title="إضافة منتج أو خدمة جديدة" />

        <div class="space-y-6 max-w-4xl mx-auto">
            <!-- Header Ribbon -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <Link :href="route('inventory.products.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">المنتجات</Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">إضافة منتج</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('inventory.products.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50"
                    >
                        إلغاء
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>حفظ المنتج</span>
                    </button>
                </div>
            </div>

            <!-- Form Card -->
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs space-y-6">
                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Type Selector -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">نوع البند</label>
                        <div class="grid grid-cols-2 gap-3 max-w-sm">
                            <label 
                                :class="[
                                    'flex items-center justify-center p-3 rounded-xl border cursor-pointer font-bold text-xs transition-all',
                                    form.type === 'product' 
                                        ? 'bg-indigo-50 border-indigo-500 text-indigo-700' 
                                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                ]"
                            >
                                <input type="radio" v-model="form.type" value="product" class="sr-only" />
                                <span>منتج ملموس (له مخزون)</span>
                            </label>
                            <label 
                                :class="[
                                    'flex items-center justify-center p-3 rounded-xl border cursor-pointer font-bold text-xs transition-all',
                                    form.type === 'service' 
                                        ? 'bg-indigo-50 border-indigo-500 text-indigo-700' 
                                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                ]"
                            >
                                <input type="radio" v-model="form.type" value="service" class="sr-only" />
                                <span>خدمة أو مقاولة (بدون مخزون)</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name AR -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم المنتج بالعربية <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar"
                                type="text" 
                                required
                                placeholder="مثال: لابتوب ديل انسبيرون..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            />
                        </div>

                        <!-- Name EN -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الاسم بالإنجليزية</label>
                            <input 
                                v-model="form.name_en"
                                type="text" 
                                placeholder="Dell Inspiron Laptop..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            />
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">التصنيف</label>
                            <select 
                                v-model="form.category_id"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            >
                                <option value="">بدون تصنيف</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                    {{ cat.name_ar }}
                                </option>
                            </select>
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">وحدة القياس</label>
                            <select 
                                v-model="form.unit_id"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            >
                                <option v-for="u in units" :key="u.id" :value="u.id">
                                    {{ u.name_ar }}
                                </option>
                            </select>
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">رمز المنتج (SKU)</label>
                            <input 
                                v-model="form.sku"
                                type="text" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Barcode -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الباركود (Barcode / EAN)</label>
                            <input 
                                v-model="form.barcode"
                                type="text" 
                                placeholder="امسح الباركود هنا..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Purchase Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">سعر الشراء (التكلفة) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.purchase_price"
                                type="number" 
                                step="0.01" 
                                min="0"
                                required
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold"
                            />
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">سعر البيع (قبل الضريبة) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.selling_price"
                                type="number" 
                                step="0.01" 
                                min="0"
                                required
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold text-indigo-700"
                            />
                        </div>

                        <!-- Initial Stock (if product) -->
                        <div v-if="form.type === 'product'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي للمخزون</label>
                            <input 
                                v-model="form.initial_stock"
                                type="number" 
                                step="1" 
                                min="0"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold"
                            />
                        </div>

                        <!-- Warehouse (if product) -->
                        <div v-if="form.type === 'product'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">المستودع</label>
                            <select 
                                v-model="form.warehouse_id"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            >
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">
                                    {{ w.name_ar }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <VatFields v-model:category="form.vat_category" v-model:code="form.vat_exemption_code" v-model:tax-rate="form.tax_rate" :categories="vatCategories" :reasons="exemptionReasons" :errors="form.errors" />

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الوصف أو الملاحظات</label>
                        <textarea 
                            v-model="form.description"
                            rows="2"
                            class="w-full text-xs rounded-xl border border-slate-200 p-3 focus:border-indigo-500"
                        ></textarea>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                        <Link 
                            :href="route('inventory.products.index')"
                            class="px-5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            إلغاء
                        </Link>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                        >
                            {{ form.processing ? 'جاري الحفظ...' : 'حفظ المنتج' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
