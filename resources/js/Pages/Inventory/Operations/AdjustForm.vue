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
        <Head :title="$t('i_operations_adjustform.stock_adjustment')" />
        <PageHeader :title="$t('i_operations_adjustform.stock_adjustment')" :crumbs="[{ label: 'المخزون' }, { label: 'عمليات المخزون', href: route('inventory.operations.index') }, { label: 'تسوية' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitAdjust" class="form-grid text-xs">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_operations_adjustform.product') }}</label>
                                <SelectCreate v-model="adjustForm.product_id" :options="products" search="lookup.products" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" :allow-empty="false" required />
                            </div>
            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_operations_adjustform.warehouse') }}</label>
                                <SelectCreate v-model="adjustForm.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required />
                            </div>
            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_operations_adjustform.operation_type') }}</label>
                                    <select 
                                        v-model="adjustForm.type"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                                    >
                                        <option value="add">{{ $t('i_operations_adjustform.add_quantity') }}</option>
                                        <option value="subtract">{{ $t('i_operations_adjustform.deduct_quantity') }}</option>
                                        <option value="set">{{ $t('i_operations_adjustform.set_the_actual_balance') }}</option>
                                        <option value="damage">{{ $t('i_operations_adjustform.write_off_damaged') }}</option>
                                    </select>
                                </div>
            
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_operations_adjustform.quantity') }}</label>
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
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('i_operations_adjustform.reason_justification') }}</label>
                                <input 
                                    v-model="adjustForm.reason" 
                                    type="text" 
                                    :placeholder="$t('i_operations_adjustform.damaged_count_surplus_sample')"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                                />
                            </div>
            
                            <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                                <Link :href="route('inventory.operations.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('i_operations_adjustform.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="adjustForm.processing"
                                    class="px-5 py-2 text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition disabled:opacity-50"
                                >
                                    {{ $t('i_operations_adjustform.update_stock') }}
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>
