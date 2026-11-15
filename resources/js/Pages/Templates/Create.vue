<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const createForm = useForm({
    name: '',
    mode: 'freeform',
    template_type: 'invoice',
    primary_color: '#1e293b',
});
useUnsavedGuard(createForm);
// Free-form templates exist for every printable document kind.
const kinds = computed(() => ({ invoice: t('z_templates_create.sales_invoice'), quotation: t('z_templates_create.quotation'), sales_order: t('z_templates_create.sales_order'), credit_note: t('z_templates_create.credit_note_return'), debit_note: t('z_templates_create.debit_note'), receipt: t('z_templates_create.receipt_voucher'), payment: t('z_templates_create.supplier_payment_voucher'), expense: t('z_templates_create.payment_voucher_expense'), revenue: t('z_templates_create.receipt_voucher_revenue'), journal: t('z_templates_create.journal_entry'), purchase_invoice: t('z_templates_create.purchase_invoice'), purchase_order: t('z_templates_create.purchase_order') }));
const setMode = (mode) => { createForm.mode = mode; createForm.template_type = mode === 'freeform' ? 'invoice' : 'invoice_a4'; };

const submitCreate = () => createForm.post(route('templates.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('z_templates_create.new_print_template')" />
        <PageHeader :title="$t('z_templates_create.new_print_template')" :crumbs="[{ label: $t('z_templates_create.department') }, { label: $t('z_templates_create.print_templates'), href: route('templates.index') }, { label: $t('z_templates_create.new') }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitCreate" class="form-grid text-xs">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('z_templates_create.template_name') }}</label>
                                <input 
                                    v-model="createForm.name" 
                                    type="text" 
                                    required 
                                    :placeholder="$t('z_templates_create.example_the_modern_engineering_i')" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                                />
                            </div>
            
                            <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="طريقة التصميم">
                                <button type="button" role="radio" :aria-checked="createForm.mode === 'freeform'" @click="setMode('freeform')" :class="['p-3 rounded-xl border text-start', createForm.mode === 'freeform' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200']">
                                    <span class="block font-black text-slate-800">{{ $t('z_templates_create.free_design') }}</span>
                                    <span class="block text-slate-500 mt-1">{{ $t('z_templates_create.place_any_element_anywhere_by_dr') }}</span>
                                </button>
                                <button type="button" role="radio" :aria-checked="createForm.mode === 'quick'" @click="setMode('quick')" :class="['p-3 rounded-xl border text-start', createForm.mode === 'quick' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200']">
                                    <span class="block font-black text-slate-800">{{ $t('z_templates_create.quick_design') }}</span>
                                    <span class="block text-slate-500 mt-1">{{ $t('z_templates_create.ready_sections_where_you_choose') }}</span>
                                </button>
                            </div>
            
                            <div v-if="createForm.mode === 'freeform'">
                                <label class="block font-medium text-slate-700 mb-1" for="ff-kind">{{ $t('z_templates_create.document') }}</label>
                                <select id="ff-kind" v-model="createForm.template_type" class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800">
                                    <option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option>
                                </select>
                                <p v-if="createForm.errors.template_type" class="text-rose-600 mt-1">{{ createForm.errors.template_type }}</p>
                                <p class="text-slate-400 mt-1">{{ $t('z_templates_create.starts_from_a_ready_design_where') }}</p>
                            </div>
            
                            <div v-else>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('z_templates_create.document_type') }}</label>
                                <select 
                                    v-model="createForm.template_type" 
                                    class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800"
                                >
                                    <option value="invoice_a4">{{ $t('z_templates_create.standard_tax_invoice_a4_zatca') }}</option>
                                    <option value="invoice_thermal_80mm">{{ $t('z_templates_create.cashier_thermal_receipt_80mm_pos') }}</option>
                                    <option value="quotation">{{ $t('z_templates_create.formal_quotation_a4') }}</option>
                                    <option value="voucher">{{ $t('z_templates_create.financial_vouchers') }}</option>
                                </select>
                            </div>
            
                            <div v-if="createForm.mode === 'quick'">
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('z_templates_create.main_accent_color') }}</label>
                                <div class="flex items-center gap-3">
                                    <input 
                                        v-model="createForm.primary_color" 
                                        type="color" 
                                        class="w-10 h-10 rounded-xl border border-slate-200 cursor-pointer p-1"
                                    />
                                    <input 
                                        v-model="createForm.primary_color" 
                                        type="text" 
                                        class="w-32 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs"
                                    />
                                </div>
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                                <Link :href="route('templates.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('z_templates_create.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="createForm.processing"
                                    class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold flex items-center gap-1.5 disabled:opacity-50"
                                >
                                    <CheckIcon class="w-4 h-4 stroke-2" />
                                    <span>{{ $t('z_templates_create.create_and_open_the_designer') }}</span>
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>
