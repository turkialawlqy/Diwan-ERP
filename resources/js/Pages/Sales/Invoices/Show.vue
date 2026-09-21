<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import AlOstazLogo from '../../../Components/AlOstazLogo.vue';
import QRCode from 'qrcode';
import {
    PrinterIcon,
    ArrowRightIcon,
    ArrowDownTrayIcon,
    CheckCircleIcon,
    ShareIcon,
    ShieldCheckIcon,
    BuildingOffice2Icon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
    company: Object,
});

const qrCodeDataUrl = ref('');

onMounted(async () => {
    if (props.invoice.zatca_qr) {
        try {
            qrCodeDataUrl.value = await QRCode.toDataURL(props.invoice.zatca_qr, {
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
        <Head :title="`فاتورة رقم ${invoice.invoice_number}`" />

        <div class="space-y-6 max-w-5xl mx-auto">
            <!-- Top Action Ribbon (Hidden on Print) -->
            <div class="print:hidden flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.invoices.index')"
                        class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                        title="العودة للفواتير"
                    >
                        <ArrowRightIcon class="w-4 h-4 rtl:rotate-180" />
                    </Link>
                    <div>
                        <h1 class="text-sm font-black text-slate-800 flex items-center gap-2">
                            <span>فاتورة ضريبية:</span>
                            <span class="font-mono text-indigo-600">{{ invoice.invoice_number }}</span>
                        </h1>
                        <p class="text-2xs text-slate-400">
                            تاريخ الإصدار: {{ formatDateArabic(invoice.invoice_date) }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Print Button -->
                    <button 
                        @click="triggerPrint"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs hover:shadow-indigo-500/20 transition-all"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>طباعة الفاتورة</span>
                    </button>
                </div>
            </div>

            <!-- Printable ZATCA Standard Tax Invoice Sheet -->
            <div id="printable-invoice" class="bg-white rounded-2xl border border-slate-200/90 p-8 sm:p-12 shadow-sm text-slate-800 space-y-8 print:p-0 print:border-none print:shadow-none">
                <!-- Header: Company & ZATCA QR Code -->
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6 border-b-2 border-slate-800 pb-8">
                    <!-- Right/Start: Company details & Branding -->
                    <div class="space-y-3 max-w-md">
                        <div class="flex items-center gap-3">
                            <AlOstazLogo size="lg" />
                            <div>
                                <h2 class="text-xl font-black text-slate-900 leading-tight">
                                    {{ company?.name || 'شركة الأستاذ للتجارة والحلول البرمجية' }}
                                </h2>
                                <p class="text-xs text-slate-500 font-medium">نظام الأستاذ المحاسبي الشامل - المملكة العربية السعودية</p>
                            </div>
                        </div>

                        <div class="text-xs text-slate-600 space-y-1 font-medium leading-relaxed pt-2">
                            <p><strong>الرقم الضريبي (VAT):</strong> <span class="font-mono text-slate-900 font-bold">{{ company?.tax_number || '300000000000003' }}</span></p>
                            <p><strong>السجل التجاري (CR):</strong> <span class="font-mono text-slate-900">{{ company?.commercial_registration || '1010000000' }}</span></p>
                            <p><strong>العنوان:</strong> الرياض، المملكة العربية السعودية</p>
                            <p><strong>الهاتف:</strong> {{ company?.phone || '+966 11 000 0000' }}</p>
                        </div>
                    </div>

                    <!-- Center/Left: Invoice Title & Official ZATCA QR Code -->
                    <div class="flex flex-col items-center sm:items-end gap-3">
                        <div class="text-center sm:text-end">
                            <span class="inline-block px-3 py-1 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded">
                                فاتورة ضريبية | TAX INVOICE
                            </span>
                            <p class="text-2xs text-slate-400 mt-1 font-medium">متوافقة مع اشتراطات هيئة الزكاة والضريبة والجمارك (ZATCA)</p>
                        </div>

                        <!-- Rendered ZATCA Base64 TLV QR Code -->
                        <div class="p-2 bg-white rounded-xl border border-slate-300 shadow-xs flex flex-col items-center">
                            <img 
                                v-if="qrCodeDataUrl" 
                                :src="qrCodeDataUrl" 
                                alt="ZATCA E-Invoice QR Code" 
                                class="w-32 h-32 object-contain"
                            />
                            <div v-else class="w-32 h-32 bg-slate-100 flex items-center justify-center text-slate-400 text-3xs">
                                جاري توليد رمز الزكاة...
                            </div>
                            <span class="text-3xs text-slate-500 font-mono mt-1 font-semibold">رمز الفوترة الإلكترونية</span>
                        </div>
                    </div>
                </div>

                <!-- Invoice Meta & Customer Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-slate-50/80 rounded-xl p-5 border border-slate-200/80 text-xs">
                    <!-- Invoice Details -->
                    <div class="space-y-2">
                        <h4 class="font-bold text-slate-900 text-2xs uppercase tracking-wider text-indigo-700">بيانات الفاتورة / Invoice Details</h4>
                        <div class="grid grid-cols-2 gap-2 text-slate-600">
                            <div>رقم الفاتورة:</div>
                            <div class="font-mono font-bold text-slate-900">{{ invoice.invoice_number }}</div>

                            <div>تاريخ الإصدار:</div>
                            <div class="font-medium text-slate-900">{{ formatDateArabic(invoice.invoice_date) }}</div>

                            <div>تاريخ التوريد:</div>
                            <div class="font-medium text-slate-900">{{ formatDateArabic(invoice.supply_date || invoice.invoice_date) }}</div>

                            <div>طريقة السداد:</div>
                            <div class="font-bold text-slate-900">
                                {{ invoice.payment_method === 'cash' ? 'نقداً' : invoice.payment_method === 'card' ? 'مدى / بطاقة' : invoice.payment_method === 'bank_transfer' ? 'تحويل بنكي' : 'آجل' }}
                            </div>
                        </div>
                    </div>

                    <!-- Customer Details -->
                    <div class="space-y-2">
                        <h4 class="font-bold text-slate-900 text-2xs uppercase tracking-wider text-indigo-700">بيانات العميل / Customer Details</h4>
                        <div class="grid grid-cols-2 gap-2 text-slate-600">
                            <div>اسم العميل:</div>
                            <div class="font-bold text-slate-900">{{ invoice.customer?.name || 'عميل نقدي' }}</div>

                            <div v-if="invoice.customer?.company_name">المنشأة:</div>
                            <div v-if="invoice.customer?.company_name" class="text-slate-900">{{ invoice.customer.company_name }}</div>

                            <div>الرقم الضريبي للعميل:</div>
                            <div class="font-mono font-bold text-slate-900">{{ invoice.customer?.tax_number || 'غير مسجل' }}</div>

                            <div>العنوان / الهاتف:</div>
                            <div class="text-slate-900">{{ invoice.customer?.city || 'المملكة العربية السعودية' }} - {{ invoice.customer?.phone || '-' }}</div>
                        </div>
                    </div>
                </div>

                <!-- Line Items Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse text-xs">
                        <thead>
                            <tr class="border-y-2 border-slate-800 bg-slate-100/60 font-black text-slate-900">
                                <th class="py-3 px-3 text-center w-10">#</th>
                                <th class="py-3 px-4 min-w-[200px]">السلعة أو الخدمة / Description</th>
                                <th class="py-3 px-3 text-center">الكمية</th>
                                <th class="py-3 px-3 text-start">سعر الوحدة</th>
                                <th class="py-3 px-3 text-start">الخصم</th>
                                <th class="py-3 px-3 text-start">الضريبة (%15)</th>
                                <th class="py-3 px-4 text-end">المجموع شامل الضريبة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="(item, idx) in invoice.items" :key="item.id" class="text-slate-800">
                                <td class="py-3 px-3 text-center font-mono font-bold text-slate-400">{{ idx + 1 }}</td>
                                <td class="py-3 px-4">
                                    <p class="font-bold text-slate-900">{{ item.item_name }}</p>
                                    <p v-if="item.description" class="text-2xs text-slate-500 mt-0.5">{{ item.description }}</p>
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold">{{ Number(item.quantity) }}</td>
                                <td class="py-3 px-3 text-start font-mono">{{ formatCurrency(item.unit_price) }} ﷼</td>
                                <td class="py-3 px-3 text-start font-mono">{{ formatCurrency(item.discount) }} ﷼</td>
                                <td class="py-3 px-3 text-start font-mono">{{ formatCurrency(item.tax_amount) }} ﷼</td>
                                <td class="py-3 px-4 text-end font-mono font-black text-slate-900">{{ formatCurrency(item.total) }} ﷼</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals Breakdown & ZATCA Verification Box -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 pt-4">
                    <!-- Notes & Terms -->
                    <div class="space-y-4 text-2xs text-slate-600">
                        <div v-if="invoice.notes">
                            <p class="font-bold text-slate-800 mb-1">ملاحظات:</p>
                            <p class="bg-slate-50 p-3 rounded-lg border border-slate-100 leading-relaxed">{{ invoice.notes }}</p>
                        </div>
                        <div v-if="invoice.terms">
                            <p class="font-bold text-slate-800 mb-1">الشروط والأحكام:</p>
                            <p class="bg-slate-50 p-3 rounded-lg border border-slate-100 leading-relaxed">{{ invoice.terms }}</p>
                        </div>
                    </div>

                    <!-- Totals Box -->
                    <div class="bg-slate-50/90 rounded-xl border border-slate-200 p-5 space-y-3 text-xs">
                        <div class="flex items-center justify-between text-slate-600">
                            <span>المجموع غير شامل الضريبة (Total Excl. VAT)</span>
                            <span class="font-mono font-bold text-slate-900">{{ formatCurrency(invoice.subtotal) }} ﷼</span>
                        </div>

                        <div v-if="Number(invoice.discount_amount) > 0" class="flex items-center justify-between text-rose-600">
                            <span>إجمالي الخصومات (Total Discounts)</span>
                            <span class="font-mono font-bold">- {{ formatCurrency(invoice.discount_amount) }} ﷼</span>
                        </div>

                        <div class="flex items-center justify-between text-slate-600">
                            <span>ضريبة القيمة المضافة 15% (VAT Amount)</span>
                            <span class="font-mono font-bold text-slate-900">{{ formatCurrency(invoice.tax_amount) }} ﷼</span>
                        </div>

                        <div class="border-t-2 border-slate-800 pt-3">
                            <div class="flex items-center justify-between text-slate-900 font-black">
                                <span class="text-sm">المجموع الكلي شامل الضريبة (Total Inc. VAT)</span>
                                <span class="text-lg text-indigo-700 font-mono">{{ formatCurrency(invoice.total) }} ﷼</span>
                            </div>
                        </div>

                        <div v-if="invoice.payment_status !== 'paid'" class="border-t border-slate-200 pt-2 space-y-1 text-2xs">
                            <div class="flex items-center justify-between text-emerald-700 font-bold">
                                <span>المبلغ المسدد (Paid Amount)</span>
                                <span class="font-mono">{{ formatCurrency(invoice.paid_amount) }} ﷼</span>
                            </div>
                            <div class="flex items-center justify-between text-rose-700 font-bold">
                                <span>المبلغ المتبقي (Remaining Amount)</span>
                                <span class="font-mono">{{ formatCurrency(invoice.remaining_amount) }} ﷼</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Signatures -->
                <div class="pt-8 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500">
                    <div>
                        <p class="font-bold text-slate-700 mb-6">المستلم / Receiver Signature:</p>
                        <div class="w-40 border-b border-dashed border-slate-400"></div>
                    </div>
                    <div class="text-center">
                        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 font-bold text-2xs border border-emerald-200">
                            <ShieldCheckIcon class="w-4 h-4 text-emerald-600" />
                            <span>فاتورة إلكترونية معتمدة - نظام الأستاذ</span>
                        </div>
                    </div>
                    <div class="text-end">
                        <p class="font-bold text-slate-700 mb-6">الختم والتوقيع المعتمد / Stamp:</p>
                        <div class="w-40 border-b border-dashed border-slate-400 ms-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    body {
        background-color: #ffffff !important;
        font-family: 'Cairo', sans-serif !important;
    }
    aside, header, nav, .print\:hidden {
        display: none !important;
    }
    main {
        padding: 0 !important;
        margin: 0 !important;
    }
    #printable-invoice {
        border: none !important;
        box-shadow: none !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
    }
}
</style>
