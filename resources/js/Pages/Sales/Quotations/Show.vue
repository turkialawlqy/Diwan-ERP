<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import BrandLogo from '../../../Components/BrandLogo.vue';
import {
    PrinterIcon,
    ArrowRightIcon,
    CheckCircleIcon,
    ArrowRightCircleIcon,
    BuildingOffice2Icon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    quotation: Object,
    company: Object,
});

const triggerPrint = () => {
    window.print();
};

const convertToInvoice = () => {
    if (confirm(`هل ترغب في تحويل عرض السعر ${props.quotation.quotation_number} إلى فاتورة مبيعات رسمية؟`)) {
        router.post(route('sales.quotations.convert', props.quotation.id));
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
        <Head :title="`عرض سعر رقم ${quotation.quotation_number}`" />

        <div class="space-y-6 w-full pb-12">
            <!-- Top Action Ribbon (Hidden on Print) -->
            <div class="print:hidden flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.quotations.index')"
                        class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                        title="العودة لعروض الأسعار"
                    >
                        <ArrowRightIcon class="w-4 h-4 rtl:rotate-180" />
                    </Link>
                    <div>
                        <h1 class="text-sm font-black text-slate-800 flex items-center gap-2">
                            <span>عرض سعر:</span>
                            <span class="font-mono text-indigo-600">{{ quotation.quotation_number }}</span>
                            <span 
                                v-if="quotation.status === 'converted'"
                                class="text-2xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
                            >
                                تم التحويل لفاتورة
                            </span>
                        </h1>
                        <p class="text-2xs text-slate-400">
                            تاريخ الإصدار: {{ formatDateArabic(quotation.quotation_date) }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <ShareDocument v-if="$can('sales.quotations.print')" type="quotation" :id="quotation.id" />

                    <!-- Convert to Invoice Button -->
                    <button 
                        v-if="quotation.status !== 'converted'"
                        @click="convertToInvoice"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition-all"
                    >
                        <ArrowRightCircleIcon class="w-4 h-4" />
                        <span>تحويل إلى فاتورة مبيعات</span>
                    </button>

                    <!-- Print Button -->
                    <button 
                        @click="triggerPrint"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs hover:shadow-indigo-500/20 transition-all"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة عرض السعر</span>
                    </button>
                </div>
            </div>

            <!-- Printable Quotation Sheet -->
            <div id="printable-quotation" class="bg-white rounded-2xl border border-slate-200/90 p-8 sm:p-12 shadow-sm text-slate-800 space-y-8 print:p-0 print:border-none print:shadow-none">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6 border-b-2 border-slate-800 pb-8">
                    <div class="space-y-3 max-w-md">
                        <div class="flex items-center gap-3">
                            <BrandLogo size="lg" />
                            <div>
                                <h2 class="text-xl font-black text-slate-900 leading-tight">
                                    {{ company?.name || $page.props.brand?.name }}
                                </h2>
                                <p class="text-xs text-slate-500 font-medium">{{ $page.props.brand?.name }} — نظام محاسبي متكامل - المملكة العربية السعودية</p>
                            </div>
                        </div>

                        <div class="text-xs text-slate-600 space-y-1 font-medium leading-relaxed pt-2">
                            <p><strong>الرقم الضريبي (VAT):</strong> <span class="font-mono text-slate-900 font-bold">{{ company?.tax_number || '300000000000003' }}</span></p>
                            <p><strong>السجل التجاري (CR):</strong> <span class="font-mono text-slate-900">{{ company?.commercial_registration || '1010000000' }}</span></p>
                            <p><strong>العنوان:</strong> الرياض، المملكة العربية السعودية</p>
                            <p><strong>الهاتف:</strong> {{ company?.phone || '+966 11 000 0000' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col items-center sm:items-end gap-3">
                        <div class="text-center sm:text-end">
                            <span class="inline-block px-3 py-1 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded">
                                عرض سعر | PRICE QUOTATION
                            </span>
                            <p class="text-2xs text-slate-400 mt-1 font-medium">عرض سعر تقديري - غير ملزم كفاتورة ضريبية</p>
                        </div>

                        <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-1 min-w-[220px]">
                            <div class="flex justify-between">
                                <span class="text-slate-500">رقم العرض:</span>
                                <span class="font-mono font-bold text-slate-900">{{ quotation.quotation_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">تاريخ العرض:</span>
                                <span class="font-mono text-slate-900">{{ quotation.quotation_date }}</span>
                            </div>
                            <div v-if="quotation.valid_until" class="flex justify-between">
                                <span class="text-slate-500">صالح حتى:</span>
                                <span class="font-mono text-amber-700 font-bold">{{ quotation.valid_until }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Details Ribbon -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-slate-50/60 p-6 rounded-xl border border-slate-200/60 text-xs">
                    <div>
                        <h4 class="font-bold text-slate-400 uppercase text-2xs mb-2">مقدم إلى (العميل):</h4>
                        <p class="text-base font-black text-slate-900">{{ quotation.customer?.name || 'العميل النقدي' }}</p>
                        <p v-if="quotation.customer?.company_name" class="text-slate-600 font-medium">{{ quotation.customer.company_name }}</p>
                        <p v-if="quotation.customer?.tax_number" class="text-slate-600 mt-1">الرقم الضريبي: <span class="font-mono font-bold">{{ quotation.customer.tax_number }}</span></p>
                        <p v-if="quotation.customer?.phone" class="text-slate-500 font-mono">الهاتف: {{ quotation.customer.phone }}</p>
                    </div>

                    <div class="sm:text-end space-y-1">
                        <h4 class="font-bold text-slate-400 uppercase text-2xs mb-2">حالة العرض:</h4>
                        <span 
                            v-if="quotation.status === 'converted'"
                            class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200"
                        >
                            تم تحويله لفاتورة مبيعات
                        </span>
                        <span 
                            v-else-if="quotation.status === 'accepted'"
                            class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"
                        >
                            مقبول من العميل
                        </span>
                        <span 
                            v-else
                            class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200"
                        >
                            عرض سعر ساري
                        </span>
                    </div>
                </div>

                <!-- Quotation Items Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs">
                        <thead>
                            <tr class="border-b-2 border-slate-800 bg-slate-100 text-slate-900 font-black">
                                <th class="py-3 px-4 w-12 text-center">#</th>
                                <th class="py-3 px-4">الصنف / الخدمة</th>
                                <th class="py-3 px-4 text-center">الكمية</th>
                                <th class="py-3 px-4 text-center">سعر الوحدة</th>
                                <th class="py-3 px-4 text-center">الخصم</th>
                                <th class="py-3 px-4 text-center">الضريبة %</th>
                                <th class="py-3 px-4 text-center">مبلغ الضريبة</th>
                                <th class="py-3 px-4 text-start">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="(item, idx) in quotation.items" :key="item.id" class="border-b border-slate-100">
                                <td class="py-3 px-4 text-center font-mono text-slate-400">{{ idx + 1 }}</td>
                                <td class="py-3 px-4">
                                    <p class="font-bold text-slate-900">{{ item.item_name }}</p>
                                    <p v-if="item.description" class="text-2xs text-slate-500 mt-0.5">{{ item.description }}</p>
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-bold">{{ Number(item.quantity) }}</td>
                                <td class="py-3 px-4 text-center font-mono">{{ formatCurrency(item.unit_price) }} ﷼</td>
                                <td class="py-3 px-4 text-center font-mono text-slate-500">{{ formatCurrency(item.discount) }} ﷼</td>
                                <td class="py-3 px-4 text-center font-mono text-slate-500">{{ Number(item.tax_rate) }}%</td>
                                <td class="py-3 px-4 text-center font-mono text-slate-500">{{ formatCurrency(item.tax_amount) }} ﷼</td>
                                <td class="py-3 px-4 text-start font-mono font-black text-slate-900">{{ formatCurrency(item.total) }} ﷼</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals Section -->
                <div class="flex flex-col sm:flex-row justify-between gap-6 pt-4 border-t border-slate-200">
                    <div class="space-y-4 max-w-md">
                        <div v-if="quotation.terms" class="text-xs text-slate-600 bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <h5 class="font-bold text-slate-700 mb-1">الشروط والأحكام:</h5>
                            <p class="whitespace-pre-line text-2xs leading-relaxed">{{ quotation.terms }}</p>
                        </div>
                        <div v-if="quotation.notes" class="text-xs text-slate-500">
                            <h5 class="font-bold text-slate-600 mb-0.5">ملاحظات:</h5>
                            <p class="text-2xs">{{ quotation.notes }}</p>
                        </div>
                    </div>

                    <div class="w-full sm:w-72 space-y-2 text-xs">
                        <div class="flex justify-between py-1 border-b border-slate-100 text-slate-600">
                            <span>المجموع الفرعي (غير شامل الضريبة):</span>
                            <span class="font-mono font-bold">{{ formatCurrency(quotation.subtotal) }} ﷼</span>
                        </div>
                        <div v-if="quotation.discount_amount > 0" class="flex justify-between py-1 border-b border-slate-100 text-emerald-600 font-medium">
                            <span>إجمالي الخصم:</span>
                            <span class="font-mono font-bold">- {{ formatCurrency(quotation.discount_amount) }} ﷼</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 text-slate-600">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(quotation.tax_amount) }} ﷼</span>
                        </div>
                        <div class="flex justify-between py-2 border-b-2 border-slate-900 text-base font-black text-slate-900">
                            <span>الإجمالي شامل الضريبة:</span>
                            <span class="font-mono text-indigo-600">{{ formatCurrency(quotation.total) }} ﷼</span>
                        </div>
                    </div>
                </div>

                <!-- Signatures & Acceptance Footer -->
                <div class="grid grid-cols-2 gap-8 pt-12 text-center text-xs text-slate-600">
                    <div class="space-y-8 border-t border-slate-200 pt-4">
                        <p class="font-bold">توقيع وختم المنشأة</p>
                        <div class="h-12"></div>
                    </div>
                    <div class="space-y-8 border-t border-slate-200 pt-4">
                        <p class="font-bold">قبول واعتماد العميل</p>
                        <div class="h-12"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    body {
        background: white !important;
    }
    #printable-quotation {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>
