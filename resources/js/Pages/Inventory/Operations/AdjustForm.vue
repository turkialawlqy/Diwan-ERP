<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ products: Array, warehouses: Array, preset: { type: Object, default: () => ({}) } });

const adjustForm = useForm({
    product_id: props.preset?.product_id || props.products?.[0]?.id || '',
    warehouse_id: props.preset?.warehouse_id || props.warehouses?.[0]?.id || '',
    type: 'add',
    quantity: 1,
    reason: '',
});
useUnsavedGuard(adjustForm);

const submitAdjust = () => adjustForm.post(route('inventory.operations.adjust'));
</script>

<template>
    <AppLayout>
        <Head title="تسوية مخزون" />
        <PageHeader title="تسوية مخزون" :crumbs="[{ label: 'المخزون' }, { label: 'عمليات المخزون', href: route('inventory.operations.index') }, { label: 'تسوية' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitAdjust" class="form-grid text-xs">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">المنتج *</label>
                                <SelectCreate v-model="adjustForm.product_id" :options="products" search="lookup.products" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" :allow-empty="false" required />
                            </div>
            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">المستودع *</label>
                                <SelectCreate v-model="adjustForm.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required />
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
                                        v-model.number="adjustForm.quantity" 
                                        type="text" inputmode="decimal" data-num 
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
                                <Link :href="route('inventory.operations.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
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
    </AppLayout>
</template>
