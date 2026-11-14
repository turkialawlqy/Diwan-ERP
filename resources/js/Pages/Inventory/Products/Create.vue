<script setup>
import ProductImageField from '@/Components/Products/ProductImageField.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
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
    image: null,
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
useUnsavedGuard(form);

const submit = () => {
    form.post(route('inventory.products.store'));
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_products_create.add_a_new_product_or_service')" />

        <div class="space-y-6">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('i_products_create.add_product')" :crumbs="[{ label: 'إضافة منتج' }]">
<template #actions>
<Link 
                        :href="route('inventory.products.index')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50"
                    >
                        {{ $t('i_products_create.cancel') }}
                    </Link>
                    <button 
                        @click="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <CheckIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('i_products_create.save_product') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- Form Card -->
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs space-y-6">
                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">{{ $t('i_products_create.item_image_shown_at_the_cashier') }}</label>
                        <ProductImageField v-model="form.image" :error="form.errors.image" />
                    </div>
                    <!-- Type Selector -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">{{ $t('i_products_create.item_type') }}</label>
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
                                <span>{{ $t('i_products_create.physical_product_has_stock') }}</span>
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
                                <span>{{ $t('i_products_create.service_or_contracting_no_stock') }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name AR -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.product_name_in_arabic') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar"
                                type="text" 
                                required
                                :placeholder="$t('i_products_create.example_dell_inspiron_laptop')"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            />
                        </div>

                        <!-- Name EN -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.name_in_english') }}</label>
                            <input 
                                v-model="form.name_en"
                                type="text" 
                                placeholder="Dell Inspiron Laptop..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-medium"
                            />
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.category') }}</label>
                            <SelectCreate v-model="form.category_id" :options="categories" entity="category" label="name_ar" :placeholder="$t('i_products_create.no_category')" />
                        </div>

                        <!-- Unit -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.unit_of_measure') }}</label>
                            <SelectCreate v-model="form.unit_id" :options="units" entity="unit" label="name_ar" :allow-empty="false" />
                        </div>

                        <!-- SKU -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.product_code_sku') }}</label>
                            <input 
                                v-model="form.sku"
                                type="text" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Barcode -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.barcode_ean') }}</label>
                            <input 
                                v-model="form.barcode"
                                type="text" 
                                :placeholder="$t('i_products_create.scan_the_barcode_here')"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Purchase Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.purchase_price_cost') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="form.purchase_price"
                                type="text" inputmode="decimal" data-num 
                                step="0.01" 
                                min="0"
                                required
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold"
                            />
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.selling_price_before_tax') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model.number="form.selling_price"
                                type="text" inputmode="decimal" data-num 
                                step="0.01" 
                                min="0"
                                required
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold text-indigo-700"
                            />
                        </div>

                        <!-- Initial Stock (if product) -->
                        <div v-if="form.type === 'product'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.opening_stock_balance') }}</label>
                            <input 
                                v-model.number="form.initial_stock"
                                type="text" inputmode="decimal" data-num 
                                step="1" 
                                min="0"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono font-bold"
                            />
                        </div>

                        <!-- Warehouse (if product) -->
                        <div v-if="form.type === 'product'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.warehouse') }}</label>
                            <SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" />
                        </div>
                    </div>

                    <VatFields v-model:category="form.vat_category" v-model:code="form.vat_exemption_code" v-model:tax-rate="form.tax_rate" :categories="vatCategories" :reasons="exemptionReasons" :errors="form.errors" />

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_products_create.description_or_notes') }}</label>
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
                            {{ $t('i_products_create.cancel') }}
                        </Link>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                        >
                            {{ form.processing ? $t('i_products_create.saving') : $t('i_products_create.save_product') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
