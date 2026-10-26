<script setup>
import { cur } from '@/utils/tax';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import BrandLogo from '../../../Components/BrandLogo.vue';
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
    ArrowUturnLeftIcon,
    PaintBrushIcon,
    ChevronDownIcon,
    XMarkIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
    company: Object,
    template: Object,
    templates: Array,
    zatcaPhase: String,
});

const currentTemplateId = ref(props.template?.id || props.templates?.[0]?.id || null);

const currentTemplate = computed(() => {
    return props.templates?.find(t => t.id === currentTemplateId.value) || props.template || {};
});

// Free-form designs print from the server PDF, which is laid out exactly as designed.
const invoiceTemplates = computed(() => (props.templates || []).filter((t) => ['invoice', 'invoice_a4', 'invoice_thermal_80mm'].includes(t.template_type)));
const isFreeform = computed(() => currentTemplate.value?.layout_data?.mode === 'freeform');
const freeformPdf = computed(() => route('documents.pdf', { type: 'invoice', id: props.invoice.id, template: currentTemplate.value?.id }));

// Foreign-currency invoices show their currency and the base equivalent.
const curSymbol = computed(() => props.invoice?.currency_code || cur());
const baseTotal = computed(() => props.invoice?.currency_code ? Number(props.invoice.total) * Number(props.invoice.exchange_rate || 1) : null);
const isLogoBroken = ref(false);
const companyLogoUrl = computed(() => {
    return props.company?.logo_url || props.company?.logo || null;
});

const layout = computed(() => {
    const tmpl = currentTemplate.value;
    const def = {
        design: {
            primary_color: tmpl?.primary_color || '#1e293b',
            secondary_color: '#64748b',
            font_family: 'Cairo',
            font_size_base: 12,
            border_style: 'solid',
        },
        page: {
            paper_size: tmpl?.template_type === 'invoice_thermal_80mm' ? 'thermal_80mm' : 'A4',
            orientation: 'portrait',
            margin_top: 15,
            margin_bottom: 15,
            margin_left: 15,
            margin_right: 15,
            show_page_number: true,
        },
        logo: {
            show: tmpl?.show_logo ?? true,
            position: 'right',
            height: 85,
            max_width: 280,
        },
        qr: {
            show: tmpl?.show_qr ?? true,
            position: 'left',
            size: 100,
        },
        header: {
            layout_order: 'logo_meta_qr',
            show_divider_line: true,
            title: {
                text: 'فاتورة ضريبية',
                tag: '{{zatca.invoice.title.ar}}',
                font_size: 14,
                color: '#0f172a',
                alignment: 'center',
                is_bold: true,
            },
            meta_fields: [
                { id: 'code', label: 'رقم الفاتورة', tag: '{{invoice.code}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: true },
                { id: 'date', label: 'تاريخ الإصدار', tag: '{{invoice.issue_date}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: false },
                { id: 'ref', label: 'الرقم المرجعي', tag: '{{invoice.partner_reference_code}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: false },
                { id: 'payment_status', label: 'حالة الدفع', tag: '{{invoice.payments.status.ar}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: false },
                { id: 'payment_method', label: 'طريقة الدفع', tag: '{{invoice.payments.payment_method_names}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: false },
            ],
        },
        company: {
            show_name: true,
            show_vat: true,
            show_cr: true,
            show_address: true,
            show_phone: true,
            show_email: false,
        },
        customer: {
            show_name: true,
            show_vat: true,
            show_address: true,
            show_phone: true,
            show_ref: true,
        },
        table: {
            header_bg: '#f8fafc',
            header_text_color: '#1e293b',
            columns: [
                { key: 'index', label: 'البند', visible: true, width: '8%', align: 'center' },
                { key: 'description', label: 'الوصف / البيان', visible: true, width: '42%', align: 'right' },
                { key: 'unit_price', label: 'السعر', visible: true, width: '15%', align: 'center' },
                { key: 'quantity', label: 'الكمية', visible: true, width: '15%', align: 'center' },
                { key: 'total', label: 'المجموع', visible: true, width: '20%', align: 'center' },
            ],
        },
        footer: {
            show_subtotal: true,
            show_vat_15: true,
            show_grand_total: true,
            show_paid: true,
            show_due: true,
            show_tafqeet: true,
            show_bank_details: tmpl?.show_bank_details ?? true,
            show_terms: true,
            show_notes: true,
            show_signature: true,
            show_stamp: tmpl?.show_stamp ?? true,
        },
    };

    if (tmpl?.layout_data && typeof tmpl.layout_data === 'object') {
        return {
            ...def,
            ...tmpl.layout_data,
            design: { ...def.design, ...(tmpl.layout_data.design || {}) },
            page: { ...def.page, ...(tmpl.layout_data.page || {}) },
            logo: { ...def.logo, ...(tmpl.layout_data.logo || {}) },
            qr: { ...def.qr, ...(tmpl.layout_data.qr || {}) },
            header: { ...def.header, ...(tmpl.layout_data.header || {}) },
            company: { ...def.company, ...(tmpl.layout_data.company || {}) },
            customer: { ...def.customer, ...(tmpl.layout_data.customer || {}) },
            table: { ...def.table, ...(tmpl.layout_data.table || {}) },
            footer: { ...def.footer, ...(tmpl.layout_data.footer || {}) },
        };
    }
    return def;
});

const headerLayout = computed(() => {
    const logoPos = layout.value?.logo?.position || 'right';
    const qrPos = layout.value?.qr?.position || 'left';
    const order = layout.value?.header?.layout_order || 'logo_meta_qr';

    if (logoPos === 'center' || order === 'meta_logo_qr') {
        return {
            mode: 'center_logo',
            right: 'meta',
            center: 'logo_title',
            left: 'qr',
        };
    }

    if (qrPos === 'center' || order === 'logo_qr_meta') {
        return {
            mode: 'center_qr',
            right: logoPos === 'left' ? 'meta' : 'logo',
            center: 'qr_title',
            left: logoPos === 'left' ? 'logo' : 'meta',
        };
    }

    if (logoPos === 'left' || qrPos === 'right' || order === 'qr_meta_logo') {
        return {
            mode: 'logo_left_qr_right',
            right: 'qr_meta',
            center: 'title',
            left: 'logo',
        };
    }

    return {
        mode: 'logo_right_qr_left',
        right: 'logo',
        center: 'title',
        left: 'qr_meta',
    };
});

const isThermal = computed(() => {
    return currentTemplate.value?.template_type === 'invoice_thermal_80mm' || layout.value?.page?.paper_size === 'thermal_80mm';
});

const qrCodeDataUrl = ref('');

const renderQR = async () => {
    if (props.invoice.zatca_qr) {
        try {
            const qrPx = (Number(layout.value?.qr?.size) || 100) * 2;
            qrCodeDataUrl.value = await QRCode.toDataURL(props.invoice.zatca_qr, {
                width: qrPx,
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
};

onMounted(async () => {
    await renderQR();
    updatePrintStyles();
});

watch([() => layout.value?.qr?.size, () => currentTemplateId.value], () => {
    renderQR();
});

const updatePrintStyles = () => {
    if (typeof document === 'undefined') return;
    let el = document.getElementById('dynamic-invoice-print-style');
    if (!el) {
        el = document.createElement('style');
        el.id = 'dynamic-invoice-print-style';
        document.head.appendChild(el);
    }
    if (isThermal.value) {
        el.innerHTML = `
            @media print {
                @page {
                    size: 80mm auto;
                    margin: 0;
                }
                html, body {
                    width: 80mm !important;
                    max-width: 80mm !important;
                    margin: 0 auto !important;
                    padding: 0 !important;
                    background: #ffffff !important;
                }
                #printable-invoice {
                    width: 76mm !important;
                    max-width: 76mm !important;
                    margin: 0 auto !important;
                    padding: 2mm !important;
                    border: none !important;
                    box-shadow: none !important;
                }
            }
        `;
    } else {
        el.innerHTML = `
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 8mm;
                }
                html, body {
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #ffffff !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }
                #printable-invoice {
                    width: 100% !important;
                    max-width: 100% !important;
                    border: none !important;
                    box-shadow: none !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    direction: rtl !important;
                }
                .invoice-header-grid {
                    display: grid !important;
                    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                    align-items: start !important;
                    gap: 1rem !important;
                }
                .invoice-parties-grid {
                    display: grid !important;
                    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                    gap: 1.5rem !important;
                }
            }
        `;
    }
};

watch([isThermal, currentTemplateId], () => {
    updatePrintStyles();
}, { immediate: true });

onUnmounted(() => {
    if (typeof document !== 'undefined') {
        document.getElementById('dynamic-invoice-print-style')?.remove();
    }
});

const showPrintModal = ref(false);

const switchTemplate = (id) => {
    currentTemplateId.value = Number(id);
};

const triggerPrint = () => {
    if (isFreeform.value) {
        window.open(freeformPdf.value, '_blank');
        return;
    }
    window.print();
};

const printWithTemplate = (id) => {
    currentTemplateId.value = Number(id);
    showPrintModal.value = false;
    if (isFreeform.value) {
        window.open(freeformPdf.value, '_blank');
        return;
    }
    setTimeout(() => {
        window.print();
    }, 200);
};

const getTemplateBadge = (type) => {
    switch (type) {
        case 'invoice_thermal_80mm':
            return { label: 'كاشير حراري (80mm)', color: 'bg-amber-50 text-amber-800 border-amber-200' };
        case 'quotation':
            return { label: 'عرض أسعار (A4)', color: 'bg-blue-50 text-blue-800 border-blue-200' };
        default:
            return { label: 'فاتورة ضريبية (A4 ZATCA)', color: 'bg-indigo-50 text-indigo-800 border-indigo-200' };
    }
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const formatDateClean = (dateStr) => {
    if (!dateStr) return '-';
    try {
        const str = String(dateStr);
        return str.includes('T') ? str.split('T')[0] : str;
    } catch {
        return dateStr;
    }
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

const resolveTag = (tag) => {
    if (!tag) return '';
    const inv = props.invoice || {};
    const comp = props.company || {};
    const cust = inv.customer || {};

    const dictionary = {
        '{{invoice.code}}': inv.invoice_number || '-',
        '{{invoice.issue_date}}': formatDateClean(inv.invoice_date),
        '{{invoice.partner_reference_code}}': inv.reference_number || '-',
        '{{invoice.payments.status.ar}}': inv.payment_status === 'paid' ? 'مدفوعة' : (inv.payment_status === 'partially_paid' ? 'مدفوعة جزئياً' : 'مستحقة'),
        '{{invoice.payments.payment_method_names}}': inv.payment_method === 'cash' ? 'نقدي' : (inv.payment_method === 'bank_transfer' ? 'تحويل بنكي' : 'بطاقة مدى'),
        '{{zatca.invoice.title.ar}}': 'فاتورة ضريبية',
        '{{company.name}}': comp.name || '-',
        '{{company.tax_number}}': comp.tax_number || '-',
        '{{company.cr}}': comp.commercial_register || comp.commercial_registration || '-',
        '{{customer.name}}': cust.name || 'عميل نقدي',
        '{{customer.tax_number}}': cust.tax_number || '-',
        '{{customer.address}}': cust.address || cust.city || '-',
    };

    let result = tag;
    for (const [key, val] of Object.entries(dictionary)) {
        result = result.replaceAll(key, val);
    }
    return result;
};
</script>

<template>
    <AppLayout>
        <Head :title="`فاتورة رقم ${invoice.invoice_number}`" />

        <div class="space-y-5 max-w-5xl mx-auto print:space-y-0 print:max-w-none print:w-full print:m-0 print:p-0">
            
            <!-- Top Action Ribbon (Hidden on Print) -->
            <div class="print:hidden flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                
                <!-- Invoice Info & Back button -->
                <div class="flex items-center gap-3">
                    <Link 
                        :href="route('sales.invoices.index')"
                        class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                        title="العودة للفواتير"
                    >
                        <ArrowRightIcon class="w-4 h-4 rtl:rotate-180" />
                    </Link>
                    <div>
                        <h1 class="text-sm font-black text-slate-900 flex items-center gap-2">
                            <span>فاتورة ضريبية:</span>
                            <span class="font-mono text-indigo-600">{{ invoice.invoice_number }}</span>
                        </h1>
                        <p class="text-2xs text-slate-400">
                            تاريخ الإصدار: {{ formatDateArabic(invoice.invoice_date) }}
                        </p>
                    </div>
                </div>

                <!-- Template Selector & Action Controls -->
                <div class="flex flex-wrap items-center gap-2.5">
                    
                    <!-- Template Selector Dropdown -->
                    <div class="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl">
                        <label class="text-3xs font-bold text-slate-500 whitespace-nowrap">قالب الطباعة:</label>
                        <select 
                            :value="currentTemplate.id"
                            @change="switchTemplate($event.target.value)"
                            class="bg-transparent text-xs font-semibold text-slate-800 border-none p-0 focus:ring-0 cursor-pointer"
                        >
                            <option v-for="t in invoiceTemplates" :key="t.id" :value="t.id">
                                {{ t.name }} {{ t.is_default ? '★ (الافتراضي)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Customize in Designer Button -->
                    <Link 
                        v-if="currentTemplate?.id"
                        :href="route('templates.designer', currentTemplate.id)"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition"
                        title="تخصيص هذا القالب في المصمم المرئي"
                    >
                        <PaintBrushIcon class="w-4 h-4 text-indigo-600 stroke-2" />
                        <span class="hidden sm:inline">تعديل القالب</span>
                    </Link>

                    <!-- Issue Credit Note / Return Button -->
                    <Link 
                        v-if="$canRoute('sales.returns.create')"
                        :href="route('sales.returns.create', { invoice_id: invoice.id })"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 font-bold text-xs transition"
                    >
                        <ArrowUturnLeftIcon class="w-4 h-4 stroke-2" />
                        <span class="hidden sm:inline">مرتجع</span>
                    </Link>

                    <Link
                        v-if="$canRoute('sales.recurring.store')"
                        :href="route('sales.recurring.index', { from_invoice: invoice.id })"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"
                        title="فوترة نفس البنود تلقائياً كل شهر / ربع / سنة"
                    >فاتورة دورية</Link>

                    <ShareDocument v-if="$can('sales.invoices.print')" type="invoice" :id="invoice.id" />

                    <!-- Split / Quick Print Button with Dropdown Trigger -->
                    <div class="inline-flex rounded-xl shadow-xs">
                        <button 
                            @click="triggerPrint"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-s-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition"
                            title="طباعة بالقالب المحدد"
                        >
                            <PrinterIcon class="w-4 h-4 stroke-2" />
                            <span>طباعة الفاتورة</span>
                        </button>
                        <button 
                            @click="showPrintModal = true"
                            type="button"
                            class="inline-flex items-center px-2.5 py-2 rounded-e-xl bg-indigo-700 hover:bg-indigo-800 text-white border-s border-indigo-500/50 text-xs transition"
                            title="خيارات وتغيير القالب أثناء الطباعة"
                        >
                            <ChevronDownIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="isFreeform" class="max-w-4xl mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden print:hidden">
                <iframe :src="freeformPdf" title="معاينة الفاتورة بالتصميم الحر" class="w-full" style="height: 1150px"></iframe>
            </div>

            <!-- Dynamic Invoice Sheet Container (Matches Designer layout_data) -->
            <div 
                v-else
                id="printable-invoice" 
                dir="rtl"
                :class="[
                    isThermal 
                        ? 'thermal-receipt-container max-w-[360px] mx-auto bg-white rounded-xl border border-slate-300 shadow-md p-4 text-slate-950 font-sans print:max-w-[76mm] print:w-[76mm] print:p-0 print:border-none print:shadow-none print:m-0' 
                        : 'bg-white rounded-2xl border border-slate-200 shadow-sm p-8 sm:p-12 text-slate-900 space-y-6 print:p-0 print:border-none print:shadow-none max-w-4xl mx-auto'
                ]"
                :style="!isThermal ? {
                    fontFamily: (layout.design.font_family || 'Cairo') + ', sans-serif',
                    fontSize: (layout.design.font_size_base || 12) + 'px',
                    borderTop: `6px solid ${layout.design.primary_color || '#4F46E5'}`
                } : {
                    fontFamily: (layout.design.font_family || 'Cairo') + ', monospace, sans-serif',
                }"
            >
                <!-- ================= 80mm THERMAL POS RECEIPT ================= -->
                <div v-if="isThermal" class="thermal-receipt space-y-3 text-slate-950 print:text-black">
                    <!-- Thermal Store Header -->
                    <div class="text-center space-y-1">
                        <!-- Company Logo -->
                        <div v-if="layout.logo?.show && companyLogoUrl && !isLogoBroken" class="flex justify-center pb-1">
                            <img 
                                :src="companyLogoUrl" 
                                alt="Logo" 
                                :style="{ 
                                    height: Math.min(Number(layout.logo?.height) || 48, 80) + 'px',
                                    maxWidth: '180px'
                                }"
                                class="w-auto object-contain mx-auto" 
                                loading="eager" 
                                @error="isLogoBroken = true" 
                            />
                        </div>
                        <h2 class="text-sm font-black tracking-tight leading-tight">
                            {{ company?.name || $page.props.brand?.name }}
                        </h2>
                        
                        <!-- Simplified Tax Invoice Badge -->
                        <div class="inline-block px-3 py-0.5 rounded border border-slate-800 text-3xs font-black my-1 bg-slate-50">
                            فاتورة ضريبية مبسطة
                        </div>

                        <div class="text-3xs space-y-0.5 text-slate-600 font-medium">
                            <div v-if="company?.commercial_register || company?.commercial_registration">
                                <span>س.ت: </span><span class="font-mono font-bold">{{ company.commercial_register || company.commercial_registration }}</span>
                            </div>
                            <div v-if="company?.tax_number">
                                <span>الرقم الضريبي: </span><span class="font-mono font-bold text-slate-900">{{ company.tax_number }}</span>
                            </div>
                            <div v-if="company?.address">
                                <span>العنوان: </span><span>{{ company.address }}</span>
                            </div>
                            <div v-if="company?.phone">
                                <span>هاتف: </span><span class="font-mono">{{ company.phone }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Dashed Divider -->
                    <div class="border-b border-dashed border-slate-400 my-2"></div>

                    <!-- Invoice & Customer Meta -->
                    <div class="text-3xs space-y-1 font-medium">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">رقم الفاتورة:</span>
                            <span class="font-mono font-bold">{{ invoice.invoice_number }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">التاريخ والوقت:</span>
                            <span class="font-mono">{{ formatDateClean(invoice.invoice_date) }}</span>
                        </div>
                        <div v-if="invoice.reference_number" class="flex justify-between items-center">
                            <span class="text-slate-500">الرقم المرجعي:</span>
                            <span class="font-mono">{{ invoice.reference_number }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">العميل:</span>
                            <span class="font-bold">{{ invoice.customer?.name || 'عميل نقدي' }}</span>
                        </div>
                        <div v-if="invoice.customer?.tax_number" class="flex justify-between items-center">
                            <span class="text-slate-500">الرقم الضريبي للعميل:</span>
                            <span class="font-mono">{{ invoice.customer.tax_number }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">طريقة الدفع:</span>
                            <span>{{ invoice.payment_method === 'cash' ? 'نقدي' : (invoice.payment_method === 'bank_transfer' ? 'تحويل بنكي' : 'بطاقة مدى / شبكة') }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">حالة السداد:</span>
                            <span class="font-bold">{{ invoice.payment_status === 'paid' ? 'مدفوعة بالكامل' : (invoice.payment_status === 'partially_paid' ? 'مدفوعة جزئياً' : 'مستحقة') }}</span>
                        </div>
                    </div>

                    <!-- Dashed Divider -->
                    <div class="border-b border-dashed border-slate-400 my-2"></div>

                    <!-- Items Section -->
                    <div class="space-y-1">
                        <div class="flex justify-between items-center pb-1 border-b border-dashed border-slate-400 text-3xs font-bold text-slate-700">
                            <span>الصنف / البيان</span>
                            <span>الإجمالي</span>
                        </div>
                        <div 
                            v-for="item in invoice.items" 
                            :key="item.id" 
                            class="py-1.5 border-b border-dotted border-slate-200 text-3xs"
                        >
                            <div class="font-bold text-slate-900 leading-snug">
                                {{ item.item_name || item.product?.name || item.description || 'صنف' }}
                            </div>
                            <div class="flex justify-between items-center text-slate-600 mt-1">
                                <span class="font-mono text-slate-500">
                                    {{ Number(item.quantity) }} × {{ formatCurrency(item.unit_price) }}
                                </span>
                                <span class="font-mono font-bold text-slate-900">
                                    {{ formatCurrency(item.total || item.subtotal || (item.quantity * item.unit_price)) }} {{ curSymbol }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Dashed Divider -->
                    <div class="border-b border-dashed border-slate-400 my-2"></div>

                    <!-- Totals Breakdown -->
                    <div class="text-3xs space-y-1">
                        <div class="flex justify-between items-center text-slate-600">
                            <span>المجموع الخاضع للضريبة:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.subtotal) }} {{ curSymbol }}</span>
                        </div>
                        <div v-if="Number(invoice.discount_amount) > 0" class="flex justify-between items-center text-rose-600">
                            <span>الخصم:</span>
                            <span class="font-mono">-{{ formatCurrency(invoice.discount_amount) }} {{ curSymbol }}</span>
                        </div>
                        <div class="flex justify-between items-center text-slate-600">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.tax_amount) }} {{ curSymbol }}</span>
                        </div>
                        
                        <!-- Grand Total Highlighted -->
                        <div class="border-y-2 border-dashed border-slate-800 py-1.5 my-1 flex justify-between items-center text-xs font-black">
                            <span>الإجمالي شامل الضريبة:</span>
                            <span class="font-mono text-sm font-black">{{ formatCurrency(invoice.total ?? invoice.total_amount) }} {{ curSymbol }}</span>
                            <span v-if="baseTotal !== null" class="block text-2xs text-slate-500 font-mono">≈ {{ formatCurrency(baseTotal) }} {{ $cur() }} (سعر {{ Number(invoice.exchange_rate).toFixed(4) }})</span>
                        </div>

                        <div v-if="Number(invoice.paid_amount) > 0" class="flex justify-between items-center text-slate-600 pt-0.5">
                            <span>المبلغ المدفوع:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(invoice.paid_amount) }} {{ curSymbol }}</span>
                        </div>
                        <div v-if="Number(invoice.remaining_amount) > 0" class="flex justify-between items-center text-amber-700 font-bold">
                            <span>المبلغ المتبقي:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.remaining_amount) }} {{ curSymbol }}</span>
                        </div>
                    </div>

                    <!-- Dashed Divider -->
                    <div class="border-b border-dashed border-slate-400 my-2"></div>

                    <!-- ZATCA QR Code (Centered) -->
                    <div v-if="currentTemplate.show_qr !== false" class="text-center py-1">
                        <div class="inline-block p-1 bg-white border border-dashed border-slate-300 rounded-lg">
                            <img 
                                v-if="qrCodeDataUrl" 
                                :src="qrCodeDataUrl" 
                                alt="ZATCA QR" 
                                class="w-32 h-32 object-contain mx-auto"
                            />
                            <div v-else class="w-32 h-32 bg-slate-100 flex items-center justify-center text-3xs text-slate-400">
                                QR Code
                            </div>
                        </div>
                        <p class="text-3xs text-slate-400 mt-1 font-medium">
                            امسح للتحقق من الفاتورة عبر تطبيق زاتكا
                        </p>
                    </div>

                    <!-- Footer Notes & Terms -->
                    <div class="text-center text-3xs text-slate-500 space-y-1 pt-1 border-t border-dotted border-slate-200">
                        <p class="font-bold text-slate-800">
                            {{ currentTemplate.footer_text || 'شكراً لزيارتكم ونسعد بخدمتكم دائماً' }}
                        </p>
                        <p v-if="currentTemplate.terms_and_conditions || invoice.terms" class="text-3xs text-slate-500 leading-tight">
                            {{ currentTemplate.terms_and_conditions || invoice.terms }}
                        </p>
                        <p class="text-3xs text-slate-400 pt-1">
                            {{ $page.props.brand?.name }}
                        </p>
                    </div>
                </div>

                <!-- ================= STANDARD A4 INVOICE ================= -->
                <div v-else class="space-y-6">
                    <!-- TOP HEADER: Header Blocks & Layout Order -->
                    <div>
                        <!-- Dynamic 3-Column Header Grid (Right, Center, Left) -->
                        <div class="invoice-header-grid grid grid-cols-3 items-start gap-4 pb-4">
                            
                            <!-- RIGHT COLUMN (يمين الفاتورة في الواجهة العربية) -->
                            <div :class="[
                                headerLayout.right === 'logo' ? (layout.logo?.position === 'center' ? 'text-center' : 'text-right') : 'text-right',
                                'flex flex-col items-start justify-center'
                            ]">
                                <!-- If right is logo -->
                                <div v-if="headerLayout.right === 'logo' && layout.logo?.show" class="inline-block">
                                    <img 
                                        v-if="companyLogoUrl && !isLogoBroken" 
                                        :src="companyLogoUrl" 
                                        alt="Company Logo" 
                                        :style="{ 
                                            height: (Number(layout.logo?.height) || 85) + 'px',
                                            maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                        }" 
                                        class="object-contain w-auto"
                                        loading="eager"
                                        @error="isLogoBroken = true"
                                    />
                                    <div 
                                        v-else 
                                        :style="{ 
                                            height: (Number(layout.logo?.height) || 85) + 'px',
                                            maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                        }" 
                                        class="px-4 py-2 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center bg-slate-50 text-xs font-bold text-slate-600"
                                    >
                                        <span>{{ company?.name || 'مؤسسة أنس عبدالرحمن' }}</span>
                                    </div>
                                </div>

                                <!-- If right is qr_meta -->
                                <div v-else-if="headerLayout.right === 'qr_meta'" class="flex flex-col items-start gap-2">
                                    <div v-if="layout.qr?.show !== false && currentTemplate.show_qr" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs">
                                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="ZATCA QR Code" :style="{ width: (Number(layout.qr?.size) || 100) + 'px', height: (Number(layout.qr?.size) || 100) + 'px' }" class="object-contain" />
                                        <div v-else :style="{ width: (Number(layout.qr?.size) || 100) + 'px', height: (Number(layout.qr?.size) || 100) + 'px' }" class="bg-slate-100 flex items-center justify-center text-3xs text-slate-400">QR Code</div>
                                    </div>
                                    <div class="mt-1 text-start space-y-1 text-xs">
                                        <div v-for="f in layout.header.meta_fields" :key="f.id" class="flex items-center gap-2" :style="{ fontSize: (f.font_size || 12) + 'px', color: f.color || '#0f172a', fontWeight: f.is_bold ? 'bold' : 'normal', textAlign: f.alignment || 'right' }">
                                            <span class="text-slate-500 font-medium">{{ f.label }}:</span>
                                            <span class="font-mono">{{ resolveTag(f.tag) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- If right is meta (when logo or qr is center) -->
                                <div v-else-if="headerLayout.right === 'meta'" class="space-y-1 text-xs">
                                    <div v-for="f in layout.header.meta_fields" :key="f.id" class="flex items-center gap-2" :style="{ fontSize: (f.font_size || 12) + 'px', color: f.color || '#0f172a', fontWeight: f.is_bold ? 'bold' : 'normal', textAlign: f.alignment || 'right' }">
                                        <span class="text-slate-500 font-medium">{{ f.label }}:</span>
                                        <span class="font-mono font-bold">{{ resolveTag(f.tag) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- CENTER COLUMN (وسط الفاتورة) -->
                            <div class="text-center flex flex-col items-center justify-center">
                                <!-- If center is title -->
                                <div v-if="headerLayout.center === 'title'" class="text-center sm:pt-2">
                                    <h2 :style="{ fontSize: (layout.header.title.font_size || 14) + 'px', color: layout.header.title.color || '#0f172a', fontWeight: layout.header.title.is_bold ? 'bold' : 'normal', textAlign: layout.header.title.alignment || 'center' }" class="tracking-wide">
                                        {{ resolveTag(layout.header.title.tag) || layout.header.title.text || 'فاتورة ضريبية' }}
                                    </h2>
                                    <p class="text-3xs text-slate-400 font-mono mt-0.5">خاضعة لاشتراطات هيئة الزكاة والضريبة والجمارك (ZATCA)</p>
                                </div>

                                <!-- If center is logo_title (Logo in Center) -->
                                <div v-else-if="headerLayout.center === 'logo_title'" class="text-center space-y-2">
                                    <div v-if="layout.logo?.show" class="inline-block">
                                        <img v-if="companyLogoUrl && !isLogoBroken" :src="companyLogoUrl" alt="Company Logo" :style="{ height: (Number(layout.logo?.height) || 85) + 'px', maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px' }" class="object-contain mx-auto" loading="eager" @error="isLogoBroken = true" />
                                        <div v-else :style="{ height: (Number(layout.logo?.height) || 85) + 'px', maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px' }" class="px-4 py-2 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center bg-slate-50 text-xs font-bold text-slate-600">
                                            <span>{{ company?.name || 'مؤسسة أنس عبدالرحمن' }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <h2 :style="{ fontSize: (layout.header.title.font_size || 14) + 'px', color: layout.header.title.color || '#0f172a', fontWeight: layout.header.title.is_bold ? 'bold' : 'normal', textAlign: 'center' }" class="tracking-wide">
                                            {{ resolveTag(layout.header.title.tag) || layout.header.title.text || 'فاتورة ضريبية' }}
                                        </h2>
                                        <p class="text-3xs text-slate-400 font-mono mt-0.5">خاضعة لاشتراطات هيئة الزكاة والضريبة والجمارك (ZATCA)</p>
                                    </div>
                                </div>

                                <!-- If center is qr_title (QR in Center) -->
                                <div v-else-if="headerLayout.center === 'qr_title'" class="text-center space-y-2">
                                    <div v-if="layout.qr?.show !== false && currentTemplate.show_qr" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs inline-block">
                                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="ZATCA QR Code" :style="{ width: (Number(layout.qr?.size) || 100) + 'px', height: (Number(layout.qr?.size) || 100) + 'px' }" class="object-contain" />
                                    </div>
                                    <div>
                                        <h2 :style="{ fontSize: (layout.header.title.font_size || 14) + 'px', color: layout.header.title.color || '#0f172a', fontWeight: layout.header.title.is_bold ? 'bold' : 'normal', textAlign: 'center' }" class="tracking-wide">
                                            {{ resolveTag(layout.header.title.tag) || layout.header.title.text || 'فاتورة ضريبية' }}
                                        </h2>
                                        <p class="text-3xs text-slate-400 font-mono mt-0.5">خاضعة لاشتراطات هيئة الزكاة والضريبة والجمارك (ZATCA)</p>
                                    </div>
                                </div>
                            </div>

                            <!-- LEFT COLUMN (يسار الفاتورة في الواجهة العربية) -->
                            <div :class="[
                                headerLayout.left === 'logo' ? (layout.logo?.position === 'center' ? 'text-center' : 'text-left') : 'text-left',
                                'flex flex-col items-end justify-center'
                            ]">
                                <!-- If left is qr_meta or qr -->
                                <div v-if="headerLayout.left === 'qr_meta' || headerLayout.left === 'qr'" class="flex flex-col items-end gap-2">
                                    <div v-if="layout.qr?.show !== false && currentTemplate.show_qr" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs">
                                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="ZATCA QR Code" :style="{ width: (Number(layout.qr?.size) || 100) + 'px', height: (Number(layout.qr?.size) || 100) + 'px' }" class="object-contain" />
                                        <div v-else :style="{ width: (Number(layout.qr?.size) || 100) + 'px', height: (Number(layout.qr?.size) || 100) + 'px' }" class="bg-slate-100 flex items-center justify-center text-3xs text-slate-400">QR Code</div>
                                    </div>
                                    <!-- Meta Fields if qr_meta -->
                                    <div v-if="headerLayout.left === 'qr_meta'" class="mt-1 text-start space-y-1 text-xs">
                                        <div v-for="f in layout.header.meta_fields" :key="f.id" class="flex items-center gap-2" :style="{ fontSize: (f.font_size || 12) + 'px', color: f.color || '#0f172a', fontWeight: f.is_bold ? 'bold' : 'normal', textAlign: f.alignment || 'right' }">
                                            <span class="text-slate-500 font-medium">{{ f.label }}:</span>
                                            <span class="font-mono">{{ resolveTag(f.tag) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- If left is logo -->
                                <div v-else-if="headerLayout.left === 'logo' && layout.logo?.show" class="inline-block">
                                    <img 
                                        v-if="companyLogoUrl && !isLogoBroken" 
                                        :src="companyLogoUrl" 
                                        alt="Company Logo" 
                                        :style="{ 
                                            height: (Number(layout.logo?.height) || 85) + 'px',
                                            maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                        }" 
                                        class="object-contain w-auto"
                                        loading="eager"
                                        @error="isLogoBroken = true"
                                    />
                                    <div 
                                        v-else 
                                        :style="{ 
                                            height: (Number(layout.logo?.height) || 85) + 'px',
                                            maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                        }" 
                                        class="px-4 py-2 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center bg-slate-50 text-xs font-bold text-slate-600"
                                    >
                                        <span>{{ company?.name || 'مؤسسة أنس عبدالرحمن' }}</span>
                                    </div>
                                </div>

                                <!-- If left is meta (when qr is center) -->
                                <div v-else-if="headerLayout.left === 'meta'" class="space-y-1 text-xs">
                                    <div v-for="f in layout.header.meta_fields" :key="f.id" class="flex items-center gap-2" :style="{ fontSize: (f.font_size || 12) + 'px', color: f.color || '#0f172a', fontWeight: f.is_bold ? 'bold' : 'normal', textAlign: f.alignment || 'right' }">
                                        <span class="text-slate-500 font-medium">{{ f.label }}:</span>
                                        <span class="font-mono">{{ resolveTag(f.tag) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <!-- Header Divider Line -->
                    <hr v-if="layout.header.show_divider_line" class="border-t border-slate-200 my-4" />
                </div>

                <!-- Parties Info: Seller (من) and Customer (إلى) -->
                <div class="invoice-parties-grid grid grid-cols-2 gap-6 text-xs">
                    <!-- Seller (من) -->
                    <div class="bg-slate-50/70 p-4 rounded-xl border border-slate-100 space-y-1.5">
                        <div class="font-bold text-slate-800 flex items-center justify-between border-b border-slate-200 pb-1 mb-2">
                            <span>من:</span>
                            <span class="text-3xs text-slate-500 font-normal">المورد / البائع</span>
                        </div>
                        <div v-if="layout.company.show_name" class="font-bold text-slate-900 text-xs">
                            {{ company?.name || $page.props.brand?.name }}
                        </div>
                        <div v-if="layout.company.show_vat" class="text-slate-600 text-3xs font-mono">
                            <span>الرقم الضريبي (VAT):</span>
                            <span class="font-bold text-slate-800 mr-1">{{ company?.tax_number || '-' }}</span>
                        </div>
                        <div v-if="layout.company.show_cr" class="text-slate-600 text-3xs font-mono">
                            <span>السجل التجاري (CR):</span>
                            <span class="mr-1">{{ company?.commercial_register || company?.commercial_registration || '-' }}</span>
                        </div>
                        <div v-if="layout.company.show_address" class="text-slate-600 text-3xs">
                            <span>العنوان:</span> {{ company?.address || 'المملكة العربية السعودية' }}
                        </div>
                        <div v-if="layout.company.show_phone && company?.phone" class="text-slate-600 text-3xs font-mono">
                            <span>الهاتف:</span> {{ company.phone }}
                        </div>
                    </div>

                    <!-- Customer (إلى) -->
                    <div class="bg-slate-50/70 p-4 rounded-xl border border-slate-100 space-y-1.5">
                        <div class="font-bold text-slate-800 flex items-center justify-between border-b border-slate-200 pb-1 mb-2">
                            <span>إلى:</span>
                            <span class="text-3xs text-slate-500 font-normal">العميل / المشتري</span>
                        </div>
                        <div v-if="layout.customer.show_name" class="font-bold text-slate-900 text-xs">
                            {{ invoice.customer?.name || 'عميل نقدي' }}
                        </div>
                        <div v-if="layout.customer.show_vat" class="text-slate-600 text-3xs font-mono">
                            <span>الرقم الضريبي للعميل:</span>
                            <span class="font-bold text-slate-800 mr-1">{{ invoice.customer?.tax_number || 'غير مسجل' }}</span>
                        </div>
                        <div v-if="layout.customer.show_address" class="text-slate-600 text-3xs">
                            <span>العنوان:</span> {{ invoice.customer?.address || invoice.customer?.city || '-' }}
                        </div>
                        <div v-if="layout.customer.show_phone && invoice.customer?.phone" class="text-slate-600 text-3xs font-mono">
                            <span>الهاتف:</span> {{ invoice.customer.phone }}
                        </div>
                    </div>
                </div>

                <!-- Items Table (الجدول) -->
                <div class="border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
                    <table class="w-full text-xs text-start border-collapse">
                        <thead>
                            <tr 
                                :style="{
                                    backgroundColor: layout.table.header_bg || '#f8fafc',
                                    color: layout.table.header_text_color || '#1e293b'
                                }"
                                class="border-b border-slate-200 font-bold"
                            >
                                <th 
                                    v-for="col in layout.table.columns.filter(c => c.visible)" 
                                    :key="col.key"
                                    :style="{ width: col.width, textAlign: col.align }"
                                    class="py-3 px-3"
                                >
                                    {{ col.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(item, idx) in invoice.items" :key="item.id || idx" class="hover:bg-slate-50/50">
                                <td v-if="layout.table.columns.find(c => c.key === 'index')?.visible" class="py-3 px-3 text-center font-mono text-slate-400">
                                    {{ idx + 1 }}
                                </td>
                                <td v-if="layout.table.columns.find(c => c.key === 'description')?.visible" class="py-3 px-3 font-medium text-slate-900">
                                    <div>{{ item.item_name || item.description }}</div>
                                    <div v-if="item.description && item.item_name" class="text-3xs text-slate-500">{{ item.description }}</div>
                                </td>
                                <td v-if="layout.table.columns.find(c => c.key === 'unit_price')?.visible" class="py-3 px-3 text-center font-mono">
                                    {{ formatCurrency(item.unit_price) }} {{ curSymbol }}
                                </td>
                                <td v-if="layout.table.columns.find(c => c.key === 'quantity')?.visible" class="py-3 px-3 text-center font-mono font-bold">
                                    {{ item.quantity }}
                                </td>
                                <td v-if="layout.table.columns.find(c => c.key === 'total')?.visible" class="py-3 px-3 text-center font-mono font-bold text-slate-900">
                                    {{ formatCurrency(item.total || (item.unit_price * item.quantity)) }} {{ curSymbol }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals Breakdown -->
                <div class="flex justify-end pt-2">
                    <div class="w-80 bg-slate-50/80 rounded-xl border border-slate-200 p-4 space-y-2.5 text-xs">
                        <div v-if="layout.footer.show_subtotal" class="flex justify-between items-center text-slate-600">
                            <span>المجموع غير شامل الضريبة:</span>
                            <span class="font-mono font-semibold">{{ formatCurrency(invoice.subtotal) }} {{ curSymbol }}</span>
                        </div>

                        <div v-if="layout.footer.show_vat_15" class="flex justify-between items-center text-slate-600">
                            <span>{{ $taxLabel() }}:</span>
                            <span class="font-mono font-semibold">{{ formatCurrency(invoice.tax_amount) }} {{ curSymbol }}</span>
                        </div>

                        <div v-if="layout.footer.show_grand_total" class="flex justify-between items-center text-slate-900 font-bold border-t border-slate-200 pt-2.5 text-sm">
                            <span>الإجمالي شامل الضريبة:</span>
                            <span class="font-mono" :style="{ color: layout.design.primary_color || '#4F46E5' }">
                                {{ formatCurrency(invoice.total) }} {{ curSymbol }}
                            </span>
                        </div>
                        <div v-if="baseTotal !== null" class="flex items-center justify-between text-2xs text-slate-500 font-mono px-1">
                            <span>ما يعادل بالريال (سعر {{ Number(invoice.exchange_rate).toFixed(4) }})</span>
                            <span>{{ formatCurrency(baseTotal) }} {{ $cur() }}</span>
                        </div>

                        <div v-if="layout.footer.show_paid" class="flex justify-between items-center text-emerald-700 text-3xs font-semibold pt-1">
                            <span>المبلغ المدفوع:</span>
                            <span class="font-mono">{{ formatCurrency(invoice.paid_amount) }} {{ curSymbol }}</span>
                        </div>

                        <div v-if="layout.footer.show_due" class="flex justify-between items-center text-slate-700 text-3xs font-semibold border-t border-dashed border-slate-200 pt-1">
                            <span>المبلغ المتبقي:</span>
                            <span class="font-mono font-bold">{{ formatCurrency(invoice.remaining_amount) }} {{ curSymbol }}</span>
                        </div>
                    </div>
                </div>

                <!-- Footer, Notes, Terms, and Signatures -->
                <div class="mt-8 pt-4 border-t border-slate-200/80 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                        <div class="text-3xs text-slate-500 space-y-1.5">
                            <div v-if="layout.footer.show_notes && invoice.notes">
                                <span class="font-bold text-slate-700">ملاحظات:</span> {{ invoice.notes }}
                            </div>
                            <div v-if="layout.footer.show_terms && (currentTemplate.terms_and_conditions || invoice.terms)">
                                <span class="font-bold text-slate-700">الشروط والأحكام:</span> {{ currentTemplate.terms_and_conditions || invoice.terms }}
                            </div>
                        </div>

                        <div class="flex items-center justify-around text-center text-xs">
                            <div v-if="layout.footer.show_signature" class="space-y-6">
                                <span class="text-slate-500 font-medium">التوقيع المعتمد</span>
                                <div class="border-b border-dashed border-slate-300 w-28 mx-auto"></div>
                            </div>
                            <div v-if="layout.footer.show_stamp" class="space-y-6">
                                <span class="text-slate-500 font-medium">الختم الرسمي</span>
                                <div class="w-14 h-14 border border-dashed border-slate-300 rounded-full mx-auto flex items-center justify-center text-3xs text-slate-400">ختم</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="currentTemplate.footer_text" class="text-center text-3xs text-slate-400 pt-2 border-t border-slate-100">
                        {{ currentTemplate.footer_text }}
                    </div>
                </div>

                <!-- End Standard A4 -->
                </div>

            </div>

        </div>

        <!-- Print & Template Selection Modal (Hidden on Print) -->
        <div 
            v-if="showPrintModal"
            class="print:hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150"
            @click.self="showPrintModal = false"
        >
            <div class="bg-white rounded-2xl border border-slate-200 p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in zoom-in-95 duration-150">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <PrinterIcon class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 text-sm">خيارات وقوالب الطباعة</h3>
                            <p class="text-3xs text-slate-500">اختر القالب المطلوب لطباعة الفاتورة فورياً</p>
                        </div>
                    </div>
                    <button 
                        @click="showPrintModal = false" 
                        class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"
                    >
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Template Choices Grid -->
                <div class="space-y-2.5 max-h-80 overflow-y-auto pr-1">
                    <div 
                        v-for="tmpl in invoiceTemplates" 
                        :key="tmpl.id"
                        @click="switchTemplate(tmpl.id)"
                        :class="[
                            Number(currentTemplateId) === Number(tmpl.id)
                                ? 'bg-indigo-50/80 border-indigo-400 ring-2 ring-indigo-200/80' 
                                : 'bg-slate-50/70 border-slate-200/80 hover:bg-slate-100/70',
                            'p-3 rounded-xl border flex items-center justify-between gap-3 cursor-pointer transition'
                        ]"
                    >
                        <div class="flex items-center gap-3">
                            <div 
                                class="w-3.5 h-10 rounded-full flex-shrink-0"
                                :style="{ backgroundColor: tmpl.primary_color || '#4F46E5' }"
                            ></div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 text-xs">{{ tmpl.name }}</span>
                                    <span v-if="tmpl.is_default" class="text-3xs bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 rounded-full">
                                        افتراضي
                                    </span>
                                </div>
                                <span class="text-3xs text-slate-500 font-medium">
                                    {{ getTemplateBadge(tmpl.template_type).label }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button 
                                @click.stop="printWithTemplate(tmpl.id)"
                                type="button"
                                class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-3xs font-bold shadow-2xs flex items-center gap-1 transition"
                                title="طباعة فورية بهذا القالب"
                            >
                                <PrinterIcon class="w-3.5 h-3.5" />
                                <span>طباعة الآن</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs">
                    <Link 
                        :href="route('templates.designer', currentTemplateId)"
                        class="text-indigo-600 hover:text-indigo-800 font-bold flex items-center gap-1 hover:underline"
                    >
                        <PaintBrushIcon class="w-4 h-4" />
                        <span>فتح القالب في المصمم المرئي</span>
                    </Link>

                    <div class="flex items-center gap-2">
                        <button 
                            @click="showPrintModal = false"
                            type="button" 
                            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            @click="printWithTemplate(currentTemplateId)"
                            type="button" 
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs flex items-center gap-1.5 transition"
                        >
                            <PrinterIcon class="w-4 h-4 stroke-2" />
                            <span>طباعة الفاتورة</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    header, nav, aside, .print\:hidden {
        display: none !important;
    }
}
</style>
