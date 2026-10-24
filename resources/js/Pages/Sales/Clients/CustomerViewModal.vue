<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    XMarkIcon,
    PencilSquareIcon,
    DocumentTextIcon,
    BanknotesIcon,
    PlusIcon,
    DocumentIcon,
    ArrowTopRightOnSquareIcon,
    PrinterIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    customer: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'edit', 'openStatement']);

// Active Tab: 'summary' (الملخص) | 'invoices' (الفواتير) | 'payments' (المدفوعات)
const activeTab = ref('summary');

const formatCurrency = (val) => {
    if (val === null || val === undefined || Number(val) === 0) return '-';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ﷼';
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    try {
        return new Date(dateStr).toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return dateStr;
    }
};

const getStatusBadge = (status) => {
    switch (status) {
        case 'paid':
            return { text: 'مدفوعة', class: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
        case 'partially_paid':
            return { text: 'مدفوعة جزئياً', class: 'bg-amber-50 text-amber-700 border-amber-200' };
        case 'unpaid':
            return { text: 'غير مدفوعة', class: 'bg-rose-50 text-rose-700 border-rose-200' };
        default:
            return { text: status || 'معلقة', class: 'bg-slate-50 text-slate-700 border-slate-200' };
    }
};
</script>

<template>
    <div
        v-if="show && customer"
        class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4 animate-in fade-in duration-200"
    >
        <div
            class="bg-white rounded-2xl w-full max-w-5xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[94vh]"
            dir="rtl"
        >
            <!-- Top Header Bar -->
            <div class="px-6 py-3.5 border-b border-slate-100 flex items-center justify-between bg-white">
                <h2 class="text-xl font-bold text-slate-800">عرض عميل</h2>
                <button
                    type="button"
                    @click="emit('close')"
                    class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-lg transition-colors"
                >
                    <XMarkIcon class="w-5 h-5" />
                </button>
            </div>

            <!-- Tabs Navigation Bar (Matching Screenshot 1) -->
            <div class="px-6 border-b border-slate-200 bg-white flex items-center justify-start gap-8">
                <!-- Summary Tab (الملخص) -->
                <button
                    type="button"
                    @click="activeTab = 'summary'"
                    class="py-3 text-sm font-bold border-b-2 transition-colors relative"
                    :class="[
                        activeTab === 'summary'
                            ? 'text-indigo-600 border-indigo-600'
                            : 'text-slate-500 border-transparent hover:text-slate-800'
                    ]"
                >
                    الملخص
                </button>

                <!-- Invoices Tab (الفواتير) -->
                <button
                    type="button"
                    @click="activeTab = 'invoices'"
                    class="py-3 text-sm font-bold border-b-2 transition-colors relative flex items-center gap-2"
                    :class="[
                        activeTab === 'invoices'
                            ? 'text-indigo-600 border-indigo-600'
                            : 'text-slate-500 border-transparent hover:text-slate-800'
                    ]"
                >
                    <span>الفواتير</span>
                    <span
                        v-if="customer.invoices && customer.invoices.length > 0"
                        class="text-2xs px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-mono"
                    >
                        {{ customer.invoices.length }}
                    </span>
                </button>

                <!-- Payments Tab (المدفوعات) -->
                <button
                    type="button"
                    @click="activeTab = 'payments'"
                    class="py-3 text-sm font-bold border-b-2 transition-colors relative flex items-center gap-2"
                    :class="[
                        activeTab === 'payments'
                            ? 'text-indigo-600 border-indigo-600'
                            : 'text-slate-500 border-transparent hover:text-slate-800'
                    ]"
                >
                    <span>المدفوعات</span>
                    <span
                        v-if="customer.payments && customer.payments.length > 0"
                        class="text-2xs px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-mono"
                    >
                        {{ customer.payments.length }}
                    </span>
                </button>
            </div>

            <!-- Scrollable Content Area -->
            <div class="overflow-y-auto p-6 sm:p-8 flex-1 bg-white space-y-6">
                <!-- TAB 1: SUMMARY (الملخص - Exactly matching Screenshot 1) -->
                <div v-if="activeTab === 'summary'" class="space-y-6">
                    <!-- Top Cards Bar: Balance & Account Statement -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Balance Card (الرصيد) -->
                        <div class="p-4 rounded-xl border border-slate-200 bg-white shadow-2xs flex flex-col items-center justify-center text-center">
                            <span class="text-xs font-bold text-slate-500 mb-1">الرصيد</span>
                            <span
                                class="text-2xl font-black font-mono"
                                :class="[
                                    Number(customer.current_balance) > 0
                                        ? 'text-rose-600'
                                        : Number(customer.current_balance) < 0
                                        ? 'text-emerald-600'
                                        : 'text-slate-400'
                                ]"
                            >
                                {{ formatCurrency(customer.current_balance) }}
                            </span>
                        </div>

                        <!-- Statement of Account Card (كشف حساب) -->
                        <div class="p-4 rounded-xl border border-slate-200 bg-white shadow-2xs flex flex-col items-center justify-center text-center">
                            <span class="text-xs font-bold text-slate-500 mb-1">كشف حساب</span>
                            <Link
                                :href="route('sales.clients.statement', customer.id)"
                                class="text-base sm:text-lg font-bold text-slate-800 hover:text-indigo-600 transition-colors inline-flex items-center gap-2"
                                title="عرض كشف الحساب التفصيلي"
                            >
                                <span>#{{ customer.code || ('1-' + String(customer.id).padStart(6, '0')) }}</span>
                                <span>{{ customer.name }}</span>
                                <ArrowTopRightOnSquareIcon class="w-4 h-4 text-slate-400" />
                            </Link>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 my-4"></div>

                    <!-- Details Two-Columns Grid (Matching Screenshot 1) -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                        
                        <!-- RIGHT COLUMN: Type, Code, Name, Tax, CR, Notes, Attachments -->
                        <div class="space-y-4">
                            <!-- Type (النوع) -->
                            <div class="flex items-center justify-between pb-1">
                                <span class="text-xs font-bold text-slate-700">النوع</span>
                                <div class="flex items-center gap-6">
                                    <label class="inline-flex items-center gap-2">
                                        <input
                                            type="radio"
                                            disabled
                                            :checked="customer.customer_type === 'individual'"
                                            class="w-4 h-4 text-indigo-600 border-slate-300"
                                        />
                                        <span class="text-sm font-medium text-slate-700">فردي</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2">
                                        <input
                                            type="radio"
                                            disabled
                                            :checked="customer.customer_type !== 'individual'"
                                            class="w-4 h-4 text-indigo-600 border-slate-300"
                                        />
                                        <span class="text-sm font-medium text-slate-700">تجاري</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Code (الكود) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">الكود</label>
                                <input
                                    readonly
                                    :value="customer.code || ('1-' + String(customer.id).padStart(6, '0'))"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 font-mono text-slate-700"
                                />
                            </div>

                            <!-- Name (الاسم *) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">
                                    الاسم <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    readonly
                                    :value="customer.name"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 font-semibold text-slate-800"
                                />
                            </div>

                            <!-- Commercial Registration & Tax Number (Row) -->
                            <div v-if="customer.customer_type !== 'individual'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <!-- CR (السجل التجاري) -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">السجل التجاري</label>
                                    <input
                                        readonly
                                        :value="customer.commercial_registration || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 font-mono text-slate-700"
                                    />
                                </div>

                                <!-- Tax Number (الرقم الضريبي) -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي</label>
                                    <input
                                        readonly
                                        :value="customer.tax_number || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-indigo-300 px-3 py-2 bg-indigo-50/20 font-mono text-indigo-900 font-bold"
                                    />
                                </div>
                            </div>

                            <!-- Notes (ملاحظات) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                                <textarea
                                    readonly
                                    rows="3"
                                    :value="customer.notes || 'لا توجد ملاحظات مسجلة'"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-600 resize-none"
                                ></textarea>
                            </div>

                            <!-- Attachments (المرفقات) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">المرفقات</label>
                                <div class="border-2 border-dashed border-slate-200 rounded-xl p-6 text-center bg-slate-50/50">
                                    <div v-if="customer.attachments && customer.attachments.length > 0" class="space-y-2">
                                        <div
                                            v-for="(att, i) in customer.attachments"
                                            :key="i"
                                            class="flex items-center justify-between p-2 rounded-lg bg-white border border-slate-200 text-xs"
                                        >
                                            <div class="flex items-center gap-2 truncate">
                                                <DocumentIcon class="w-4 h-4 text-indigo-500 shrink-0" />
                                                <span class="truncate font-medium text-slate-700">{{ att.name }}</span>
                                            </div>
                                            <a
                                                :href="att.url"
                                                target="_blank"
                                                class="text-indigo-600 hover:text-indigo-800 font-bold flex items-center gap-1"
                                            >
                                                <span>عرض</span>
                                                <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                                            </a>
                                        </div>
                                    </div>
                                    <div v-else class="text-xs text-slate-400">
                                        لا توجد مرفقات مرفوعة لهذا العميل
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- LEFT COLUMN: Contact Information -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-bold text-slate-800">بيانات الاتصال</h3>

                            <!-- Contact Name (اسم جهة الاتصال) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">اسم جهة الاتصال</label>
                                <input
                                    readonly
                                    :value="customer.contact_name || customer.company_name || '-'"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                />
                            </div>

                            <!-- Checkboxes Display -->
                            <div class="flex items-center gap-6 py-1 border-b border-slate-100">
                                <label class="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked
                                        disabled
                                        class="rounded text-indigo-600 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">الهاتف</span>
                                </label>

                                <label class="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked
                                        disabled
                                        class="rounded text-indigo-600 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">بريد إلكتروني</span>
                                </label>

                                <label class="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked
                                        disabled
                                        class="rounded text-indigo-600 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">عنوان</span>
                                </label>
                            </div>

                            <!-- Phone & Mobile -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الهاتف</label>
                                    <input
                                        readonly
                                        :value="customer.phone || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700 font-mono"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الجوال</label>
                                    <input
                                        readonly
                                        :value="customer.mobile || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700 font-mono"
                                    />
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">بريد إلكتروني</label>
                                <input
                                    readonly
                                    :value="customer.email || '-'"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                />
                            </div>

                            <!-- Building Number & Street Name -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">رقم المبنى - اسم الشارع</label>
                                <input
                                    readonly
                                    :value="[customer.building_number, customer.street_name].filter(Boolean).join(' - ') || customer.address || '-'"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                />
                            </div>

                            <!-- Additional Details -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الإضافي</label>
                                <input
                                    readonly
                                    :value="customer.additional_details || '-'"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                />
                            </div>

                            <!-- District & City -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">حي</label>
                                    <input
                                        readonly
                                        :value="customer.district || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">مدينة</label>
                                    <input
                                        readonly
                                        :value="customer.city || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                    />
                                </div>
                            </div>

                            <!-- Postal Code & Province -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">رمز بريدي</label>
                                    <input
                                        readonly
                                        :value="customer.postal_code || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700 font-mono"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">محافظة</label>
                                    <input
                                        readonly
                                        :value="customer.province || customer.city || '-'"
                                        type="text"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                    />
                                </div>
                            </div>

                            <!-- Country -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">دولة</label>
                                <input
                                    readonly
                                    :value="customer.country || 'المملكة العربية السعودية'"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-slate-50 text-slate-700"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: INVOICES (الفواتير) -->
                <div v-else-if="activeTab === 'invoices'" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-800">فواتير العميل</h3>
                        <Link
                            v-if="$canRoute('sales.invoices.create')"
                            :href="route('sales.invoices.create')"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs transition-colors"
                        >
                            <PlusIcon class="w-4 h-4 stroke-2" />
                            <span>إصدار فاتورة جديدة</span>
                        </Link>
                    </div>

                    <!-- Invoices Table -->
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-2xs">
                        <div class="overflow-x-auto">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead>
                                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-500 font-bold">
                                        <th class="py-3 px-4">رقم الفاتورة</th>
                                        <th class="py-3 px-4">تاريخ الإصدار</th>
                                        <th class="py-3 px-4">تاريخ الاستحقاق</th>
                                        <th class="py-3 px-4">الإجمالي</th>
                                        <th class="py-3 px-4">الحالة</th>
                                        <th class="py-3 px-4 text-center">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="inv in customer.invoices"
                                        :key="inv.id"
                                        class="hover:bg-slate-50/60 transition-colors"
                                    >
                                        <td class="py-3 px-4 font-bold font-mono text-indigo-600">
                                            {{ inv.invoice_number }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-600">
                                            {{ formatDate(inv.invoice_date) }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-500">
                                            {{ formatDate(inv.due_date) }}
                                        </td>
                                        <td class="py-3 px-4 font-mono font-bold text-slate-800">
                                            {{ formatCurrency(inv.total_amount) }}
                                        </td>
                                        <td class="py-3 px-4">
                                            <span
                                                :class="[
                                                    'inline-flex items-center px-2 py-0.5 rounded-full text-2xs font-semibold border',
                                                    getStatusBadge(inv.payment_status || inv.status).class
                                                ]"
                                            >
                                                {{ getStatusBadge(inv.payment_status || inv.status).text }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <Link
                                                :href="route('sales.invoices.show', inv.id)"
                                                class="text-indigo-600 hover:text-indigo-800 font-bold underline"
                                            >
                                                عرض الفاتورة
                                            </Link>
                                        </td>
                                    </tr>

                                    <tr v-if="!customer.invoices || customer.invoices.length === 0">
                                        <td colspan="6" class="py-8 text-center text-slate-400">
                                            لا توجد أية فواتير مسجلة لهذا العميل حتى الآن
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: PAYMENTS (المدفوعات) -->
                <div v-else-if="activeTab === 'payments'" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-800">سندات القبض والمدفوعات</h3>
                    </div>

                    <!-- Payments Table -->
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-2xs">
                        <div class="overflow-x-auto">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead>
                                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-500 font-bold">
                                        <th class="py-3 px-4">رقم السند</th>
                                        <th class="py-3 px-4">التاريخ</th>
                                        <th class="py-3 px-4">طريقة الدفع</th>
                                        <th class="py-3 px-4">المبلغ</th>
                                        <th class="py-3 px-4">المرجع / ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="pmt in customer.payments"
                                        :key="pmt.id"
                                        class="hover:bg-slate-50/60 transition-colors"
                                    >
                                        <td class="py-3 px-4 font-bold font-mono text-emerald-600">
                                            {{ pmt.payment_number || ('#PAY-' + pmt.id) }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-600">
                                            {{ formatDate(pmt.payment_date) }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-700">
                                            {{ pmt.payment_method === 'bank_transfer' ? 'تحويل بنكي' : (pmt.payment_method === 'cash' ? 'نقداً' : (pmt.payment_method || 'أخرى')) }}
                                        </td>
                                        <td class="py-3 px-4 font-mono font-black text-emerald-600">
                                            {{ formatCurrency(pmt.amount) }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-500">
                                            {{ pmt.reference_number || pmt.notes || '-' }}
                                        </td>
                                    </tr>

                                    <tr v-if="!customer.payments || customer.payments.length === 0">
                                        <td colspan="5" class="py-8 text-center text-slate-400">
                                            لا توجد أية دفعات مسجلة لهذا العميل حتى الآن
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Actions Bar -->
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="emit('edit', customer); emit('close')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold shadow-xs transition-colors"
                    >
                        <PencilSquareIcon class="w-4 h-4" />
                        <span>تعديل بيانات العميل</span>
                    </button>

                    <Link
                        :href="route('sales.clients.statement', customer.id)"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-sm font-medium transition-colors"
                    >
                        <PrinterIcon class="w-4 h-4 text-slate-500" />
                        <span>طباعة كشف الحساب</span>
                    </Link>
                </div>

                <button
                    type="button"
                    @click="emit('close')"
                    class="px-5 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-sm font-medium transition-colors"
                >
                    إغلاق
                </button>
            </div>
        </div>
    </div>
</template>
