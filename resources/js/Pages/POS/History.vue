<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import QRCode from 'qrcode';
import {
    ShoppingBagIcon,
    PrinterIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    XMarkIcon,
    EyeIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    orders: Object,
});

const isReceiptModalOpen = ref(false);
const activeOrder = ref(null);
const receiptQrUrl = ref('');

const viewReceipt = async (order) => {
    activeOrder.value = order;
    if (order.zatca_qr) {
        try {
            receiptQrUrl.value = await QRCode.toDataURL(order.zatca_qr, {
                width: 140,
                margin: 1,
                color: { dark: '#000000', light: '#ffffff' },
            });
        } catch (e) {
            console.error('QR code error', e);
        }
    }
    isReceiptModalOpen.value = true;
};

const printReceipt = () => {
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
    <AppLayout title="سجل فواتير نقاط البيع">
        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs">
                <div>
                    <h1 class="text-xl font-black text-slate-800 flex items-center gap-2">
                        <ShoppingBagIcon class="w-6 h-6 text-indigo-600" />
                        <span>سجل عمليات نقاط البيع (POS Orders)</span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        استعراض وإعادة طباعة فواتير مبيعات نقاط البيع وإيصالات الزكاة والضريبة المبسطة
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('pos.index')"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors flex items-center gap-1.5"
                    >
                        <span>الذهاب إلى نقطة البيع</span>
                    </Link>
                </div>
            </div>

            <!-- Orders Table Card -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200/80 text-slate-500 font-bold">
                                <th class="py-3.5 px-4">رقم الطلب</th>
                                <th class="py-3.5 px-4">العميل</th>
                                <th class="py-3.5 px-4">الكاشير</th>
                                <th class="py-3.5 px-4">نقطة البيع</th>
                                <th class="py-3.5 px-4">طريقة الدفع</th>
                                <th class="py-3.5 px-4">المجموع الكلي</th>
                                <th class="py-3.5 px-4">التاريخ والوقت</th>
                                <th class="py-3.5 px-4 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="order in orders.data" 
                                :key="order.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600">
                                    {{ order.order_number }}
                                </td>
                                <td class="py-3.5 px-4 font-medium">
                                    {{ order.customer?.name || 'عميل نقدي (عام)' }}
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    {{ order.user?.name || '-' }}
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    {{ order.session?.register?.name || 'جهاز نقاط البيع' }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <span 
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-3xs font-bold inline-block',
                                            order.payment_method === 'cash' 
                                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/60' 
                                                : 'bg-purple-50 text-purple-700 border border-purple-200/60'
                                        ]"
                                    >
                                        {{ ({ cash: 'نقداً (Cash)', card: 'مدى / بطاقة', multiple: 'مجزأ' })[order.payment_method] || order.payment_method }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-900">
                                    {{ formatCurrency(order.total) }} <span class="text-3xs text-slate-500 font-normal">﷼</span>
                                </td>
                                <td class="py-3.5 px-4 text-slate-500 font-mono text-3xs">
                                    {{ new Date(order.created_at).toLocaleString('ar-SA') }}
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <button
                                        @click="viewReceipt(order)"
                                        class="px-2.5 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-medium text-3xs transition-colors inline-flex items-center gap-1"
                                        title="عرض وطباعة الإيصال الحراري"
                                    >
                                        <PrinterIcon class="w-3.5 h-3.5" />
                                        <span>عرض الإيصال</span>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!orders.data || orders.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا توجد عمليات نقاط بيع مسجلة حتى الآن.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Receipt Modal -->
            <div 
                v-if="isReceiptModalOpen && activeOrder"
                class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-4 shadow-2xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 print:hidden">
                        <span class="text-xs font-bold text-slate-800">إيصال نقطة بيع مبسط</span>
                        <button @click="isReceiptModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <div id="thermal-receipt" class="p-4 border border-dashed border-slate-300 rounded-xl text-center text-xs space-y-3 bg-white text-slate-900 font-mono">
                        <div class="space-y-1">
                            <h2 class="font-black text-sm text-slate-900">مؤسسة أنس التجارية</h2>
                            <p class="text-3xs text-slate-500">فاتورة ضريبية مبسطة | SIMPLIFIED TAX INVOICE</p>
                            <p class="text-3xs text-slate-600 font-bold">الرقم الضريبي: 300000000000003</p>
                        </div>

                        <div class="border-t border-slate-200 pt-2 text-3xs text-start space-y-0.5">
                            <p>رقم الفاتورة: <strong>{{ activeOrder.order_number }}</strong></p>
                            <p>التاريخ: {{ new Date(activeOrder.created_at).toLocaleString('ar-SA') }}</p>
                            <p>طريقة الدفع: {{ ({ cash: 'نقداً', card: 'بطاقة مدى', multiple: 'مجزأ (نقد + بطاقة)' })[activeOrder.payment_method] || activeOrder.payment_method }}</p>
                        </div>

                        <div class="border-t border-slate-200 pt-2 text-3xs space-y-1 text-start">
                            <div class="flex justify-between">
                                <span>المجموع الخاضع للضريبة:</span>
                                <span>{{ formatCurrency(activeOrder.subtotal) }} ﷼</span>
                            </div>
                            <div class="flex justify-between">
                                <span>{{ $taxLabel() }}:</span>
                                <span>{{ formatCurrency(activeOrder.tax_amount) }} ﷼</span>
                            </div>
                            <div class="flex justify-between font-bold text-xs pt-1 border-t border-slate-200">
                                <span>المجموع الكلي:</span>
                                <span>{{ formatCurrency(activeOrder.total) }} ﷼</span>
                            </div>
                        </div>

                        <div v-if="receiptQrUrl" class="pt-2 flex flex-col items-center">
                            <img :src="receiptQrUrl" alt="ZATCA QR" class="w-28 h-28 mx-auto" />
                            <span class="text-4xs text-slate-400 mt-1">رمز التحقق الإلكتروني ZATCA</span>
                        </div>
                    </div>

                    <div class="pt-2 flex items-center gap-2">
                        <button 
                            @click="printReceipt"
                            class="flex-1 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs flex items-center justify-center gap-2"
                        >
                            <PrinterIcon class="w-4 h-4" />
                            <span>طباعة</span>
                        </button>
                        <button 
                            @click="isReceiptModalOpen = false"
                            class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs"
                        >
                            إغلاق
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
