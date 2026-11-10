<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ products: Array, warehouses: Array });

const transferForm = useForm({
    product_id: props.products?.[0]?.id || '',
    from_warehouse_id: props.warehouses?.[0]?.id || '',
    to_warehouse_id: props.warehouses?.[1]?.id || '',
    quantity: 1,
    notes: '',
});
useUnsavedGuard(transferForm);

const submitTransfer = () => transferForm.post(route('inventory.operations.transfer'));
</script>

<template>
    <AppLayout>
        <Head title="تحويل بين المستودعات" />
        <PageHeader title="تحويل بين المستودعات" :crumbs="[{ label: 'المخزون' }, { label: 'عمليات المخزون', href: route('inventory.operations.index') }, { label: 'تحويل' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitTransfer" class="form-grid text-xs">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">اختر المنتج *</label>
                                <SelectCreate v-model="transferForm.product_id" :options="products" search="lookup.products" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" :allow-empty="false" required />
                                <p v-if="transferForm.errors.product_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.product_id }}</p>
                            </div>
            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">من مستودع *</label>
                                    <SelectCreate v-model="transferForm.from_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required />
                                    <p v-if="transferForm.errors.from_warehouse_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.from_warehouse_id }}</p>
                                </div>
            
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">إلى مستودع *</label>
                                    <SelectCreate v-model="transferForm.to_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required />
                                    <p v-if="transferForm.errors.to_warehouse_id" class="text-[11px] text-rose-600 mt-1">{{ transferForm.errors.to_warehouse_id }}</p>
                                </div>
                            </div>
            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">الكمية المحولة *</label>
                                <input 
                                    v-model.number="transferForm.quantity" 
                                    type="text" inputmode="decimal" data-num 
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
                                <Link :href="route('inventory.operations.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
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
    </AppLayout>
</template>
