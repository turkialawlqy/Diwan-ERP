<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import BrandLogo from '../../../Components/BrandLogo.vue';
import QRCode from 'qrcode';
import {
    PrinterIcon,
    ArrowRightIcon,
    DocumentTextIcon,
    ArrowUturnLeftIcon,
    CheckCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    creditNote: Object,
    company: Object,
});

const qrCodeDataUrl = ref('');

onMounted(async () => {
    if (props.creditNote.zatca_qr) {
        try {
            qrCodeDataUrl.value = await QRCode.toDataURL(props.creditNote.zatca_qr, {
                width: 160,
                margin: 1,
                color: {
                    dark: '#0f172a',
                    light: '#ffffff'
                }
            });
        } catch (e) {
            console.error('Failed to render QR Code', e);
        }
    }
});

const triggerPrint = () => {
    window.print();
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
        <Head :title="`إشعار دائن رقم ${creditNote.credit_note_number}`" />

        <div class="space-y-6 w-full pb-12">
            <!-- Top Action Ribbon (Hidden on Print) -->
            <div class="print:hidden flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.returns.index')"
                        class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                        title="العودة للمرتجعات"
                    >
                        <ArrowRightIcon class="w-4 h-4 rtl:rotate-180" />
                    </Link>
                    <div>
                        <h1 class="text-sm font-black text-slate-800 flex items-center gap-2">
                            <span>إشعار دائن ضريبي:</span>
                            <span class="font-mono text-rose-600">{{ creditNote.credit_note_number }}</span>
                        </h1>
                        <p class="text-2xs text-slate-400">
                            تاريخ الإصدار: {{ formatDateArabic(creditNote.credit_note_date) }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Link 
                        v-if="creditNote.invoice_id"
                        :href="route('sales.invoices.show', creditNote.invoice_id)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-bold text-xs transition-colors"
                    >
                        <DocumentTextIcon class="w-4 h-4" />
                        <span>عرض الفاتورة الأصلية</span>
                    </Link>

                    <ShareDocument v-if="$can('sales.returns.print')" type="credit_note" :id="creditNote.id" />

                    <button 
                        @click="triggerPrint"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-xs transition-all"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة الإشعار الدائن</span>
                    </button>
                </div>
            </div>

            <!-- Printable ZATCA Credit Note Sheet -->
            <div id="printable-credit-note" class="bg-white rounded-2xl border border-slate-200/90 p-8 sm:p-12 shadow-sm text-slate-800 space-y-8 print:p-0 print:border-none print:shadow-none">
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
                            <span class="inline-block px-3 py-1 bg-rose-700 text-white font-black text-xs uppercase tracking-widest rounded">
                                إشعار دائن ضريبي | TAX CREDIT NOTE
                            </span>
                            <p class="text-2xs text-slate-400 mt-1 font-medium">متوافق مع متطلبات هيئة الزكاة والضريبة والجمارك (ZATCA)</p>
                        </div>

                        <!-- Rendered ZATCA QR Code -->
                        <div class="p-2 bg-white rounded-xl border border-slate-300 shadow-xs flex flex-col items-center">
                            <img 
                                v-if="qrCodeDataUrl" 
                                :src="qrCodeDataUrl" 
                                alt="ZATCA Credit Note QR" 
                                class="w-32 h-32 block"
                            />
                            <div v-else class="w-32 h-32 flex items-center justify-center bg-slate-50 text-slate-300 text-2xs">
                                جاري توليد الرمز...
                            </div>
                            <span class="text-3xs text-slate-400 font-mono tracking-tighter mt-1">ZATCA e-Invoice XML V2</span>
                        </div>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-slate-50/70 p-6 rounded-xl border border-slate-200/60 text-xs">
                    <div>
                        <h4 class="font-bold text-slate-400 uppercase text-2xs mb-2">بيانات العميل المستفيد:</h4>
                        <p class="text-base font-black text-slate-900">{{ creditNote.customer?.name }}</p>
                        <p v-if="creditNote.customer?.company_name" class="text-slate-600 font-medium">{{ creditNote.customer.company_name }}</p>
                        <p v-if="creditNote.customer?.tax_number" class="text-slate-600 mt-1">الرقم الضريبي: <span class="font-mono font-bold">{{ creditNote.customer.tax_number }}</span></p>
                        <p v-if="creditNote.customer?.phone" class="text-slate-500 font-mono">الهاتف: {{ creditNote.customer.phone }}</p>
                    </div>

                    <div class="sm:text-end space-y-1.5">
                        <div class="flex sm:justify-end gap-2">
                            <span class="text-slate-500">رقم الإشعار الدائن:</span>
                            <span class="font-mono font-bold text-slate-900">{{ creditNote.credit_note_number }}</span>
                        </div>
                        <div class="flex sm:justify-end gap-2">
                            <span class="text-slate-500">تاريخ الإصدار:</span>
                            <span class="font-mono text-slate-900">{{ creditNote.credit_note_date }}</span>
                        </div>
                        <div v-if="creditNote.invoice" class="flex sm:justify-end gap-2">
                            <span class="text-slate-500">مرتبط بالفاتورة رقم:</span>
                            <span class="font-mono font-bold text-indigo-600">{{ creditNote.invoice.invoice_number }}</span>
                        </div>
                        <div class="flex sm:justify-end gap-2">
                            <span class="text-slate-500">حالة الإشعار:</span>
                            <span class="font-bold text-emerald-600">
                                {{ creditNote.status === 'refunded' ? 'مسترد نقداً' : 'معتمد ومخصوم بالحساب' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Reason Notice -->
                <div class="p-4 bg-rose-50/60 border border-rose-100 rounded-xl text-xs space-y-1">
                    <h5 class="font-bold text-rose-800">سبب الإرجاع / التعديل:</h5>
                    <p class="text-rose-900 leading-relaxed">{{ creditNote.reason }}</p>
                </div>

                <!-- Financial Breakdown Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs">
                        <thead>
                            <tr class="border-b-2 border-slate-800 bg-slate-100 text-slate-900 font-black">
                                <th class="py-3 px-4">البيان</th>
                                <th class="py-3 px-4 text-center">المبلغ المسترد (قبل الضريبة)</th>
                                <th class="py-3 px-4 text-center">نسبة الضريبة</th>
                                <th class="py-3 px-4 text-center">مبلغ ضريبة القيمة المضافة</th>
                                <th class="py-3 px-4 text-start">الإجمالي المسترد شامل الضريبة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr class="border-b border-slate-100">
                                <td class="py-4 px-4 font-bold text-slate-900">
                                    {{ creditNote.invoice ? `مرتجع مبيعات عن الفاتورة ${creditNote.invoice.invoice_number}` : 'إشعار دائن وتسوية مبيعات' }}
                                </td>
                                <td class="py-4 px-4 text-center font-mono font-bold">{{ formatCurrency(creditNote.subtotal) }} ﷼</td>
                                <td class="py-4 px-4 text-center font-mono text-slate-500">15.00%</td>
                                <td class="py-4 px-4 text-center font-mono text-slate-500">{{ formatCurrency(creditNote.tax_amount) }} ﷼</td>
                                <td class="py-4 px-4 text-start font-mono font-black text-rose-600 text-sm">{{ formatCurrency(creditNote.total) }} ﷼</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Summary Totals -->
                <div class="flex justify-end pt-4 border-t border-slate-200">
                    <div class="w-full sm:w-80 space-y-2 text-xs">
                        <div class="flex justify-between py-1 border-b border-slate-100 text-slate-600">
                            <span>المجموع الخاضع للضريبة:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(creditNote.subtotal) }} ﷼</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 text-slate-600">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(creditNote.tax_amount) }} ﷼</span>
                        </div>
                        <div class="flex justify-between py-2 border-b-2 border-slate-900 text-base font-black text-slate-900">
                            <span>إجمالي الإشعار الدائن:</span>
                            <span class="font-mono text-rose-600">{{ formatCurrency(creditNote.total) }} ﷼</span>
                        </div>
                    </div>
                </div>

                <!-- Signatures -->
                <div class="grid grid-cols-2 gap-8 pt-12 text-center text-xs text-slate-600">
                    <div class="space-y-8 border-t border-slate-200 pt-4">
                        <p class="font-bold">المحاسب المسؤول / المعتمد</p>
                        <div class="h-12"></div>
                    </div>
                    <div class="space-y-8 border-t border-slate-200 pt-4">
                        <p class="font-bold">توقيع المستلم / العميل</p>
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
    #printable-credit-note {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>
