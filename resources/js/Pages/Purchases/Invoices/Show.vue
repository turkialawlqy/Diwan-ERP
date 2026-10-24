<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import BrandLogo from '../../../Components/BrandLogo.vue';
import {
    PrinterIcon,
    ArrowLeftOnRectangleIcon,
    ChevronRightIcon,
    HomeIcon,
    BuildingOfficeIcon,
    TruckIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationCircleIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
    company: Object,
});

const printInvoice = () => {
    window.print();
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="'فاتورة شراء ' + invoice.invoice_number" />

        <div class="space-y-6 max-w-4xl mx-auto">
            <!-- Header Ribbon & Actions (Hidden on Print) -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 print:hidden">
                <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs font-medium text-slate-600 shadow-2xs">
                    <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                        <HomeIcon class="w-4 h-4" />
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <Link :href="route('purchases.invoices.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">فواتير الشراء</Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-indigo-600 font-bold font-mono">{{ invoice.invoice_number }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <button 
                        @click="printInvoice"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4" />
                        <span>طباعة الفاتورة</span>
                    </button>
                    <Link 
                        :href="route('purchases.invoices.index')"
                        class="px-4 py-2 border border-slate-200 text-slate-600 bg-white hover:bg-slate-50 rounded-xl text-xs font-semibold transition-colors"
                    >
                        عودة
                    </Link>
                </div>
            </div>

            <!-- Printable Purchase Invoice Sheet -->
            <div id="invoice-sheet" class="bg-white rounded-3xl border border-slate-200/80 p-8 sm:p-12 shadow-sm space-y-8 text-slate-800 print:border-none print:shadow-none print:p-0">
                <!-- Header: Company & Invoice Info -->
                <div class="flex flex-col sm:flex-row justify-between items-start gap-6 border-b border-slate-200 pb-8">
                    <div>
                        <BrandLogo size="md" :showSubtitle="true" />
                        <div class="mt-4 text-xs space-y-1 text-slate-600">
                            <h2 class="text-sm font-black text-slate-900">{{ company?.name || 'مؤسسة أنس التجارية' }}</h2>
                            <p>الرقم الضريبي: <strong class="font-mono">{{ company?.tax_number || '300000000000003' }}</strong></p>
                            <p>السجل التجاري: <strong class="font-mono">{{ company?.commercial_register || '1010000000' }}</strong></p>
                            <p>المستودع المستلم: <strong>{{ invoice.warehouse?.name_ar || 'المستودع الرئيسي' }}</strong></p>
                        </div>
                    </div>

                    <div class="text-start sm:text-end space-y-1">
                        <div class="inline-block px-3 py-1 rounded-xl bg-indigo-50 text-indigo-700 text-xs font-black uppercase tracking-wider mb-2">
                            فاتورة مشتريات (Purchase Bill)
                        </div>
                        <h1 class="text-xl font-mono font-black text-slate-900">{{ invoice.invoice_number }}</h1>
                        <p v-if="invoice.supplier_invoice_number" class="text-xs text-slate-500 font-mono">
                            مرجع المورد: {{ invoice.supplier_invoice_number }}
                        </p>
                        <p class="text-xs text-slate-500">التاريخ: <strong class="font-mono text-slate-800">{{ String(invoice.invoice_date).substring(0, 10) }}</strong></p>
                        <p v-if="invoice.due_date" class="text-xs text-slate-500">الاستحقاق: <strong class="font-mono text-slate-800">{{ String(invoice.due_date).substring(0, 10) }}</strong></p>
                        
                        <div class="pt-2">
                            <span 
                                v-if="invoice.payment_status === 'paid'"
                                class="px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200"
                            >
                                <CheckCircleIcon class="w-4 h-4" />
                                <span>مدفوعة بالكامل</span>
                            </span>
                            <span 
                                v-else-if="invoice.payment_status === 'partially_paid'"
                                class="px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200"
                            >
                                <ClockIcon class="w-4 h-4" />
                                <span>مدفوعة جزئياً</span>
                            </span>
                            <span 
                                v-else
                                class="px-3 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200"
                            >
                                <ExclamationCircleIcon class="w-4 h-4" />
                                <span>مستحقة السداد (غير مدفوعة)</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Supplier Details Card -->
                <div class="bg-slate-50/75 rounded-2xl p-5 border border-slate-200/80">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">بيانات المورد (Supplier Details)</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-slate-400 block text-3xs font-medium">اسم المورد:</span>
                            <strong class="text-slate-900 text-sm font-black">{{ invoice.supplier?.name }}</strong>
                            <p v-if="invoice.supplier?.company_name" class="text-slate-500 text-2xs">{{ invoice.supplier.company_name }}</p>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-3xs font-medium">الرقم الضريبي:</span>
                            <strong class="font-mono text-slate-800">{{ invoice.supplier?.tax_number || '-' }}</strong>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-3xs font-medium">الهاتف والبريد:</span>
                            <span class="font-mono text-slate-800">{{ invoice.supplier?.phone || '-' }}</span>
                            <span v-if="invoice.supplier?.email" class="block text-3xs text-slate-500">{{ invoice.supplier.email }}</span>
                        </div>
                    </div>
                </div>

                <!-- Invoice Items Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-100/75 border-y border-slate-200 text-slate-700 font-bold">
                                <th class="py-3 px-3">#</th>
                                <th class="py-3 px-4">البند / وصف المشتريات</th>
                                <th class="py-3 px-4 text-center">الكمية</th>
                                <th class="py-3 px-4">سعر الوحدة</th>
                                <th class="py-3 px-4">نسبة الضريبة</th>
                                <th class="py-3 px-4">قيمة الضريبة</th>
                                <th class="py-3 px-4 text-end">الإجمالي شامل الضريبة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="(item, idx) in invoice.items" :key="item.id">
                                <td class="py-3 px-3 font-mono text-slate-400">{{ idx + 1 }}</td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ item.item_name }}
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-bold">
                                    {{ Number(item.quantity) }}
                                </td>
                                <td class="py-3 px-4 font-mono">
                                    {{ formatCurrency(item.unit_price) }} ﷼
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-500">
                                    {{ Number(item.tax_rate) }}%
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-500">
                                    {{ formatCurrency(item.tax_amount) }} ﷼
                                </td>
                                <td class="py-3 px-4 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(item.total) }} ﷼
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals & Payment Summary -->
                <div class="flex flex-col sm:flex-row justify-between items-start gap-6 pt-4 border-t border-slate-200">
                    <div class="text-xs space-y-1 max-w-sm">
                        <span class="text-slate-400 font-bold block">ملاحظات وشروط الفاتورة:</span>
                        <p class="text-slate-600 bg-slate-50 p-3 rounded-xl border border-slate-100 italic">
                            {{ invoice.notes || 'تم استلام وتدقيق كافة الأصناف ومطابقتها لأمر الشراء وإدخالها لرصيد المستودع بنجاح.' }}
                        </p>
                    </div>

                    <div class="w-full sm:w-72 bg-slate-50/75 rounded-2xl p-4 border border-slate-200 space-y-2 text-xs">
                        <div class="flex justify-between text-slate-600">
                            <span>المجموع الخاضع للضريبة:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(invoice.subtotal) }} ﷼</span>
                        </div>
                        <div class="flex justify-between text-slate-600">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-mono font-bold text-indigo-600">{{ formatCurrency(invoice.tax_amount) }} ﷼</span>
                        </div>
                        <div class="flex justify-between py-2 border-t border-slate-200 text-sm font-black text-slate-900">
                            <span>الإجمالي شامل الضريبة:</span>
                            <span class="font-mono text-indigo-700">{{ formatCurrency(invoice.total) }} ﷼</span>
                        </div>
                        <div class="flex justify-between pt-1 text-2xs text-emerald-700 font-bold">
                            <span>المبلغ المدفوع:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.paid_amount) }} ﷼</span>
                        </div>
                        <div v-if="invoice.remaining_amount > 0" class="flex justify-between text-2xs text-rose-600 font-bold">
                            <span>المبلغ المتبقي للمورد:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.remaining_amount) }} ﷼</span>
                        </div>
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
    aside, header, nav, .print\:hidden {
        display: none !important;
    }
    #invoice-sheet {
        padding: 0 !important;
        border: none !important;
    }
}
</style>
