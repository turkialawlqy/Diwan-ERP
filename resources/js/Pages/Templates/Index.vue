<script setup>
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    PrinterIcon,
    HomeIcon,
    ChevronRightIcon,
    DocumentTextIcon,
    PlusIcon,
    PaintBrushIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    CheckIcon,
    XMarkIcon,
    StarIcon,
    EyeIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';
const { t } = useI18n();

const props = defineProps({
    templates: Array,
});

// Free-form templates exist for every printable document kind.
const kinds = computed(() => ({ invoice: t('z_templates_index.sales_invoice'), quotation: t('z_templates_index.quotation'), sales_order: t('z_templates_index.sales_order'), credit_note: t('z_templates_index.credit_note_return'), debit_note: t('z_templates_index.debit_note'), receipt: t('z_templates_index.receipt_voucher'), payment: t('z_templates_index.supplier_payment_voucher'), expense: t('z_templates_index.payment_voucher_expense'), revenue: t('z_templates_index.receipt_voucher_revenue'), journal: t('z_templates_index.journal_entry'), purchase_invoice: t('z_templates_index.purchase_invoice'), purchase_order: t('z_templates_index.purchase_order') }));
const isFreeform = (t) => t.layout_data?.mode === 'freeform';

const duplicateTemplate = (template) => {
    router.post(route('templates.duplicate', template.id));
};

const setDefaultTemplate = (template) => {
    router.post(route('templates.default', template.id));
};

const deleteTemplate = (template) => {
    if (confirm(t('z_templates_index.are_you_sure_you_want_to_delete', { n0: template.name }))) {
        router.delete(route('templates.destroy', template.id));
    }
};

const getTypeName = (type) => {
    switch (type) {
        case 'invoice_a4':
            return t('z_templates_index.a4_tax_invoice_zatca');
        case 'invoice_thermal_80mm':
            return t('z_templates_index.cashier_thermal_receipt_80mm_pos');
        case 'quotation':
            return t('z_templates_index.quotation_a4');
        case 'voucher':
            return t('z_templates_index.receipt_payment_voucher');
        default:
            return kinds.value[type] ? t('z_templates_index.free_design_n', { n0: kinds.value[type] }) : type;
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('z_templates_index.invoice_and_print_template_desig')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('z_templates_index.print_templates')" :crumbs="[{ label: $t('z_templates_index.print_templates') }]">
<template #actions>
<button 
                        @click="router.reload()" 
                        :title="$t('z_templates_index.refresh_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<Link v-if="$can('admin.templates.create')" :href="route('templates.create')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('z_templates_index.create_a_new_template') }}</span>
                    </Link>
</template>
</PageHeader>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('z_templates_index.total_invoice_templates') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ templates?.length || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('z_templates_index.default_template') }}</p>
                    <p class="text-base sm:text-lg font-black text-emerald-600 truncate">{{ templates?.find(t => t.is_default)?.name || $t('z_templates_index.unassigned') }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('z_templates_index.a4_invoice_templates') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ templates?.filter(t => t.template_type === 'invoice_a4').length || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('z_templates_index.pos_thermal_receipts') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-amber-600">{{ templates?.filter(t => t.template_type === 'invoice_thermal_80mm').length || 0 }}</p>
                </div>
            </div>

            <!-- Templates Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="tmpl in templates" 
                    :key="tmpl.id"
                    class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs hover:shadow-md transition flex flex-col justify-between overflow-hidden group"
                >
                    <!-- Card Header Bar -->
                    <div 
                        class="h-2.5 w-full"
                        :style="{ backgroundColor: tmpl.primary_color || '#4F46E5' }"
                    ></div>

                    <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2.5">
                                    <div 
                                        class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold"
                                        :style="{ backgroundColor: tmpl.primary_color || '#4F46E5' }"
                                    >
                                        <PrinterIcon class="w-5 h-5" />
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 text-sm group-hover:text-indigo-600 transition">
                                            {{ tmpl.name }}
                                        </h3>
                                        <span class="text-3xs text-slate-400 font-mono">{{ getTypeName(tmpl.template_type) }}</span>
                                    </div>
                                </div>

                                <span v-if="tmpl.is_default" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-3xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <StarSolidIcon class="w-3 h-3 text-emerald-600" />
                                    {{ $t('z_templates_index.default') }}
                                </span>
                            </div>

                            <div v-if="isFreeform(tmpl)" class="bg-indigo-50/60 p-3 rounded-xl border border-indigo-100 text-3xs text-indigo-800 leading-5">
                                {{ $t('z_templates_index.free_design_n_elements_on_page_n', { n0: tmpl.layout_data?.freeform?.elements?.length || 0, n1: tmpl.layout_data?.freeform?.page?.size === 'custom' ? 'بمقاس مخصص' : tmpl.layout_data?.freeform?.page?.size }) }}
                                <template v-if="tmpl.is_default"> {{ $t('z_templates_index.used_when_printing_n', { n0: kinds[tmpl.template_type] }) }}</template>
                            </div>
                            <!-- Features checklist summary -->
                            <div v-else class="bg-slate-50/80 p-3 rounded-xl border border-slate-100 grid grid-cols-2 gap-2 text-3xs text-slate-600">
                                <div class="flex items-center gap-1.5">
                                    <span :class="tmpl.show_logo ? 'text-emerald-600' : 'text-slate-300'">●</span>
                                    <span>{{ $t('z_templates_index.official_logo') }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span :class="tmpl.show_qr ? 'text-emerald-600' : 'text-slate-300'">●</span>
                                    <span>{{ $t('z_templates_index.zatca_qr_code') }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span :class="tmpl.show_stamp ? 'text-emerald-600' : 'text-slate-300'">●</span>
                                    <span>{{ $t('z_templates_index.stamp_and_signature') }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span :class="tmpl.show_bank_details ? 'text-emerald-600' : 'text-slate-300'">●</span>
                                    <span>{{ $t('z_templates_index.bank_accounts') }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Actions -->
                        <div class="pt-4 border-t border-slate-100 flex items-center justify-between gap-2">
                            <Link 
                                :href="route('templates.designer', tmpl.id)"
                                class="flex-1 py-2 px-3 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 text-center shadow-2xs"
                            >
                                <PaintBrushIcon class="w-4 h-4" />
                                <span>{{ $t('z_templates_index.visual_designer') }}</span>
                            </Link>

                            <button 
                                @click="duplicateTemplate(tmpl)"
                                type="button"
                                class="p-2 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-600 transition"
                                :title="$t('z_templates_index.duplicate_the_template')"
                            >
                                <DocumentDuplicateIcon class="w-4 h-4" />
                            </button>

                            <button 
                                v-if="!tmpl.is_default"
                                @click="setDefaultTemplate(tmpl)"
                                type="button"
                                class="p-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-700 transition"
                                :title="$t('z_templates_index.set_as_default')"
                            >
                                <StarIcon class="w-4 h-4" />
                            </button>

                            <button 
                                v-if="!tmpl.is_default"
                                @click="deleteTemplate(tmpl)"
                                type="button"
                                class="p-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 transition"
                                :title="$t('z_templates_index.delete_the_template')"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
