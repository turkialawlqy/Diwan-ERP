<script setup>
import { notify } from '@/composables/useDialog';
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import QRCode from 'qrcode';
import {
    PrinterIcon,
    HomeIcon,
    ChevronRightIcon,
    ArrowPathIcon,
    CheckIcon,
    PlusIcon,
    XMarkIcon,
    DocumentDuplicateIcon,
    StarIcon,
    EyeIcon,
    ArrowLeftIcon,
    SwatchIcon,
    DocumentTextIcon,
    PhotoIcon,
    ArrowUpTrayIcon,
    TrashIcon,
    Bars3CenterLeftIcon,
    BuildingOffice2Icon,
    UserGroupIcon,
    TableCellsIcon,
    DocumentCheckIcon,
    InformationCircleIcon,
    CheckCircleIcon,
    QrCodeIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';
import axios from 'axios';

const props = defineProps({
    template: Object,
    templates: Array,
    company: Object,
    sampleInvoices: Array,
    defaultInvoice: Object,
});

// Active Sidebar Tab
const activeTab = ref('header'); // 'header', 'logo', 'qr', 'design', 'page', 'company', 'customer', 'table', 'footer'

const tabs = [
    { id: 'header', label: 'المقدمة والترتيب', icon: Bars3CenterLeftIcon },
    { id: 'logo', label: 'الشعار', icon: PhotoIcon },
    { id: 'qr', label: 'الباركود (QR)', icon: QrCodeIcon },
    { id: 'design', label: 'التصميم', icon: SwatchIcon },
    { id: 'page', label: 'الصفحة', icon: DocumentTextIcon },
    { id: 'company', label: 'الشركة', icon: BuildingOffice2Icon },
    { id: 'customer', label: 'العميل/المورد', icon: UserGroupIcon },
    { id: 'table', label: 'الجدول', icon: TableCellsIcon },
    { id: 'footer', label: 'التذييل', icon: DocumentCheckIcon },
];

// Current Template Layout Data state (deep reactive clone)
const defaultLayout = {
    design: {
        primary_color: props.template?.primary_color || '#1e293b',
        secondary_color: '#64748b',
        font_family: 'Cairo',
        font_size_base: 12,
        border_style: 'solid',
    },
    page: {
        paper_size: props.template?.template_type === 'invoice_thermal_80mm' ? 'thermal_80mm' : (props.template?.layout_data?.page?.paper_size || 'A4'),
        orientation: 'portrait',
        margin_top: 15,
        margin_bottom: 15,
        margin_left: 15,
        margin_right: 15,
        show_page_number: true,
    },
    logo: {
        show: props.template?.show_logo ?? true,
        position: 'right',
        height: 85,
        max_width: 280,
    },
    qr: {
        show: props.template?.show_qr ?? true,
        position: 'left',
        size: 95,
    },
    header: {
        layout_order: 'logo_meta_qr', // 'logo_meta_qr', 'meta_logo_qr', 'qr_meta_logo', 'logo_qr_meta'
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
            { id: 'code', label: 'الرقم', tag: '{{invoice.code}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: true },
            { id: 'date', label: 'التاريخ', tag: '{{invoice.issue_date}}', font_size: 12, color: '#0f172a', alignment: 'right', is_bold: false },
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
            { key: 'index', label: 'البند', visible: true, width: '10%', align: 'center' },
            { key: 'description', label: 'الوصف', visible: true, width: '40%', align: 'right' },
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
        show_bank_details: props.template?.show_bank_details ?? true,
        show_terms: true,
        show_notes: true,
        show_signature: true,
        show_stamp: props.template?.show_stamp ?? true,
    },
};

// Merge template layout_data with default
const layout = reactive(
    props.template?.layout_data ? JSON.parse(JSON.stringify({ ...defaultLayout, ...props.template.layout_data })) : defaultLayout
);

// Preview controls
const selectedScope = ref('مبيعات');
const selectedDocType = ref('فاتورة');
const selectedInvoiceId = ref(props.defaultInvoice?.id || props.sampleInvoices?.[0]?.id || null);
const currentInvoice = ref(props.defaultInvoice || {});
const qrDataUrl = ref('');
const isSaving = ref(false);
const saveSuccess = ref(false);

const isThermal = computed(() => {
    return props.template?.template_type === 'invoice_thermal_80mm' || layout.page?.paper_size === 'thermal_80mm';
});

const companyLogo = ref(props.company?.logo_url || props.company?.logo || null);
const isUploadingLogo = ref(false);
const logoInputRef = ref(null);
const logoError = ref('');

const uploadLogoFromDesigner = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        logoError.value = 'يرجى اختيار ملف صورة صالح (PNG, JPG, WebP, GIF).';
        return;
    }

    if (file.size > 3 * 1024 * 1024) {
        logoError.value = 'حجم الصورة يجب ألا يتجاوز 3 ميجابايت.';
        return;
    }

    logoError.value = '';
    isUploadingLogo.value = true;

    const formData = new FormData();
    formData.append('logo', file);

    try {
        const response = await axios.post(route('settings.logo.upload'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });
        if (response.data.success) {
            companyLogo.value = response.data.logo;
            if (props.company) {
                props.company.logo = response.data.logo;
            }
        }
    } catch (err) {
        logoError.value = err.response?.data?.message || 'حدث خطأ أثناء رفع الشعار.';
    } finally {
        isUploadingLogo.value = false;
        if (logoInputRef.value) logoInputRef.value.value = '';
    }
};

const deleteLogoFromDesigner = async () => {
    if (!confirm('هل أنت متأكد من حذف شعار المنشأة؟')) return;

    isUploadingLogo.value = true;
    try {
        const response = await axios.delete(route('settings.logo.delete'));
        if (response.data.success) {
            companyLogo.value = null;
            if (props.company) {
                props.company.logo = null;
            }
        }
    } catch (err) {
        logoError.value = 'تعذر حذف الشعار.';
    } finally {
        isUploadingLogo.value = false;
    }
};

// Header layout computed slot routing (matches Show.vue / Print.vue)
const headerLayout = computed(() => {
    const logoPos = layout.logo?.position || 'right';
    const qrPos = layout.qr?.position || 'left';
    const order = layout.header?.layout_order || 'logo_meta_qr';

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

// Helper position switchers to prevent overlap and sync presets
const setLogoPosition = (pos) => {
    if (!layout.logo) layout.logo = {};
    layout.logo.position = pos;
    if (pos === 'right') {
        if (!layout.qr) layout.qr = {};
        if (layout.qr.position === 'right') layout.qr.position = 'left';
        layout.header.layout_order = 'logo_meta_qr';
    } else if (pos === 'left') {
        if (!layout.qr) layout.qr = {};
        if (layout.qr.position === 'left') layout.qr.position = 'right';
        layout.header.layout_order = 'qr_meta_logo';
    } else if (pos === 'center') {
        layout.header.layout_order = 'meta_logo_qr';
        if (!layout.qr) layout.qr = {};
        if (layout.qr.position === 'center') layout.qr.position = 'left';
    }
};

const setQrPosition = (pos) => {
    if (!layout.qr) layout.qr = {};
    layout.qr.position = pos;
    if (pos === 'left') {
        if (!layout.logo) layout.logo = {};
        if (layout.logo.position === 'left') layout.logo.position = 'right';
        layout.header.layout_order = 'logo_meta_qr';
    } else if (pos === 'right') {
        if (!layout.logo) layout.logo = {};
        if (layout.logo.position === 'right') layout.logo.position = 'left';
        layout.header.layout_order = 'qr_meta_logo';
    } else if (pos === 'center') {
        layout.header.layout_order = 'logo_qr_meta';
        if (!layout.logo) layout.logo = {};
        if (layout.logo.position === 'center') layout.logo.position = 'right';
    }
};

const setHeaderPreset = (preset) => {
    if (!layout.logo) layout.logo = {};
    if (!layout.qr) layout.qr = {};
    if (preset === 'standard') {
        layout.logo.position = 'right';
        layout.qr.position = 'left';
        layout.header.layout_order = 'logo_meta_qr';
    } else if (preset === 'center_logo') {
        layout.logo.position = 'center';
        layout.qr.position = 'left';
        layout.header.layout_order = 'meta_logo_qr';
    } else if (preset === 'reversed') {
        layout.logo.position = 'left';
        layout.qr.position = 'right';
        layout.header.layout_order = 'qr_meta_logo';
    } else if (preset === 'center_qr') {
        layout.logo.position = 'right';
        layout.qr.position = 'center';
        layout.header.layout_order = 'logo_qr_meta';
    }
};

// Generate QR Code dynamically
const generateQR = async () => {
    try {
        const qrPayload = currentInvoice.value?.zatca_qr || 'ZATCA-QR-SAMPLE-DIWAN-ERP';
        const qrPx = (Number(layout.qr?.size) || 95) * 2;
        qrDataUrl.value = await QRCode.toDataURL(qrPayload, {
            width: qrPx,
            margin: 1,
            color: {
                dark: '#0f172a',
                light: '#ffffff'
            }
        });
    } catch (e) {
        console.error('QR code generation error', e);
    }
};

onMounted(() => {
    generateQR();
});

watch([() => currentInvoice.value, () => layout.qr?.size], () => {
    generateQR();
}, { deep: true });

// Switch preview invoice
const changePreviewInvoice = async () => {
    if (!selectedInvoiceId.value) return;
    try {
        const response = await axios.get(route('templates.preview-invoice', selectedInvoiceId.value));
        if (response.data) {
            currentInvoice.value = response.data;
        }
    } catch (err) {
        console.error('Failed to load invoice preview', err);
    }
};

// Tag replacement engine
const resolveTag = (tag) => {
    if (!tag) return '';
    const inv = currentInvoice.value || {};
    const comp = props.company || {};
    const cust = inv.customer || {};

    const dictionary = {
        '{{invoice.code}}': inv.invoice_number || 'INV-26-1-000004',
        '{{invoice.issue_date}}': inv.invoice_date || '2026/09/10',
        '{{invoice.partner_reference_code}}': inv.reference_number || 'REF-98421',
        '{{invoice.payments.status.ar}}': inv.payment_status === 'paid' ? 'مدفوعة' : (inv.payment_status === 'partial' ? 'مدفوعة جزئياً' : 'مستحقة'),
        '{{invoice.payments.payment_method_names}}': inv.payment_method === 'cash' ? 'نقدي' : (inv.payment_method === 'bank_transfer' ? 'تحويل بنكي' : 'بطاقة مدى'),
        '{{zatca.invoice.title.ar}}': 'فاتورة ضريبية',
        '{{company.name}}': comp.name || 'مؤسسة أنس عبدالرحمن الغامدي للمقاولات',
        '{{company.tax_number}}': comp.tax_number || '314953716500003',
        '{{company.cr}}': comp.commercial_register || '1010892341',
        '{{customer.name}}': cust.name || 'شركة مدن الراقية المحدودة',
        '{{customer.tax_number}}': cust.tax_number || '311576763100003',
        '{{customer.address}}': cust.address || 'حي الكوكية - مبنى 4002 - جدة',
    };

    let result = tag;
    for (const [key, val] of Object.entries(dictionary)) {
        result = result.replaceAll(key, val);
    }
    return result;
};

// Form submission to save template
const saveTemplate = () => {
    isSaving.value = true;
    router.put(route('templates.update', props.template.id), {
        name: props.template.name,
        primary_color: layout.design.primary_color,
        show_logo: layout.logo?.show ?? true,
        show_qr: layout.qr?.show ?? true,
        show_stamp: layout.footer.show_stamp,
        show_bank_details: layout.footer.show_bank_details,
        terms_and_conditions: props.template.terms_and_conditions,
        footer_text: props.template.footer_text,
        layout_data: JSON.parse(JSON.stringify(layout)),
        is_default: props.template?.is_default ?? false,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isSaving.value = false;
            saveSuccess.value = true;
            setTimeout(() => {
                saveSuccess.value = false;
            }, 3000);
        },
        onError: (errors) => {
            isSaving.value = false;
            console.error('Save template errors:', errors);
            notify('حدث خطأ أثناء حفظ القالب:\n' + Object.values(errors).join('\n'));
        }
    });
};

// Add / Remove Meta field in header
const addMetaField = (index = null) => {
    const newField = {
        id: 'field_' + Date.now(),
        label: 'حقل جديد',
        tag: '{{invoice.code}}',
        font_size: 12,
        color: '#0f172a',
        alignment: 'right',
        is_bold: false,
    };
    if (index !== null) {
        layout.header.meta_fields.splice(index + 1, 0, newField);
    } else {
        layout.header.meta_fields.push(newField);
    }
};

const removeMetaField = (index) => {
    if (layout.header.meta_fields.length > 1) {
        layout.header.meta_fields.splice(index, 1);
    }
};

// Available tags list for dropdown insert
const availableTags = [
    { label: 'رقم الفاتورة', tag: '{{invoice.code}}' },
    { label: 'تاريخ الفاتورة', tag: '{{invoice.issue_date}}' },
    { label: 'الرقم المرجعي', tag: '{{invoice.partner_reference_code}}' },
    { label: 'حالة الدفع', tag: '{{invoice.payments.status.ar}}' },
    { label: 'طريقة الدفع', tag: '{{invoice.payments.payment_method_names}}' },
    { label: 'عنوان الفاتورة', tag: '{{zatca.invoice.title.ar}}' },
    { label: 'اسم العميل', tag: '{{customer.name}}' },
    { label: 'الرقم الضريبي للعميل', tag: '{{customer.tax_number}}' },
];

const insertTagToField = (field, tagStr) => {
    field.tag = tagStr;
};

// Currency formatter
const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

// Duplicate template
const duplicateThisTemplate = () => {
    router.post(route('templates.duplicate', props.template.id));
};

// Set as default
const setAsDefault = () => {
    router.post(route('templates.default', props.template.id));
};
</script>

<template>
    <AppLayout>
        <Head :title="`مصمم القوالب - ${template.name}`" />

        <div class="h-full flex flex-col space-y-4">
            <!-- Top Navigation & Action Header -->
            <div class="bg-white border border-slate-200/80 rounded-2xl px-5 py-3 shadow-2xs flex flex-wrap items-center justify-between gap-4">
                <!-- Breadcrumbs & Title -->
                <div class="flex items-center gap-2">
                    <nav class="flex items-center gap-1.5 text-xs text-slate-500">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-400 rotate-180" />
                        <Link :href="route('templates.index')" class="hover:text-indigo-600 transition-colors font-medium">
                            تصاميم القوالب
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-bold">{{ template.name }}</span>
                    </nav>

                    <span v-if="template.is_default" class="mr-2 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-3xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <StarSolidIcon class="w-3 h-3 text-emerald-600" />
                        القالب الافتراضي
                    </span>
                </div>

                <!-- Template Actions -->
                <div class="flex items-center gap-2">
                    <!-- Template Switcher Dropdown -->
                    <select 
                        :value="template.id"
                        @change="router.get(route('templates.designer', $event.target.value))"
                        class="text-xs bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 font-medium focus:ring-indigo-500"
                    >
                        <option v-for="t in templates" :key="t.id" :value="t.id">
                            {{ t.name }} {{ t.is_default ? '(افتراضي)' : '' }}
                        </option>
                    </select>

                    <button 
                        @click="duplicateThisTemplate"
                        type="button"
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold flex items-center gap-1.5 transition"
                        title="استنساخ هذا القالب"
                    >
                        <DocumentDuplicateIcon class="w-4 h-4 text-slate-500" />
                        <span class="hidden sm:inline">نسخ</span>
                    </button>

                    <button 
                        v-if="!template.is_default"
                        @click="setAsDefault"
                        type="button"
                        class="px-3 py-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 text-xs font-semibold flex items-center gap-1.5 transition"
                        title="تعيين كقالب افتراضي لهذا النوع"
                    >
                        <StarIcon class="w-4 h-4 text-amber-600" />
                        <span class="hidden sm:inline">تعيين كافتراضي</span>
                    </button>

                    <!-- Save Button -->
                    <button 
                        @click="saveTemplate"
                        :disabled="isSaving"
                        type="button"
                        class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 flex items-center gap-1.5 transition disabled:opacity-50"
                    >
                        <CheckIcon v-if="!isSaving" class="w-4 h-4 stroke-2" />
                        <ArrowPathIcon v-else class="w-4 h-4 animate-spin" />
                        <span>{{ isSaving ? 'جارِ الحفظ...' : 'حفظ التعديلات' }}</span>
                    </button>
                </div>
            </div>

            <!-- Success Alert Notification -->
            <transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="saveSuccess" class="bg-emerald-500 text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center gap-2 shadow-xs">
                    <CheckCircleIcon class="w-5 h-5 text-white" />
                    <span>تم حفظ كافة خيارات وتعديلات القالب بنجاح!</span>
                </div>
            </transition>

            <!-- Main Designer Split Workspace (Image Layout: Left Preview, Right Settings) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                
                <!-- LEFT SIDE: Live Document Preview (A4 Canvas) (Columns 7 of 12) -->
                <div class="lg:col-span-7 flex flex-col space-y-3">
                    
                    <!-- Preview Toolbar (Matches top toolbar in screenshot) -->
                    <div class="bg-white border border-slate-200/80 rounded-2xl p-3 shadow-2xs flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <!-- Document Scope -->
                            <select v-model="selectedScope" class="text-xs bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-medium text-slate-700">
                                <option value="مبيعات">مبيعات</option>
                                <option value="مشتريات">مشتريات</option>
                                <option value="عروض أسعار">عروض أسعار</option>
                                <option value="مرتجعات">مرتجعات</option>
                            </select>

                            <!-- Document Type -->
                            <select v-model="selectedDocType" class="text-xs bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-medium text-slate-700">
                                <option value="فاتورة">فاتورة</option>
                                <option value="إشعار دائن">إشعار دائن</option>
                                <option value="إشعار مدين">إشعار مدين</option>
                            </select>

                            <!-- Real Invoice Selector -->
                            <select 
                                v-model="selectedInvoiceId" 
                                @change="changePreviewInvoice"
                                class="text-xs bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-mono text-slate-700"
                            >
                                <option v-for="inv in sampleInvoices" :key="inv.id" :value="inv.id">
                                    {{ inv.invoice_number }} ({{ formatCurrency(inv.total) }} {{ $cur() }})
                                </option>
                            </select>
                        </div>

                        <!-- Preview Action -->
                        <button 
                            @click="changePreviewInvoice"
                            type="button" 
                            class="px-5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-2xs transition flex items-center gap-1.5"
                        >
                            <EyeIcon class="w-3.5 h-3.5" />
                            <span>معاينة</span>
                        </button>
                    </div>

                    <!-- Realistic Paper Canvas Container -->
                    <div class="bg-slate-200/60 p-4 sm:p-6 rounded-2xl border border-slate-200 overflow-x-auto flex justify-center">
                        
                        <!-- Thermal 80mm Preview Canvas -->
                        <div 
                            v-if="isThermal"
                            id="invoice-thermal-sheet"
                            class="bg-white shadow-xl transition-all duration-200 w-full max-w-[340px] p-4 text-slate-900 flex flex-col space-y-3 rounded-xl border border-slate-300"
                            :style="{
                                fontFamily: layout.design.font_family + ', monospace, sans-serif',
                                fontSize: '11px',
                            }"
                        >
                            <!-- Thermal Store Header -->
                            <div class="text-center space-y-1">
                                <div v-if="layout.logo.show && (companyLogo || company?.logo)" class="flex justify-center pb-1">
                                    <img 
                                        :src="companyLogo || company?.logo" 
                                        alt="Logo" 
                                        :style="{ 
                                            height: Math.min(Number(layout.logo.height) || 40, 80) + 'px',
                                            maxWidth: '180px'
                                        }"
                                        class="w-auto object-contain mx-auto" 
                                    />
                                </div>
                                <h2 class="text-xs font-black tracking-tight leading-tight">
                                    {{ company?.name || 'مؤسسة أنس عبدالرحمن الغامدي للمقاولات' }}
                                </h2>
                                
                                <div class="inline-block px-2.5 py-0.5 rounded border border-slate-800 text-3xs font-black my-1 bg-slate-50">
                                    {{ resolveTag(layout.header.title.tag) || layout.header.title.text || 'فاتورة ضريبية مبسطة' }}
                                </div>

                                <div class="text-3xs space-y-0.5 text-slate-600 font-medium">
                                    <div v-if="layout.company.show_cr && (company?.commercial_register || company?.commercial_registration)">
                                        <span>س.ت: </span><span class="font-mono font-bold">{{ company.commercial_register || company.commercial_registration || '1010892341' }}</span>
                                    </div>
                                    <div v-if="layout.company.show_vat">
                                        <span>الرقم الضريبي: </span><span class="font-mono font-bold text-slate-900">{{ company?.tax_number || '314953716500003' }}</span>
                                    </div>
                                    <div v-if="layout.company.show_address">
                                        <span>العنوان: </span><span>{{ company?.address || 'الرياض - الملز' }}</span>
                                    </div>
                                    <div v-if="layout.company.show_phone">
                                        <span>هاتف: </span><span class="font-mono">{{ company?.phone || '0550000000' }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Dashed Divider -->
                            <div class="border-b border-dashed border-slate-400 my-1.5"></div>

                            <!-- Invoice & Customer Meta -->
                            <div class="text-3xs space-y-1 font-medium">
                                <div 
                                    v-for="f in layout.header.meta_fields" 
                                    :key="f.id"
                                    class="flex justify-between items-center"
                                >
                                    <span class="text-slate-500">{{ f.label }}:</span>
                                    <span class="font-mono font-bold">{{ resolveTag(f.tag) }}</span>
                                </div>
                                <div v-if="layout.customer.show_name" class="flex justify-between items-center">
                                    <span class="text-slate-500">العميل:</span>
                                    <span class="font-bold">{{ currentInvoice.customer?.name || 'شركة مدن الراقية المحدودة' }}</span>
                                </div>
                                <div v-if="layout.customer.show_vat && currentInvoice.customer?.tax_number" class="flex justify-between items-center">
                                    <span class="text-slate-500">الرقم الضريبي للعميل:</span>
                                    <span class="font-mono">{{ currentInvoice.customer.tax_number }}</span>
                                </div>
                            </div>

                            <!-- Dashed Divider -->
                            <div class="border-b border-dashed border-slate-400 my-1.5"></div>

                            <!-- Items Section -->
                            <div class="space-y-1">
                                <div class="flex justify-between items-center pb-1 border-b border-dashed border-slate-400 text-3xs font-bold text-slate-700">
                                    <span>الصنف / البيان</span>
                                    <span>الإجمالي</span>
                                </div>
                                <div 
                                    v-for="item in currentInvoice.items" 
                                    :key="item.id" 
                                    class="py-1 border-b border-dotted border-slate-200 text-3xs"
                                >
                                    <div class="font-bold text-slate-900 leading-snug">
                                        {{ item.item_name || item.product?.name || item.description || 'صنف' }}
                                    </div>
                                    <div class="flex justify-between items-center text-slate-600 mt-1">
                                        <span class="font-mono text-slate-500">
                                            {{ Number(item.quantity) }} × {{ formatCurrency(item.unit_price) }}
                                        </span>
                                        <span class="font-mono font-bold text-slate-900">
                                            {{ formatCurrency(item.total || (item.quantity * item.unit_price)) }} {{ $cur() }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Dashed Divider -->
                            <div class="border-b border-dashed border-slate-400 my-1.5"></div>

                            <!-- Totals Breakdown -->
                            <div class="text-3xs space-y-1">
                                <div v-if="layout.footer.show_subtotal" class="flex justify-between items-center text-slate-600">
                                    <span>المجموع الخاضع للضريبة:</span>
                                    <span class="font-mono">{{ formatCurrency(currentInvoice.subtotal) }} {{ $cur() }}</span>
                                </div>
                                <div v-if="Number(currentInvoice.discount) > 0" class="flex justify-between items-center text-rose-600">
                                    <span>الخصم:</span>
                                    <span class="font-mono">-{{ formatCurrency(currentInvoice.discount) }} {{ $cur() }}</span>
                                </div>
                                <div v-if="layout.footer.show_vat_15" class="flex justify-between items-center text-slate-600">
                                    <span>{{ $taxLabel() }}:</span>
                                    <span class="font-mono">{{ formatCurrency(currentInvoice.tax) }} {{ $cur() }}</span>
                                </div>
                                
                                <!-- Grand Total Highlighted -->
                                <div v-if="layout.footer.show_grand_total" class="border-y-2 border-dashed border-slate-800 py-1 my-1 flex justify-between items-center text-xs font-black">
                                    <span>الإجمالي شامل الضريبة:</span>
                                    <span class="font-mono text-xs">{{ formatCurrency(currentInvoice.total) }} {{ $cur() }}</span>
                                </div>

                                <div v-if="layout.footer.show_paid && Number(currentInvoice.paid_amount) > 0" class="flex justify-between items-center text-slate-600 pt-0.5">
                                    <span>المبلغ المدفوع:</span>
                                    <span class="font-mono font-bold">{{ formatCurrency(currentInvoice.paid_amount) }} {{ $cur() }}</span>
                                </div>
                                <div v-if="layout.footer.show_due && Number(currentInvoice.remaining_amount) > 0" class="flex justify-between items-center text-amber-700 font-bold">
                                    <span>المبلغ المتبقي:</span>
                                    <span class="font-mono">{{ formatCurrency(currentInvoice.remaining_amount) }} {{ $cur() }}</span>
                                </div>
                            </div>

                            <!-- Dashed Divider -->
                            <div class="border-b border-dashed border-slate-400 my-1.5"></div>

                            <!-- ZATCA QR Code (Centered) -->
                            <div v-if="template.show_qr !== false" class="text-center py-1">
                                <div class="inline-block p-1 bg-white border border-dashed border-slate-300 rounded-lg">
                                    <img 
                                        v-if="qrDataUrl" 
                                        :src="qrDataUrl" 
                                        alt="ZATCA QR" 
                                        class="w-28 h-28 object-contain mx-auto"
                                    />
                                    <div v-else class="w-28 h-28 bg-slate-100 flex items-center justify-center text-3xs text-slate-400">
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
                                    {{ template.footer_text || 'شكراً لزيارتكم ونسعد بخدمتكم دائماً' }}
                                </p>
                                <p v-if="template.terms_and_conditions" class="text-3xs text-slate-500 leading-tight">
                                    {{ template.terms_and_conditions }}
                                </p>
                                <p class="text-3xs text-slate-400 pt-0.5">
                                    {{ $page.props.brand?.name }}
                                </p>
                            </div>
                        </div>

                        <!-- Standard A4 Paper Canvas Container -->
                        <div 
                            v-else
                            id="invoice-a4-sheet"
                            dir="rtl"
                            class="bg-white shadow-xl transition-all duration-200 w-full max-w-[720px] min-h-[960px] p-8 text-slate-900 flex flex-col justify-between"
                            :style="{
                                fontFamily: layout.design.font_family + ', sans-serif',
                                fontSize: layout.design.font_size_base + 'px',
                                borderTop: `6px solid ${layout.design.primary_color}`
                            }"
                        >
                            <!-- Top Header Block: Dynamic 3-Column Header Grid (Right, Center, Left) -->
                            <div class="space-y-4">
                                <div class="grid grid-cols-3 items-start gap-4 pb-2">
                                    
                                    <!-- RIGHT COLUMN (يمين الفاتورة في الواجهة العربية) -->
                                    <div :class="[
                                        headerLayout.right === 'logo' ? (layout.logo?.position === 'center' ? 'text-center' : 'text-right') : 'text-right',
                                        'flex flex-col items-start justify-center'
                                    ]">
                                        <!-- If right is logo -->
                                        <div v-if="headerLayout.right === 'logo' && layout.logo?.show" class="inline-block">
                                            <img 
                                                v-if="companyLogo || company?.logo" 
                                                :src="companyLogo || company?.logo" 
                                                alt="Company Logo" 
                                                :style="{ 
                                                    height: (Number(layout.logo?.height) || 85) + 'px',
                                                    maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                                }" 
                                                class="object-contain w-auto"
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
                                            <div v-if="layout.qr?.show" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs">
                                                <img v-if="qrDataUrl" :src="qrDataUrl" alt="ZATCA QR" :style="{ width: (Number(layout.qr?.size) || 95) + 'px', height: (Number(layout.qr?.size) || 95) + 'px' }" class="object-contain" />
                                                <div v-else :style="{ width: (Number(layout.qr?.size) || 95) + 'px', height: (Number(layout.qr?.size) || 95) + 'px' }" class="bg-slate-100 flex items-center justify-center text-3xs text-slate-400">QR Code</div>
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
                                                {{ resolveTag(layout.header.title.tag) || layout.header.title.text }}
                                            </h2>
                                            <span class="text-3xs text-slate-400 font-mono">فاتورة ضريبية خاضعة للائحة ZATCA</span>
                                        </div>

                                        <!-- If center is logo_title (Logo in Center) -->
                                        <div v-else-if="headerLayout.center === 'logo_title'" class="text-center space-y-2">
                                            <div v-if="layout.logo?.show" class="inline-block">
                                                <img v-if="companyLogo || company?.logo" :src="companyLogo || company?.logo" alt="Company Logo" :style="{ height: (Number(layout.logo?.height) || 85) + 'px', maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px' }" class="object-contain mx-auto" />
                                                <div v-else :style="{ height: (Number(layout.logo?.height) || 85) + 'px', maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px' }" class="px-4 py-2 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center bg-slate-50 text-xs font-bold text-slate-600">
                                                    <span>{{ company?.name || 'مؤسسة أنس عبدالرحمن' }}</span>
                                                </div>
                                            </div>
                                            <div>
                                                <h2 :style="{ fontSize: (layout.header.title.font_size || 14) + 'px', color: layout.header.title.color || '#0f172a', fontWeight: layout.header.title.is_bold ? 'bold' : 'normal', textAlign: 'center' }" class="tracking-wide">
                                                    {{ resolveTag(layout.header.title.tag) || layout.header.title.text }}
                                                </h2>
                                                <span class="text-3xs text-slate-400 font-mono">فاتورة ضريبية خاضعة للائحة ZATCA</span>
                                            </div>
                                        </div>

                                        <!-- If center is qr_title (QR in Center) -->
                                        <div v-else-if="headerLayout.center === 'qr_title'" class="text-center space-y-2">
                                            <div v-if="layout.qr?.show" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs inline-block">
                                                <img v-if="qrDataUrl" :src="qrDataUrl" alt="ZATCA QR" :style="{ width: (Number(layout.qr?.size) || 95) + 'px', height: (Number(layout.qr?.size) || 95) + 'px' }" class="object-contain mx-auto" />
                                            </div>
                                            <div>
                                                <h2 :style="{ fontSize: (layout.header.title.font_size || 14) + 'px', color: layout.header.title.color || '#0f172a', fontWeight: layout.header.title.is_bold ? 'bold' : 'normal', textAlign: 'center' }" class="tracking-wide">
                                                    {{ resolveTag(layout.header.title.tag) || layout.header.title.text }}
                                                </h2>
                                                <span class="text-3xs text-slate-400 font-mono">فاتورة ضريبية خاضعة للائحة ZATCA</span>
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
                                            <div v-if="layout.qr?.show" class="p-1.5 bg-white border border-slate-200 rounded-xl shadow-2xs">
                                                <img v-if="qrDataUrl" :src="qrDataUrl" alt="ZATCA QR" :style="{ width: (Number(layout.qr?.size) || 95) + 'px', height: (Number(layout.qr?.size) || 95) + 'px' }" class="object-contain" />
                                                <div v-else :style="{ width: (Number(layout.qr?.size) || 95) + 'px', height: (Number(layout.qr?.size) || 95) + 'px' }" class="bg-slate-100 flex items-center justify-center text-3xs text-slate-400">QR Code</div>
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
                                                v-if="companyLogo || company?.logo" 
                                                :src="companyLogo || company?.logo" 
                                                alt="Company Logo" 
                                                :style="{ 
                                                    height: (Number(layout.logo?.height) || 85) + 'px',
                                                    maxWidth: layout.logo?.max_width ? (Number(layout.logo.max_width) + 'px') : '350px'
                                                }" 
                                                class="object-contain w-auto"
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

                                        <!-- If left is meta (when qr is center and logo is right) -->
                                        <div v-else-if="headerLayout.left === 'meta'" class="space-y-1 text-xs text-left">
                                            <div v-for="f in layout.header.meta_fields" :key="f.id" class="flex items-center justify-end gap-2" :style="{ fontSize: (f.font_size || 12) + 'px', color: f.color || '#0f172a', fontWeight: f.is_bold ? 'bold' : 'normal', textAlign: f.alignment || 'left' }">
                                                <span class="text-slate-500 font-medium">{{ f.label }}:</span>
                                                <span class="font-mono font-bold">{{ resolveTag(f.tag) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Horizontal Divider Line -->
                                <hr v-if="layout.header.show_divider_line" class="border-t border-slate-200 my-3" />

                                <!-- Two-Column Parties Info (من: المورد / إلى: العميل) -->
                                <div class="grid grid-cols-2 gap-6 pt-2 text-xs">
                                    <!-- Seller Company Info (من) -->
                                    <div class="bg-slate-50/70 p-3 rounded-xl border border-slate-100 space-y-1">
                                        <div class="font-bold text-slate-800 flex items-center justify-between border-b border-slate-200 pb-1 mb-1.5">
                                            <span>من:</span>
                                            <span class="text-3xs text-slate-500 font-normal">المورد / البائع</span>
                                        </div>
                                        <div v-if="layout.company.show_name" class="font-bold text-slate-900 text-xs">
                                            {{ company?.name || 'مؤسسة أنس عبدالرحمن الغامدي للمقاولات' }}
                                        </div>
                                        <div v-if="layout.company.show_vat" class="text-slate-600 flex items-center gap-1 text-3xs font-mono">
                                            <span>الرقم الضريبي:</span>
                                            <span class="font-bold text-slate-800">{{ company?.tax_number || '314953716500003' }}</span>
                                        </div>
                                        <div v-if="layout.company.show_cr" class="text-slate-600 flex items-center gap-1 text-3xs font-mono">
                                            <span>السجل التجاري:</span>
                                            <span>{{ company?.commercial_register || '1010892341' }}</span>
                                        </div>
                                        <div v-if="layout.company.show_address" class="text-slate-600 text-3xs">
                                            <span>العنوان:</span> {{ company?.address || 'الكوكية - مكة المكرمة' }}
                                        </div>
                                        <div v-if="layout.company.show_phone && company?.phone" class="text-slate-600 text-3xs font-mono">
                                            <span>الهاتف:</span> {{ company.phone }}
                                        </div>
                                    </div>

                                    <!-- Customer Info (إلى) -->
                                    <div class="bg-slate-50/70 p-3 rounded-xl border border-slate-100 space-y-1">
                                        <div class="font-bold text-slate-800 flex items-center justify-between border-b border-slate-200 pb-1 mb-1.5">
                                            <span>إلى:</span>
                                            <span class="text-3xs text-slate-500 font-normal">العميل / المشتري</span>
                                        </div>
                                        <div v-if="layout.customer.show_name" class="font-bold text-slate-900 text-xs">
                                            {{ currentInvoice.customer?.name || 'شركة مدن الراقية المحدودة' }}
                                        </div>
                                        <div v-if="layout.customer.show_vat" class="text-slate-600 flex items-center gap-1 text-3xs font-mono">
                                            <span>الرقم الضريبي:</span>
                                            <span class="font-bold text-slate-800">{{ currentInvoice.customer?.tax_number || '311576763100003' }}</span>
                                        </div>
                                        <div v-if="layout.customer.show_address" class="text-slate-600 text-3xs">
                                            <span>العنوان:</span> {{ currentInvoice.customer?.address || '4002 جدة' }}
                                        </div>
                                        <div v-if="layout.customer.show_phone && currentInvoice.customer?.phone" class="text-slate-600 text-3xs font-mono">
                                            <span>الهاتف:</span> {{ currentInvoice.customer.phone }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Line Items Table (الجدول) -->
                                <div class="mt-4 border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
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
                                                    class="py-2.5 px-3"
                                                >
                                                    {{ col.label }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <tr v-for="(item, idx) in currentInvoice.items" :key="item.id || idx" class="hover:bg-slate-50/50">
                                                <td v-if="layout.table.columns.find(c => c.key === 'index')?.visible" class="py-2.5 px-3 text-center font-mono text-slate-400">
                                                    {{ idx + 1 }}
                                                </td>
                                                <td v-if="layout.table.columns.find(c => c.key === 'description')?.visible" class="py-2.5 px-3 font-medium text-slate-800">
                                                    <div>{{ item.item_name || item.description || 'عمارة رقم 1151 مستخلص جاري رقم 2' }}</div>
                                                    <div v-if="item.notes" class="text-3xs text-slate-400">{{ item.notes }}</div>
                                                </td>
                                                <td v-if="layout.table.columns.find(c => c.key === 'unit_price')?.visible" class="py-2.5 px-3 text-center font-mono">
                                                    {{ formatCurrency(item.unit_price) }}
                                                </td>
                                                <td v-if="layout.table.columns.find(c => c.key === 'quantity')?.visible" class="py-2.5 px-3 text-center font-mono">
                                                    {{ item.quantity }}
                                                </td>
                                                <td v-if="layout.table.columns.find(c => c.key === 'total')?.visible" class="py-2.5 px-3 text-center font-mono font-bold text-slate-900">
                                                    {{ formatCurrency(item.total || (item.unit_price * item.quantity)) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Totals & Summary Breakdown -->
                                <div class="flex justify-end pt-2">
                                    <div class="w-72 bg-slate-50/80 rounded-xl border border-slate-200 p-3 space-y-2 text-xs">
                                        <div v-if="layout.footer.show_subtotal" class="flex justify-between items-center text-slate-600">
                                            <span>المجموع (غير شامل الضريبة):</span>
                                            <span class="font-mono font-medium">{{ formatCurrency(currentInvoice.subtotal || 11927.70) }}</span>
                                        </div>

                                        <div v-if="layout.footer.show_vat_15" class="flex justify-between items-center text-slate-600">
                                            <span>{{ $taxLabel() }}:</span>
                                            <span class="font-mono font-medium">{{ formatCurrency(currentInvoice.tax_amount || 1789.16) }}</span>
                                        </div>

                                        <div v-if="layout.footer.show_grand_total" class="flex justify-between items-center text-slate-900 font-bold border-t border-slate-200 pt-2 text-sm">
                                            <span>الإجمالي شامل الضريبة ({{ $cur() }}):</span>
                                            <span class="font-mono text-indigo-700">{{ formatCurrency(currentInvoice.total || 13716.86) }}</span>
                                        </div>

                                        <div v-if="layout.footer.show_paid" class="flex justify-between items-center text-emerald-700 text-3xs font-semibold pt-1">
                                            <span>المدفوع:</span>
                                            <span class="font-mono">{{ formatCurrency(currentInvoice.paid_amount || 13716.86) }}</span>
                                        </div>

                                        <div v-if="layout.footer.show_due" class="flex justify-between items-center text-slate-700 text-3xs font-semibold border-t border-dashed border-slate-200 pt-1">
                                            <span>المتبقي المستحق:</span>
                                            <span class="font-mono">{{ formatCurrency(currentInvoice.remaining_amount || 0.00) }} {{ $cur() }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Footer Section -->
                            <div class="mt-8 pt-4 border-t border-slate-200/80 space-y-4">
                                <div class="grid grid-cols-2 gap-4 items-end">
                                    <!-- Notes & Terms -->
                                    <div class="text-3xs text-slate-500 space-y-1">
                                        <div v-if="layout.footer.show_notes && currentInvoice.notes">
                                            <span class="font-bold text-slate-700">ملاحظات:</span> {{ currentInvoice.notes }}
                                        </div>
                                        <div v-if="layout.footer.show_terms && template.terms_and_conditions">
                                            <span class="font-bold text-slate-700">الشروط والأحكام:</span> {{ template.terms_and_conditions }}
                                        </div>
                                    </div>

                                    <!-- Signature and Stamp line -->
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

                                <div v-if="template.footer_text" class="text-center text-3xs text-slate-400 pt-2 border-t border-slate-100">
                                    {{ template.footer_text }}
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDE: Multi-Tab Configuration Sidebar (Columns 5 of 12) (Matches Image Exactly) -->
                <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden flex flex-col">
                    
                    <!-- Sidebar Section Navigation Bar (Vertical or Horizontal pills) -->
                    <div class="flex border-b border-slate-200 overflow-x-auto bg-slate-50/50 p-2 gap-1.5 scrollbar-thin">
                        <button 
                            v-for="t in tabs" 
                            :key="t.id"
                            @click="activeTab = t.id"
                            :class="[
                                activeTab === t.id 
                                    ? 'bg-white text-indigo-700 font-bold shadow-2xs border-slate-200' 
                                    : 'text-slate-600 hover:text-slate-900 border-transparent hover:bg-slate-100',
                                'px-3 py-2 rounded-xl border text-xs whitespace-nowrap flex items-center gap-1.5 transition'
                            ]"
                        >
                            <component :is="t.icon" class="w-4 h-4" />
                            <span>{{ t.label }}</span>
                        </button>
                    </div>

                    <!-- Tab Contents Container -->
                    <div class="p-5 space-y-6 text-xs max-h-[850px] overflow-y-auto">

                        <!-- TAB: المقدمة (Header / Introduction) - Active in screenshot! -->
                        <div v-if="activeTab === 'header'" class="space-y-5">
                            
                            <!-- Header Layout Quick Presets -->
                            <div>
                                <label class="block font-bold text-slate-800 mb-2">نماذج جاهزة لتوزيع الترويسة (الشعار والباركود)</label>
                                <div class="grid grid-cols-2 gap-2.5">
                                    <button 
                                        type="button"
                                        @click="setHeaderPreset('standard')"
                                        :class="[
                                            (layout.logo?.position === 'right' && layout.qr?.position === 'left') || layout.header.layout_order === 'logo_meta_qr'
                                                ? 'border-indigo-600 bg-indigo-50/70 text-indigo-900 shadow-2xs font-bold'
                                                : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700',
                                            'p-3 rounded-xl border text-right transition flex flex-col gap-1.5'
                                        ]"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold">شعار يمين | باركود يسار</span>
                                            <span class="text-3xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">الافتراضي</span>
                                        </div>
                                        <div class="flex items-center justify-between text-3xs text-slate-400 font-mono bg-slate-50 p-1 rounded">
                                            <span>[شعار]</span>
                                            <span>[عنوان]</span>
                                            <span>[QR]</span>
                                        </div>
                                    </button>

                                    <button 
                                        type="button"
                                        @click="setHeaderPreset('center_logo')"
                                        :class="[
                                            layout.logo?.position === 'center' || layout.header.layout_order === 'meta_logo_qr'
                                                ? 'border-indigo-600 bg-indigo-50/70 text-indigo-900 shadow-2xs font-bold'
                                                : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700',
                                            'p-3 rounded-xl border text-right transition flex flex-col gap-1.5'
                                        ]"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold">شعار بالمنتصف</span>
                                            <span class="text-3xs px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700">مميز</span>
                                        </div>
                                        <div class="flex items-center justify-between text-3xs text-slate-400 font-mono bg-slate-50 p-1 rounded">
                                            <span>[معلومات]</span>
                                            <span>[شعار]</span>
                                            <span>[QR]</span>
                                        </div>
                                    </button>

                                    <button 
                                        type="button"
                                        @click="setHeaderPreset('reversed')"
                                        :class="[
                                            (layout.logo?.position === 'left' || layout.qr?.position === 'right') && layout.logo?.position !== 'center'
                                                ? 'border-indigo-600 bg-indigo-50/70 text-indigo-900 shadow-2xs font-bold'
                                                : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700',
                                            'p-3 rounded-xl border text-right transition flex flex-col gap-1.5'
                                        ]"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold">باركود يمين | شعار يسار</span>
                                            <span class="text-3xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">معكوس</span>
                                        </div>
                                        <div class="flex items-center justify-between text-3xs text-slate-400 font-mono bg-slate-50 p-1 rounded">
                                            <span>[QR]</span>
                                            <span>[عنوان]</span>
                                            <span>[شعار]</span>
                                        </div>
                                    </button>

                                    <button 
                                        type="button"
                                        @click="setHeaderPreset('center_qr')"
                                        :class="[
                                            layout.qr?.position === 'center' || layout.header.layout_order === 'logo_qr_meta'
                                                ? 'border-indigo-600 bg-indigo-50/70 text-indigo-900 shadow-2xs font-bold'
                                                : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700',
                                            'p-3 rounded-xl border text-right transition flex flex-col gap-1.5'
                                        ]"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold">باركود بالمنتصف</span>
                                            <span class="text-3xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">زاتكا</span>
                                        </div>
                                        <div class="flex items-center justify-between text-3xs text-slate-400 font-mono bg-slate-50 p-1 rounded">
                                            <span>[شعار]</span>
                                            <span>[QR]</span>
                                            <span>[معلومات]</span>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <!-- Layout Order -->
                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">الترتيب التفصيلي لمقدمة الفاتورة</label>
                                <select 
                                    v-model="layout.header.layout_order"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800"
                                >
                                    <option value="logo_meta_qr">الشعار يمين، معلومات الفاتورة، QR كود يسار</option>
                                    <option value="meta_logo_qr">معلومات الفاتورة يمين، الشعار بالمنتصف، QR كود يسار</option>
                                    <option value="qr_meta_logo">QR كود يمين، معلومات الفاتورة، الشعار يسار</option>
                                    <option value="logo_qr_meta">الشعار يمين، QR كود بالمنتصف، معلومات الفاتورة يسار</option>
                                </select>
                            </div>

                            <!-- Divider line checkbox -->
                            <label class="flex items-center gap-2 cursor-pointer pt-1">
                                <input 
                                    v-model="layout.header.show_divider_line" 
                                    type="checkbox" 
                                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" 
                                />
                                <span class="font-medium text-slate-800">إظهار خط عرضي أسفل المقدمة</span>
                            </label>

                            <!-- Invoice Meta Fields Blocks (معلومات الفاتورة) -->
                            <div class="space-y-3 pt-3 border-t border-slate-100">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900 text-xs">معلومات الفاتورة</span>
                                    <button 
                                        @click="addMetaField()" 
                                        type="button"
                                        class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-3xs font-bold flex items-center gap-1 transition"
                                    >
                                        <PlusIcon class="w-3.5 h-3.5" />
                                        <span>إضافة حقل</span>
                                    </button>
                                </div>

                                <!-- Field Cards with Micro-Toolbar (Matching Image) -->
                                <div 
                                    v-for="(field, index) in layout.header.meta_fields" 
                                    :key="field.id || index"
                                    class="p-3 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2.5 relative group"
                                >
                                    <!-- Micro Toolbar -->
                                    <div class="flex items-center justify-between gap-1 bg-white p-1.5 rounded-lg border border-slate-200/80">
                                        <div class="flex items-center gap-1.5">
                                            <!-- Font Size Stepper -->
                                            <input 
                                                v-model.number="field.font_size" 
                                                type="number" 
                                                min="8" 
                                                max="24"
                                                class="w-12 h-7 px-1.5 text-center text-xs font-mono border border-slate-200 rounded-md"
                                                title="حجم الخط"
                                            />

                                            <!-- Color Picker -->
                                            <input 
                                                v-model="field.color" 
                                                type="color" 
                                                class="w-7 h-7 p-0.5 border border-slate-200 rounded-md cursor-pointer"
                                                title="لون النص"
                                            />

                                            <!-- Alignment Buttons -->
                                            <div class="flex items-center border border-slate-200 rounded-md overflow-hidden bg-slate-50">
                                                <button 
                                                    @click="field.alignment = 'right'" 
                                                    :class="field.alignment === 'right' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                    type="button" 
                                                    class="p-1 text-3xs hover:bg-slate-200"
                                                    title="محاذاة لليمين"
                                                >
                                                    يمين
                                                </button>
                                                <button 
                                                    @click="field.alignment = 'center'" 
                                                    :class="field.alignment === 'center' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                    type="button" 
                                                    class="p-1 text-3xs hover:bg-slate-200"
                                                    title="توسيط"
                                                >
                                                    وسط
                                                </button>
                                                <button 
                                                    @click="field.alignment = 'left'" 
                                                    :class="field.alignment === 'left' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                    type="button" 
                                                    class="p-1 text-3xs hover:bg-slate-200"
                                                    title="محاذاة لليسار"
                                                >
                                                    يسار
                                                </button>
                                            </div>

                                            <!-- Bold Toggle -->
                                            <button 
                                                @click="field.is_bold = !field.is_bold" 
                                                :class="field.is_bold ? 'bg-indigo-100 text-indigo-700 font-extrabold' : 'text-slate-600'"
                                                type="button" 
                                                class="w-7 h-7 rounded-md border border-slate-200 flex items-center justify-center font-bold text-xs"
                                                title="خط عريض Bold"
                                            >
                                                B
                                            </button>

                                            <!-- Tag Selector helper -->
                                            <select 
                                                @change="insertTagToField(field, $event.target.value)"
                                                class="h-7 text-3xs px-2 border border-slate-200 rounded-md bg-white text-indigo-600 font-mono"
                                                title="إدراج متغير ذكي"
                                            >
                                                <option value="" disabled selected>&#123;&#123;-&#125;&#125;</option>
                                                <option v-for="tag in availableTags" :key="tag.tag" :value="tag.tag">
                                                    {{ tag.label }}
                                                </option>
                                            </select>
                                        </div>

                                        <!-- Delete Field Button -->
                                        <button 
                                            @click="removeMetaField(index)" 
                                            type="button" 
                                            class="w-6 h-6 rounded-md hover:bg-rose-100 text-rose-500 flex items-center justify-center transition"
                                            title="حذف الحقل"
                                        >
                                            <XMarkIcon class="w-4 h-4 stroke-2" />
                                        </button>
                                    </div>

                                    <!-- Field Inputs: Label & Tag expression -->
                                    <div class="grid grid-cols-3 gap-2 items-center">
                                        <input 
                                            v-model="field.label" 
                                            type="text" 
                                            placeholder="مسمى الحقل (مثال: الرقم)"
                                            class="col-span-1 px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-slate-800"
                                        />
                                        <input 
                                            v-model="field.tag" 
                                            type="text" 
                                            :placeholder="'{{invoice.code}}'"
                                            class="col-span-2 px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-indigo-600"
                                        />
                                    </div>

                                    <!-- Add button below this card -->
                                    <div class="flex justify-center -mb-5 pt-1">
                                        <button 
                                            @click="addMetaField(index)" 
                                            type="button" 
                                            class="w-5 h-5 rounded-full bg-indigo-50 border border-indigo-200 text-indigo-600 flex items-center justify-center hover:bg-indigo-600 hover:text-white transition shadow-2xs"
                                            title="إضافة سطر هنا"
                                        >
                                            <PlusIcon class="w-3 h-3 stroke-2" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Title Configuration (العنوان) -->
                            <div class="space-y-3 pt-6 border-t border-slate-100">
                                <span class="font-bold text-slate-900 text-xs">العنوان (فاتورة مبيعات)</span>
                                
                                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2.5">
                                    <div class="flex items-center gap-1.5 bg-white p-1.5 rounded-lg border border-slate-200/80">
                                        <input 
                                            v-model.number="layout.header.title.font_size" 
                                            type="number" 
                                            min="10" 
                                            max="28"
                                            class="w-12 h-7 px-1.5 text-center text-xs font-mono border border-slate-200 rounded-md"
                                        />
                                        <input 
                                            v-model="layout.header.title.color" 
                                            type="color" 
                                            class="w-7 h-7 p-0.5 border border-slate-200 rounded-md cursor-pointer"
                                        />
                                        <div class="flex items-center border border-slate-200 rounded-md overflow-hidden bg-slate-50">
                                            <button 
                                                @click="layout.header.title.alignment = 'right'" 
                                                :class="layout.header.title.alignment === 'right' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                type="button" class="p-1 text-3xs"
                                            >يمين</button>
                                            <button 
                                                @click="layout.header.title.alignment = 'center'" 
                                                :class="layout.header.title.alignment === 'center' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                type="button" class="p-1 text-3xs"
                                            >وسط</button>
                                            <button 
                                                @click="layout.header.title.alignment = 'left'" 
                                                :class="layout.header.title.alignment === 'left' ? 'bg-indigo-100 text-indigo-700' : 'text-slate-500'"
                                                type="button" class="p-1 text-3xs"
                                            >يسار</button>
                                        </div>
                                        <button 
                                            @click="layout.header.title.is_bold = !layout.header.title.is_bold" 
                                            :class="layout.header.title.is_bold ? 'bg-indigo-100 text-indigo-700' : 'text-slate-600'"
                                            type="button" 
                                            class="w-7 h-7 rounded-md border border-slate-200 flex items-center justify-center font-bold text-xs"
                                        >
                                            B
                                        </button>
                                    </div>

                                    <input 
                                        v-model="layout.header.title.tag" 
                                        type="text" 
                                        class="w-full px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-indigo-600"
                                        :placeholder="'{{zatca.invoice.title.ar}}'"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- TAB: التصميم (Design) -->
                        <div v-else-if="activeTab === 'design'" class="space-y-4">
                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">اللون الرئيسي للترويسة والعناوين</label>
                                <div class="flex items-center gap-3">
                                    <input 
                                        v-model="layout.design.primary_color" 
                                        type="color" 
                                        class="w-10 h-10 rounded-xl border border-slate-200 cursor-pointer p-1"
                                    />
                                    <input 
                                        v-model="layout.design.primary_color" 
                                        type="text" 
                                        class="w-36 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">نوع الخط المستخدم</label>
                                <select 
                                    v-model="layout.design.font_family"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800"
                                >
                                    <option value="Cairo">Cairo (خط كلاسيكي رسمي)</option>
                                    <option value="Tajawal">Tajawal (خط عصري ناعم)</option>
                                    <option value="Almarai">Almarai (خط واضح للقراءة)</option>
                                    <option value="Alexandria">Alexandria (خط أعمال معاصر)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">حجم الخط الأساسي (px)</label>
                                <input 
                                    v-model.number="layout.design.font_size_base" 
                                    type="number" 
                                    min="10" 
                                    max="16"
                                    class="w-32 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs"
                                />
                            </div>
                        </div>

                        <!-- TAB: الصفحة (Page) -->
                        <div v-else-if="activeTab === 'page'" class="space-y-4">
                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">حجم ونوع الورق</label>
                                <select 
                                    v-model="layout.page.paper_size"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800"
                                >
                                    <option value="A4">A4 (ورق قياسي رسمي)</option>
                                    <option value="A5">A5 (نصف حجم A4)</option>
                                    <option value="thermal_80mm">طابعة حرارية كاشير 80mm</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">اتجاه الصفحة</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <button 
                                        @click="layout.page.orientation = 'portrait'" 
                                        :class="layout.page.orientation === 'portrait' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-600'"
                                        type="button" 
                                        class="p-2.5 rounded-xl border text-center transition"
                                    >
                                        طولي (Portrait)
                                    </button>
                                    <button 
                                        @click="layout.page.orientation = 'landscape'" 
                                        :class="layout.page.orientation === 'landscape' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-600'"
                                        type="button" 
                                        class="p-2.5 rounded-xl border text-center transition"
                                    >
                                        عرضي (Landscape)
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: الشعار (Logo) -->
                        <div v-else-if="activeTab === 'logo'" class="space-y-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="layout.logo.show" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-bold text-slate-800">إظهار شعار المنشأة في الفاتورة</span>
                            </label>

                            <div v-if="layout.logo.show" class="space-y-4 pt-3 border-t border-slate-100">
                                <!-- Height Controls -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <label class="font-bold text-slate-800 text-xs">ارتفاع الشعار</label>
                                        <div class="flex items-center gap-1">
                                            <input 
                                                v-model.number="layout.logo.height" 
                                                type="number" 
                                                min="30" 
                                                max="250" 
                                                class="w-16 h-7 text-center text-xs font-mono font-bold bg-white border border-slate-200 rounded-lg text-indigo-600"
                                            />
                                            <span class="text-3xs text-slate-400 font-mono">px</span>
                                        </div>
                                    </div>

                                    <input 
                                        v-model.number="layout.logo.height" 
                                        type="range" 
                                        min="30" 
                                        max="250" 
                                        class="w-full accent-indigo-600 cursor-pointer"
                                    />

                                    <!-- Quick Height Presets -->
                                    <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
                                        <span class="text-3xs text-slate-400">مقاسات سريعة:</span>
                                        <button 
                                            @click="layout.logo.height = 60" 
                                            type="button" 
                                            :class="layout.logo.height === 60 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            صغير (60)
                                        </button>
                                        <button 
                                            @click="layout.logo.height = 90" 
                                            type="button" 
                                            :class="layout.logo.height === 90 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            متوسط (90)
                                        </button>
                                        <button 
                                            @click="layout.logo.height = 130" 
                                            type="button" 
                                            :class="layout.logo.height === 130 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            كبير (130)
                                        </button>
                                        <button 
                                            @click="layout.logo.height = 180" 
                                            type="button" 
                                            :class="layout.logo.height === 180 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            كبير جداً (180)
                                        </button>
                                    </div>
                                </div>

                                <!-- Max Width Controls -->
                                <div class="space-y-2 pt-2 border-t border-slate-100">
                                    <div class="flex items-center justify-between">
                                        <label class="font-bold text-slate-800 text-xs">أقصى عرض للشعار (Max Width)</label>
                                        <div class="flex items-center gap-1">
                                            <input 
                                                v-model.number="layout.logo.max_width" 
                                                type="number" 
                                                min="100" 
                                                max="450" 
                                                class="w-16 h-7 text-center text-xs font-mono font-bold bg-white border border-slate-200 rounded-lg text-indigo-600"
                                            />
                                            <span class="text-3xs text-slate-400 font-mono">px</span>
                                        </div>
                                    </div>

                                    <input 
                                        v-model.number="layout.logo.max_width" 
                                        type="range" 
                                        min="100" 
                                        max="450" 
                                        class="w-full accent-indigo-600 cursor-pointer"
                                    />

                                    <!-- Quick Width Presets -->
                                    <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
                                        <span class="text-3xs text-slate-400">عرض سريع:</span>
                                        <button 
                                            @click="layout.logo.max_width = 200" 
                                            type="button" 
                                            :class="layout.logo.max_width === 200 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            قياسي (200)
                                        </button>
                                        <button 
                                            @click="layout.logo.max_width = 280" 
                                            type="button" 
                                            :class="layout.logo.max_width === 280 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            متوسط (280)
                                        </button>
                                        <button 
                                            @click="layout.logo.max_width = 360" 
                                            type="button" 
                                            :class="layout.logo.max_width === 360 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            عريض (360)
                                        </button>
                                    </div>
                                </div>

                                <!-- Position Alignment Controls -->
                                <div class="pt-2 border-t border-slate-100">
                                    <label class="block font-bold text-slate-800 mb-1.5 text-xs">موضع ومحاذاة الشعار</label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button 
                                            @click="setLogoPosition('right')" 
                                            :class="layout.logo.position === 'right' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >يمين</button>
                                        <button 
                                            @click="setLogoPosition('center')" 
                                            :class="layout.logo.position === 'center' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >توسيط</button>
                                        <button 
                                            @click="setLogoPosition('left')" 
                                            :class="layout.logo.position === 'left' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >يسار</button>
                                    </div>
                                    <p class="text-3xs text-slate-400 mt-1.5">
                                        * عند تغيير موضع الشعار، يتم تلقائياً موازنة موضع الباركود لضمان مظهر احترافي للفاتورة.
                                    </p>
                                </div>

                                <!-- Live Dimension Info -->
                                <div class="p-2.5 bg-indigo-50/60 rounded-xl border border-indigo-100 flex items-center justify-between text-3xs text-indigo-900">
                                    <span class="font-medium">الأبعاد المطبقة للشعار:</span>
                                    <span class="font-mono font-bold">{{ layout.logo.height }}px ارتفاع × {{ layout.logo.max_width || 280 }}px أقصى عرض</span>
                                </div>

                                <!-- Logo File Upload & Preview in Designer -->
                                <div class="pt-4 border-t border-slate-100 space-y-2.5">
                                    <label class="block font-bold text-slate-800">ملف الشعار</label>
                                    
                                    <div class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 bg-slate-50/70">
                                        <div class="w-16 h-16 rounded-lg bg-white border border-slate-200 flex items-center justify-center overflow-hidden flex-shrink-0 relative shadow-2xs">
                                            <img 
                                                v-if="companyLogo || company?.logo" 
                                                :src="companyLogo || company?.logo" 
                                                alt="الشعار" 
                                                class="w-full h-full object-contain p-1"
                                            />
                                            <div v-else class="text-slate-300">
                                                <PhotoIcon class="w-7 h-7 stroke-1" />
                                            </div>
                                            <div v-if="isUploadingLogo" class="absolute inset-0 bg-slate-900/40 backdrop-blur-2xs flex items-center justify-center text-white">
                                                <ArrowPathIcon class="w-4 h-4 animate-spin" />
                                            </div>
                                        </div>

                                        <div class="space-y-1.5 flex-1">
                                            <input 
                                                ref="logoInputRef" 
                                                type="file" 
                                                accept="image/png,image/jpeg,image/webp,image/gif" 
                                                class="hidden" 
                                                @change="uploadLogoFromDesigner"
                                            />
                                            <div class="flex items-center gap-1.5">
                                                <button 
                                                    type="button" 
                                                    @click="logoInputRef?.click()"
                                                    :disabled="isUploadingLogo"
                                                    class="px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-3xs font-bold shadow-2xs flex items-center gap-1 transition disabled:opacity-50"
                                                >
                                                    <ArrowUpTrayIcon class="w-3.5 h-3.5" />
                                                    <span>{{ companyLogo || company?.logo ? 'تغيير الشعار' : 'رفع شعار' }}</span>
                                                </button>

                                                <button 
                                                    v-if="companyLogo || company?.logo" 
                                                    type="button" 
                                                    @click="deleteLogoFromDesigner"
                                                    :disabled="isUploadingLogo"
                                                    class="px-2 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-3xs font-bold transition flex items-center gap-1"
                                                >
                                                    <TrashIcon class="w-3 h-3" />
                                                    <span>حذف</span>
                                                </button>
                                            </div>
                                            <span class="text-3xs text-slate-400 block">PNG, JPG, WebP حتى 3 ميجا</span>
                                            <p v-if="logoError" class="text-3xs text-rose-600 font-bold">{{ logoError }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: الباركود (QR Code) -->
                        <div v-else-if="activeTab === 'qr'" class="space-y-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="layout.qr.show" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-bold text-slate-800">إظهار رمز الاستجابة السريع (ZATCA QR Code)</span>
                            </label>

                            <div v-if="layout.qr?.show !== false" class="space-y-4 pt-3 border-t border-slate-100">
                                
                                <!-- QR Position Alignment Controls -->
                                <div>
                                    <label class="block font-bold text-slate-800 mb-1.5 text-xs">موضع ومحاذاة الباركود (QR)</label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button 
                                            @click="setQrPosition('right')" 
                                            :class="layout.qr?.position === 'right' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >يمين</button>
                                        <button 
                                            @click="setQrPosition('center')" 
                                            :class="layout.qr?.position === 'center' ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >توسيط</button>
                                        <button 
                                            @click="setQrPosition('left')" 
                                            :class="layout.qr?.position === 'left' || !layout.qr?.position ? 'bg-indigo-50 border-indigo-500 text-indigo-700 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700'"
                                            type="button" class="p-2 rounded-xl border text-center text-xs transition"
                                        >يسار</button>
                                    </div>
                                    <p class="text-3xs text-slate-400 mt-1.5">
                                        * في الفواتير الضريبية الرسمية، يفضل وضع الباركود في الزاوية اليسرى المقابلة للشعار.
                                    </p>
                                </div>

                                <!-- QR Size Controls -->
                                <div class="space-y-2 pt-2 border-t border-slate-100">
                                    <div class="flex items-center justify-between">
                                        <label class="font-bold text-slate-800 text-xs">حجم الباركود (العرض والارتفاع)</label>
                                        <div class="flex items-center gap-1">
                                            <input 
                                                v-model.number="layout.qr.size" 
                                                type="number" 
                                                min="60" 
                                                max="160" 
                                                class="w-16 h-7 text-center text-xs font-mono font-bold bg-white border border-slate-200 rounded-lg text-indigo-600"
                                            />
                                            <span class="text-3xs text-slate-400 font-mono">px</span>
                                        </div>
                                    </div>

                                    <input 
                                        v-model.number="layout.qr.size" 
                                        type="range" 
                                        min="60" 
                                        max="160" 
                                        class="w-full accent-indigo-600 cursor-pointer"
                                    />

                                    <!-- Quick Size Presets -->
                                    <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
                                        <span class="text-3xs text-slate-400">مقاسات سريعة:</span>
                                        <button 
                                            @click="layout.qr.size = 75" 
                                            type="button" 
                                            :class="layout.qr.size === 75 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            صغير (75)
                                        </button>
                                        <button 
                                            @click="layout.qr.size = 95" 
                                            type="button" 
                                            :class="layout.qr.size === 95 || !layout.qr.size ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            قياسي (95)
                                        </button>
                                        <button 
                                            @click="layout.qr.size = 115" 
                                            type="button" 
                                            :class="layout.qr.size === 115 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            متوسط (115)
                                        </button>
                                        <button 
                                            @click="layout.qr.size = 135" 
                                            type="button" 
                                            :class="layout.qr.size === 135 ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700'"
                                            class="px-2 py-0.5 rounded text-3xs transition"
                                        >
                                            كبير (135)
                                        </button>
                                    </div>
                                </div>

                                <!-- Live Dimension Info -->
                                <div class="p-2.5 bg-indigo-50/60 rounded-xl border border-indigo-100 flex items-center justify-between text-3xs text-indigo-900">
                                    <span class="font-medium">المقاس المطبق للباركود:</span>
                                    <span class="font-mono font-bold">{{ layout.qr?.size || 95 }}px × {{ layout.qr?.size || 95 }}px</span>
                                </div>

                                <!-- QR Preview in Sidebar -->
                                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center gap-3">
                                    <div class="p-1 bg-white border border-slate-200 rounded-lg shadow-2xs flex-shrink-0">
                                        <img v-if="qrDataUrl" :src="qrDataUrl" alt="ZATCA QR Preview" class="w-16 h-16 object-contain" />
                                        <div v-else class="w-16 h-16 bg-slate-100 flex items-center justify-center text-3xs text-slate-400">QR</div>
                                    </div>
                                    <div class="space-y-1 text-3xs text-slate-500">
                                        <p class="font-bold text-slate-700">باركود الفوترة الإلكترونية (ZATCA)</p>
                                        <p>مشفر ومتوافق مع متطلبات المرحلة الثانية لهيئة الزكاة والضريبة والجمارك.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: الشركة (Company) -->
                        <div v-else-if="activeTab === 'company'" class="space-y-3">
                            <span class="font-bold text-slate-900 block mb-2">الحقول الظاهرة لبيانات الشركة (البائع):</span>
                            
                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.company.show_name" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">اسم المنشأة التجاري</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.company.show_vat" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">الرقم الضريبي للمنشأة (VAT)</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.company.show_cr" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">رقم السجل التجاري (CR)</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.company.show_address" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">العنوان الوطني / المدينة</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.company.show_phone" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">رقم الهاتف والتواصل</span>
                            </label>
                        </div>

                        <!-- TAB: العميل/المورد (Customer) -->
                        <div v-else-if="activeTab === 'customer'" class="space-y-3">
                            <span class="font-bold text-slate-900 block mb-2">الحقول الظاهرة لبيانات العميل (المشتري):</span>
                            
                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.customer.show_name" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">اسم العميل</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.customer.show_vat" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">الرقم الضريبي للعميل</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.customer.show_address" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">عنوان العميل</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                <input v-model="layout.customer.show_phone" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">رقم هاتف العميل</span>
                            </label>
                        </div>

                        <!-- TAB: الجدول (Table) -->
                        <div v-else-if="activeTab === 'table'" class="space-y-4">
                            <div>
                                <label class="block font-bold text-slate-800 mb-1.5">لون خلفية ترويسة الجدول</label>
                                <div class="flex items-center gap-3">
                                    <input v-model="layout.table.header_bg" type="color" class="w-10 h-10 rounded-xl border border-slate-200 cursor-pointer p-1" />
                                    <input v-model="layout.table.header_bg" type="text" class="w-36 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs" />
                                </div>
                            </div>

                            <div class="space-y-2 pt-2">
                                <span class="font-bold text-slate-900 block">أعمدة جدول الفاتورة:</span>
                                <div 
                                    v-for="col in layout.table.columns" 
                                    :key="col.key"
                                    class="p-2.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between gap-3"
                                >
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input v-model="col.visible" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                        <span class="font-semibold text-slate-800">{{ col.label }}</span>
                                    </label>
                                    
                                    <div class="flex items-center gap-2">
                                        <input 
                                            v-model="col.label" 
                                            type="text" 
                                            class="w-28 px-2 py-1 bg-white border border-slate-200 rounded-lg text-3xs font-medium"
                                            placeholder="المسمى"
                                        />
                                        <select 
                                            v-model="col.align" 
                                            class="px-2 py-1 bg-white border border-slate-200 rounded-lg text-3xs"
                                        >
                                            <option value="right">يمين</option>
                                            <option value="center">وسط</option>
                                            <option value="left">يسار</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: التذييل (Footer) -->
                        <div v-else-if="activeTab === 'footer'" class="space-y-4">
                            <span class="font-bold text-slate-900 block">خيارات المجاميع والتذييل:</span>
                            
                            <div class="grid grid-cols-2 gap-2.5">
                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_subtotal" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">المجموع قبل الضريبة</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_vat_15" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">ضريبة {{ $taxRate() }}%</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_grand_total" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">الإجمالي الشامل</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_paid" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">المبلغ المدفوع</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_due" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">المبلغ المتبقي</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_signature" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">خط التوقيع</span>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer p-2.5 bg-slate-50 rounded-xl">
                                    <input v-model="layout.footer.show_stamp" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                    <span class="text-3xs font-medium text-slate-800">مكان الختم الرسمي</span>
                                </label>
                            </div>

                            <div class="space-y-3 pt-3 border-t border-slate-100">
                                <div>
                                    <label class="block font-bold text-slate-800 mb-1">الشروط والأحكام</label>
                                    <textarea 
                                        v-model="template.terms_and_conditions" 
                                        rows="3" 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs"
                                    ></textarea>
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-800 mb-1">نص تذييل الصفحة (Footer Text)</label>
                                    <textarea 
                                        v-model="template.footer_text" 
                                        rows="2" 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs"
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
