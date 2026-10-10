<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import {
    HomeIcon,
    ChevronRightIcon,
    BuildingOffice2Icon,
    DocumentTextIcon,
    GlobeAltIcon,
    CheckCircleIcon,
    DeviceTabletIcon,
    ShieldCheckIcon,
    KeyIcon,
    CheckBadgeIcon,
    ArrowPathIcon,
    InformationCircleIcon,
    PhotoIcon,
    ArrowUpTrayIcon,
    TrashIcon,
    LockClosedIcon,
    ScaleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    company: Object,
    settings: Object,
    accounts: Array,
});

const activeTab = ref('general');

const findDefaultAccountId = (role) => {
    if (props.settings?.[`acc_${role}_id`]) {
        return Number(props.settings[`acc_${role}_id`]);
    }
    const accList = props.accounts || [];
    if (!accList.length) return '';

    if (role === 'sales_revenue') {
        const found = accList.find(a => a.code === '41' || a.code === '4101' || a.code === '4001' || a.name_ar?.includes('مبيعات') || a.name_ar?.includes('إيراد'))
            || accList.find(a => a.type === 'revenue');
        return found ? found.id : '';
    }
    if (role === 'purchases_cogs') {
        const found = accList.find(a => a.code === '51' || a.code === '5101' || a.code === '5001' || a.name_ar?.includes('مشتريات') || a.name_ar?.includes('بضاعة'))
            || accList.find(a => a.type === 'expense');
        return found ? found.id : '';
    }
    if (role === 'vat_output') {
        const found = accList.find(a => a.code === '212' || a.code === '2102' || a.name_ar?.includes('محصلة') || (a.name_ar?.includes('ضريبة') && a.type === 'liability'))
            || accList.find(a => a.name_ar?.includes('ضريبة') && a.type === 'liability');
        return found ? found.id : '';
    }
    if (role === 'vat_input') {
        const found = accList.find(a => a.code === '114' || a.code === '1104' || a.name_ar?.includes('مدخلة') || (a.name_ar?.includes('ضريبة') && a.type === 'asset'))
            || accList.find(a => a.name_ar?.includes('ضريبة') && a.type === 'asset');
        return found ? found.id : '';
    }
    if (role === 'receivables') {
        const found = accList.find(a => a.code === '1121' || a.code === '112' || a.name_ar?.includes('عملاء') || a.name_ar?.includes('مدين'))
            || accList.find(a => a.type === 'asset');
        return found ? found.id : '';
    }
    if (role === 'payables') {
        const found = accList.find(a => a.code === '211' || a.code === '2101' || a.name_ar?.includes('موردين') || a.name_ar?.includes('دائن'))
            || accList.find(a => a.type === 'liability');
        return found ? found.id : '';
    }
    if (role === 'default_cash') {
        const found = accList.find(a => a.code === '1111' || a.code === '1101' || a.name_ar?.includes('صندوق') || a.name_ar?.includes('خزينة'))
            || accList.find(a => a.type === 'asset');
        return found ? found.id : '';
    }
    if (role === 'default_bank') {
        const found = accList.find(a => a.code === '1112' || a.code === '1102' || a.name_ar?.includes('بنك') || a.name_ar?.includes('مصرف'))
            || accList.find(a => a.type === 'asset');
        return found ? found.id : '';
    }
    return '';
};

const form = useForm({
    name: props.company?.name || '',
    email: props.company?.email || '',
    phone: props.company?.phone || '',
    tax_number: props.company?.tax_number || '',
    commercial_registration: props.company?.commercial_registration || '',
    currency: props.company?.currency || 'SAR',
    timezone: props.company?.timezone || 'Asia/Riyadh',
    language: props.company?.language || 'ar',

    // Invoicing & Tax
    vat_rate: props.settings?.vat_rate ? Number(props.settings.vat_rate) : 15,
    invoice_prefix: props.settings?.invoice_prefix || 'INV',
    invoice_terms: props.settings?.invoice_terms || 'تطبق الشروط والأحكام الخاصة بالمبيعات.',
    document_message: props.settings?.document_message || '',
    pos_auto_print: props.settings?.pos_auto_print === '1',

    // ZATCA Settings
    zatca_phase: props.settings?.zatca_phase || 'phase1',
    zatca_environment: props.settings?.zatca_environment || 'sandbox',
    zatca_otp: props.settings?.zatca_otp || '',

    // Accounting Default Mappings (الربط والتوجيه المحاسبي)
    acc_sales_revenue_id: findDefaultAccountId('sales_revenue'),
    acc_purchases_cogs_id: findDefaultAccountId('purchases_cogs'),
    acc_vat_output_id: findDefaultAccountId('vat_output'),
    acc_vat_input_id: findDefaultAccountId('vat_input'),
    acc_receivables_id: findDefaultAccountId('receivables'),
    acc_payables_id: findDefaultAccountId('payables'),
    acc_default_cash_id: findDefaultAccountId('default_cash'),
    acc_default_bank_id: findDefaultAccountId('default_bank'),
});

const onboardForm = useForm({
    otp: '',
    environment: props.settings?.zatca_environment || 'sandbox',
});

const saveSettings = () => {
    form.put(route('settings.update'), {
        preserveScroll: true,
    });
};

const triggerOnboard = () => {
    onboardForm.environment = form.zatca_environment;
    onboardForm.post(route('settings.zatca.onboard'), {
        preserveScroll: true,
        onSuccess: () => {
            onboardForm.otp = '';
        }
    });
};

const currentLogo = ref(props.company?.logo_url || props.company?.logo || null);
const isUploadingLogo = ref(false);
const logoError = ref('');
const fileInputRef = ref(null);

const handleLogoUpload = async (event) => {
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
            currentLogo.value = response.data.logo;
            router.reload({ only: ['company'] });
        }
    } catch (err) {
        logoError.value = err.response?.data?.message || 'حدث خطأ أثناء رفع الشعار.';
    } finally {
        isUploadingLogo.value = false;
        if (fileInputRef.value) fileInputRef.value.value = '';
    }
};

const removeLogo = async () => {
    if (!confirm('هل أنت متأكد من حذف شعار المنشأة؟')) return;

    isUploadingLogo.value = true;
    try {
        const response = await axios.delete(route('settings.logo.delete'));
        if (response.data.success) {
            currentLogo.value = null;
            router.reload({ only: ['company'] });
        }
    } catch (err) {
        logoError.value = 'تعذر حذف الشعار.';
    } finally {
        isUploadingLogo.value = false;
    }
};
</script>

<template>
    <AppLayout>
        <Head title="إعدادات المنشأة والزكاة - الأستاذ" />

        <div class="space-y-5 w-full pb-12">
            <!-- Top Breadcrumbs & Action Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs Ribbon -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الإعدادات والفوترة</span>
                    </div>

                    <!-- Refresh Button -->
                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Action Button -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="saveSettings"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150 disabled:opacity-50"
                    >
                        <CheckBadgeIcon class="w-4 h-4 stroke-2" />
                        <span>{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ كافة التعديلات' }}</span>
                    </button>
                </div>
            </div>

            <!-- Settings Layout: Tabs + Content -->
            <div class="grid grid-cols-1 lg:grid-cols-4 xl:grid-cols-5 gap-6 items-start">
                <!-- Navigation Tabs Sidebar -->
                <div class="lg:col-span-1 bg-white rounded-2xl border border-slate-200/80 p-2 shadow-xs space-y-1 sticky top-20">
                    <button 
                        type="button"
                        @click="activeTab = 'general'"
                        :class="activeTab === 'general' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <BuildingOffice2Icon class="w-4 h-4 text-indigo-600" />
                        <span>بيانات المنشأة</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'zatca'"
                        :class="activeTab === 'zatca' ? 'bg-emerald-50 text-emerald-800 font-bold border border-emerald-200' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition relative"
                    >
                        <ShieldCheckIcon class="w-4 h-4 text-emerald-600" />
                        <span>الفوترة والزكاة (ZATCA)</span>
                        <span 
                            class="absolute end-2 top-2.5 w-2 h-2 rounded-full"
                            :class="form.zatca_phase === 'phase2' ? 'bg-indigo-600' : 'bg-emerald-500'"
                        ></span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'invoicing'"
                        :class="activeTab === 'invoicing' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <DocumentTextIcon class="w-4 h-4 text-indigo-600" />
                        <span>الفوترة والضريبة</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'accounting'"
                        :class="activeTab === 'accounting' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <ScaleIcon class="w-4 h-4 text-indigo-600" />
                        <span>الربط والتوجيه المحاسبي</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'system'"
                        :class="activeTab === 'system' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <GlobeAltIcon class="w-4 h-4 text-indigo-600" />
                        <span>العملة واللغة</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'pos'"
                        :class="activeTab === 'pos' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <DeviceTabletIcon class="w-4 h-4 text-indigo-600" />
                        <span>نقاط البيع (POS)</span>
                    </button>
                </div>

                <!-- Settings Content Form -->
                <div class="lg:col-span-3 xl:col-span-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 sm:p-8">
                    <form @submit.prevent="saveSettings">
                        <!-- 1. General Profile Tab -->
                        <div v-show="activeTab === 'general'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                الهوية والبيانات الرسمية للمنشأة
                            </h2>

                            <!-- Logo Upload Card -->
                            <div class="mb-5 p-4 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/60 hover:bg-slate-50 transition">
                                <label class="block text-xs font-bold text-slate-800 mb-2">شعار المنشأة / المؤسسة (Logo)</label>
                                
                                <div class="flex flex-col sm:flex-row items-center gap-4">
                                    <!-- Logo Preview -->
                                    <div class="relative group w-24 h-24 sm:w-28 sm:h-28 rounded-xl bg-white border border-slate-200 flex items-center justify-center overflow-hidden shadow-2xs flex-shrink-0">
                                        <img 
                                            v-if="currentLogo" 
                                            :src="currentLogo" 
                                            alt="شعار المنشأة" 
                                            class="w-full h-full object-contain p-2"
                                        />
                                        <div v-else class="text-center p-2 text-slate-400">
                                            <PhotoIcon class="w-8 h-8 mx-auto stroke-1 text-slate-300" />
                                            <span class="text-3xs font-medium block mt-1">لا يوجد شعار</span>
                                        </div>
                                        
                                        <div v-if="isUploadingLogo" class="absolute inset-0 bg-slate-900/40 backdrop-blur-2xs flex items-center justify-center text-white">
                                            <ArrowPathIcon class="w-6 h-6 animate-spin" />
                                        </div>
                                    </div>

                                    <!-- Upload Controls & Guidelines -->
                                    <div class="space-y-2 flex-1 text-center sm:text-start">
                                        <div class="flex flex-wrap items-center gap-2 justify-center sm:justify-start">
                                            <input 
                                                ref="fileInputRef" 
                                                type="file" 
                                                accept="image/png,image/jpeg,image/webp,image/gif" 
                                                class="hidden" 
                                                @change="handleLogoUpload"
                                            />
                                            
                                            <button 
                                                type="button" 
                                                @click="fileInputRef?.click()"
                                                :disabled="isUploadingLogo"
                                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs flex items-center gap-1.5 transition disabled:opacity-50"
                                            >
                                                <ArrowUpTrayIcon class="w-4 h-4 stroke-2" />
                                                <span>{{ currentLogo ? 'تغيير الشعار' : 'رفع شعار المنشأة' }}</span>
                                            </button>

                                            <button 
                                                v-if="currentLogo"
                                                type="button" 
                                                @click="removeLogo"
                                                :disabled="isUploadingLogo"
                                                class="px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition flex items-center gap-1"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                                <span>حذف</span>
                                            </button>
                                        </div>

                                        <p class="text-3xs text-slate-400 leading-relaxed">
                                            الصيغ المدعومة: PNG, JPG, WebP, GIF (حتى 3 ميجابايت).<br>
                                            يظهر الشعار تلقائياً في ترويسة الفواتير الضريبية المطبوعة، قوالب الـ POS الحرارية، وعروض الأسعار.
                                        </p>

                                        <p v-if="logoError" class="text-3xs text-rose-600 font-bold">
                                            {{ logoError }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">اسم المؤسسة / الشركة *</label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    required
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                                />
                                <p v-if="form.errors.name" class="text-[11px] text-rose-600 mt-1">{{ form.errors.name }}</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي (ZATCA VAT) *</label>
                                    <input 
                                        v-model="form.tax_number" 
                                        type="text" 
                                        placeholder="300000000000003"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                    <p class="text-2xs text-slate-400 mt-1">الرقم المكون من 15 خانة المسجل لدى هيئة الزكاة.</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم السجل التجاري (CR)</label>
                                    <input 
                                        v-model="form.commercial_registration" 
                                        type="text" 
                                        placeholder="1010000000"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني الرسمي</label>
                                    <input 
                                        v-model="form.email" 
                                        type="email" 
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف أو الجوال</label>
                                    <input 
                                        v-model="form.phone" 
                                        type="text" 
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- 2. ZATCA E-Invoicing Phase 1 & Phase 2 Tab -->
                        <div v-show="activeTab === 'zatca'" class="space-y-6">
                            <div class="border-b border-slate-100 pb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div>
                                    <h2 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                        <ShieldCheckIcon class="w-5 h-5 text-emerald-600" />
                                        <span>منظومة الفوترة الإلكترونية (ZATCA Fatoora)</span>
                                    </h2>
                                    <p class="text-2xs text-slate-500 mt-0.5">تحديد مرحلة الالتزام الضريبي وإدارة الربط مع منصة "فاتورة" لهيئة الزكاة والضريبة والجمارك</p>
                                </div>
                                <span 
                                    class="text-xs font-bold px-3 py-1 rounded-full border self-start"
                                    :class="form.zatca_phase === 'phase2' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'"
                                >
                                    {{ form.zatca_phase === 'phase2' ? 'المرحلة الثانية (الربط والتكامل)' : 'المرحلة الأولى (الإصدار والحفظ)' }}
                                </span>
                            </div>

                            <!-- Phase Selection Interactive Cards -->
                            <div class="space-y-3">
                                <label class="block text-xs font-black text-slate-800">اختر المرحلة المطبقة في منشأتك *</label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <!-- Phase 1 Card -->
                                    <div 
                                        @click="form.zatca_phase = 'phase1'"
                                        class="cursor-pointer rounded-2xl p-4 border-2 transition-all relative"
                                        :class="form.zatca_phase === 'phase1' ? 'border-emerald-500 bg-emerald-50/30 shadow-xs' : 'border-slate-200 hover:border-slate-300 bg-white'"
                                    >
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-center gap-2">
                                                <div class="w-4 h-4 rounded-full border flex items-center justify-center" :class="form.zatca_phase === 'phase1' ? 'border-emerald-600 bg-emerald-600' : 'border-slate-300'">
                                                    <div v-if="form.zatca_phase === 'phase1'" class="w-1.5 h-1.5 rounded-full bg-white"></div>
                                                </div>
                                                <h3 class="text-xs font-black text-slate-800">المرحلة الأولى: الإصدار والحفظ</h3>
                                            </div>
                                            <span class="text-3xs font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">بدون إنترنت</span>
                                        </div>

                                        <p class="text-2xs text-slate-500 mt-2 leading-relaxed">
                                            إصدار الفواتير إلكترونياً وحفظها مع توليد الـ QR Code المشفر TLV محلياً دون الحاجة للربط المباشر مع خوادم الهيئة.
                                        </p>

                                        <ul class="text-2xs text-slate-600 mt-3 space-y-1 font-medium">
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" />
                                                <span>QR Code خماسي الحقول متوافق مع تطبيق زاتكا</span>
                                            </li>
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" />
                                                <span>جاهزة وتعمل فوراً لجميع فواتير المبيعات ونقاط البيع</span>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Phase 2 Card -->
                                    <div 
                                        @click="form.zatca_phase = 'phase2'"
                                        class="cursor-pointer rounded-2xl p-4 border-2 transition-all relative"
                                        :class="form.zatca_phase === 'phase2' ? 'border-indigo-600 bg-indigo-50/30 shadow-xs' : 'border-slate-200 hover:border-slate-300 bg-white'"
                                    >
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-center gap-2">
                                                <div class="w-4 h-4 rounded-full border flex items-center justify-center" :class="form.zatca_phase === 'phase2' ? 'border-indigo-600 bg-indigo-600' : 'border-slate-300'">
                                                    <div v-if="form.zatca_phase === 'phase2'" class="w-1.5 h-1.5 rounded-full bg-white"></div>
                                                </div>
                                                <h3 class="text-xs font-black text-slate-800">المرحلة الثانية: الربط والتكامل</h3>
                                            </div>
                                            <span class="text-3xs font-bold px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">ربط سحابي API</span>
                                        </div>

                                        <p class="text-2xs text-slate-500 mt-2 leading-relaxed">
                                            الربط المباشر مع منصة "فاتورة" (ZATCA Fatoora Portal) عبر واجهات برمجة التطبيقات، مع التوقيع الرقمي والشهادات المشفرة (CSID).
                                        </p>

                                        <ul class="text-2xs text-slate-600 mt-3 space-y-1 font-medium">
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" />
                                                <span>بناء ملفات UBL 2.1 XML والهاش المشفر</span>
                                            </li>
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" />
                                                <span>اعتماد الفواتير (Clearance) والإبلاغ (Reporting)</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div v-show="form.zatca_phase === 'phase2'" class="bg-indigo-50/60 border border-indigo-200/80 rounded-2xl p-5 text-xs space-y-2">
                                <h4 class="font-black text-indigo-900">الربط والتكامل (المرحلة الثانية)</h4>
                                <p class="text-indigo-700">تُدار أجهزة الفوترة (EGS) والشهادات وفحوصات الامتثال ومتابعة الاعتماد والإبلاغ من شاشة الفوترة الإلكترونية. تتفعل المرحلة الثانية تلقائياً عند ربط جهاز.</p>
                                <Link :href="route('zatca.index')" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">فتح شاشة الفوترة الإلكترونية</Link>
                            </div>
                        </div>

                        <!-- 3. Invoicing & Tax Tab -->
                        <div v-show="activeTab === 'invoicing'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                إعدادات الفاتورة الإلكترونية والضرائب
                            </h2>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">نسبة ضريبة القيمة المضافة (%) *</label>
                                    <input 
                                        v-model.number="form.vat_rate" 
                                        type="number" 
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono font-bold"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">بادئة ترقيم الفواتير</label>
                                    <input 
                                        v-model="form.invoice_prefix" 
                                        type="text" 
                                        placeholder="INV"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono uppercase"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">الشروط والأحكام الافتراضية للفاتورة</label>
                                <textarea 
                                    v-model="form.invoice_terms" 
                                    rows="4"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 leading-relaxed"
                                    placeholder="تظهر هذه الشروط في أسفل جميع الفواتير الصادرة للعملاء..."
                                ></textarea>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">نص رسالة إرسال المستندات للعملاء (بريد / واتساب)</label>
                                <textarea
                                    v-model="form.document_message"
                                    rows="5"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 leading-relaxed"
                                    placeholder="اتركه فارغاً لاستخدام النص الافتراضي"
                                ></textarea>
                                <p class="text-2xs text-slate-400 mt-1">المتغيرات المتاحة: {customer} {document} {number} {total} {company}</p>
                            </div>
                        </div>

                        <!-- Accounting Tab (الربط والتوجيه المحاسبي) -->
                        <div v-show="activeTab === 'accounting'" class="space-y-6">
                            <div class="border-b border-slate-100 pb-3 mb-2 flex items-center justify-between">
                                <div>
                                    <h2 class="text-sm font-black text-slate-900">
                                        الربط والتوجيه المحاسبي لشجرة الحسابات
                                    </h2>
                                    <p class="text-2xs text-slate-500 mt-1">
                                        حدد الحسابات الافتراضية في شجرة الحسابات التي يتم توجيه القيود اليومية التلقائية إليها عند إصدار الفواتير أو المشتريات أو الضرائب.
                                    </p>
                                </div>
                                <span class="px-2.5 py-1 bg-indigo-50 text-indigo-700 text-3xs font-black rounded-lg border border-indigo-100">
                                    دليل الحسابات المخصص
                                </span>
                            </div>

                            <div class="bg-amber-50/70 border border-amber-200/80 rounded-xl p-3.5 flex items-start gap-2.5 text-xs text-amber-900">
                                <InformationCircleIcon class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                                <div class="text-[11px] leading-relaxed">
                                    <strong class="font-bold block text-amber-950 mb-0.5">كيف يتعرف النظام على الحسابات في حالة إنشاء شجرة حسابات مخصصة؟</strong>
                                    يقوم النظام بربط المعاملات التشغيلية (فواتير بيع، شراء، دفعات نقدية، تحويلات بنكية، ضرائب) مع الحسابات المحددة أدناه لإنشاء القيود آلياً. في حالة لم يتم تحديد حساب، سيعتمد النظام ذكياً على الأرقام الدليلية القياسية.
                                </div>
                            </div>

                            <!-- 1. المبيعات والعملاء -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span>حسابات المبيعات والعملاء (Sales & Receivables)</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            حساب إيرادات المبيعات (الدائن الافتراضي) *
                                        </label>
                                        <select 
                                            v-model="form.acc_sales_revenue_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب المبيعات من الدليل --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُسجل في الطرف الدائن كإيراد عند إصدار أي فاتورة بيع.</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            حساب الذمم المدينة / العملاء (المدين في الآجل) *
                                        </label>
                                        <select 
                                            v-model="form.acc_receivables_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب العملاء والمدينين --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُسجل في الطرف المدين في حالة الفواتير الآجلة أو المبالغ المتبقية غير المسددة.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. المشتريات والموردين -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                    <span>حسابات المشتريات والموردين (Purchases & Payables)</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            حساب المشتريات / تكلفة البضاعة المباعة *
                                        </label>
                                        <select 
                                            v-model="form.acc_purchases_cogs_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب تكلفة المشتريات --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُسجل كمصروف أو تكلفة مشتريات عند شراء بضاعة ومواد.</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            حساب الذمم الدائنة / الموردين *
                                        </label>
                                        <select 
                                            v-model="form.acc_payables_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب الموردين والدائنين --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُسجل في الطرف الدائن كالتزام مالي عند فواتير المشتريات الآجلة.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. ضريبة القيمة المضافة -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                                    <span>حسابات ضريبة القيمة المضافة (VAT Accounts)</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            ضريبة القيمة المضافة المحصلة (مخرجات المبيعات 15%) *
                                        </label>
                                        <select 
                                            v-model="form.acc_vat_output_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب ضريبة المبيعات المحصلة --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">حساب التزام مستحق للهيئة يُسجل فيه مبلغ الضريبة المضاف على فواتير المبيعات.</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            ضريبة القيمة المضافة المدخلة (مدخلات المشتريات) *
                                        </label>
                                        <select 
                                            v-model="form.acc_vat_input_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب ضريبة المشتريات المدخلة --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">حساب أصل متداول يُسجل فيه ضريبة المشتريات القابلة للاسترداد والخصم من الإقرار.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. النقدية والبنوك الافتراضية -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span>النقدية والبنوك الافتراضية (Cash & Banks)</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            الصندوق الرئيسي الافتراضي (الخزينة النقدية) *
                                        </label>
                                        <select 
                                            v-model="form.acc_default_cash_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر حساب الصندوق النقدي الرئيسي --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُستخدم لتسجيل المقبوضات النقدية السريعة وفواتير نقاط البيع (الكاش).</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            الحساب البنكي الافتراضي الرئيسي *
                                        </label>
                                        <select 
                                            v-model="form.acc_default_bank_id"
                                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 bg-white"
                                        >
                                            <option value="">-- اختر الحساب البنكي الافتراضي --</option>
                                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                                                {{ acc.code }} - {{ acc.name_ar }} ({{ acc.type }})
                                            </option>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">يُعتمد تلقائياً للتحويلات البنكية ومدفوعات الشبكة إذا لم يحدد الكاشير بنكاً بعينه.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. System & Localization Tab -->
                        <div v-show="activeTab === 'system'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                العملة والمنطقة واللغة
                            </h2>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="sm:col-span-3 lg:col-span-1">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-bold text-slate-700">العملة الأساسية</label>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                            <LockClosedIcon class="w-3 h-3 text-slate-500" />
                                            <span>مقفلة محاسبياً</span>
                                        </span>
                                    </div>
                                    <select 
                                        v-model="form.currency"
                                        disabled
                                        class="w-full text-xs rounded-xl border-slate-200 bg-slate-100/90 text-slate-600 cursor-not-allowed font-bold"
                                    >
                                        <option value="SAR">ريال سعودي (SAR - ر.س)</option>
                                        <option value="AED">درهم إماراتي (AED - د.إ)</option>
                                        <option value="USD">دولار أمريكي (USD - $)</option>
                                        <option value="EUR">يورو (EUR - €)</option>
                                        <option value="EGP">جنيه مصري (EGP - ج.م)</option>
                                        <option value="KWD">دينار كويتي (KWD - د.ك)</option>
                                        <option value="BHD">دينار بحريني (BHD - د.ب)</option>
                                        <option value="OMR">ريال عماني (OMR - ر.ع)</option>
                                        <option value="QAR">ريال قطري (QAR - ر.ق)</option>
                                    </select>
                                    <p class="text-[11px] text-amber-800 bg-amber-50/70 border border-amber-200/60 rounded-xl p-2.5 mt-2 flex items-start gap-1.5 leading-relaxed font-medium">
                                        <LockClosedIcon class="w-3.5 h-3.5 text-amber-600 mt-0.5 flex-shrink-0" />
                                        <span>تم قفل العملة الأساسية للحفاظ على سلامة القيود المحاسبية، وتطابق الفواتير الضريبية مع منظومة ZATCA، ومنع تعارض أسعار الصرف.</span>
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">المنطقة الزمنية</label>
                                    <select 
                                        v-model="form.timezone"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                    >
                                        <option value="Asia/Riyadh">Asia/Riyadh (GMT+3)</option>
                                        <option value="Asia/Dubai">Asia/Dubai (GMT+4)</option>
                                        <option value="Africa/Cairo">Africa/Cairo (GMT+2)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">لغة النظام الافتراضية</label>
                                    <select 
                                        v-model="form.language"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                                    >
                                        <option value="ar">العربية (Arabic)</option>
                                        <option value="en">الإنجليزية (English)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 5. POS Tab -->
                        <div v-show="activeTab === 'pos'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                إعدادات نقاط البيع (POS)
                            </h2>

                            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/60">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input 
                                        v-model="form.pos_auto_print" 
                                        type="checkbox" 
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-600 w-4 h-4"
                                    />
                                    <div>
                                        <span class="text-xs font-bold text-slate-800">الطباعة التلقائية للإيصال بعد إتمام الطلب</span>
                                        <p class="text-[11px] text-slate-500">يقوم بإرسال الإيصال المبسط مباشرة إلى طابعة الكاشير الحرارية فور الضغط على دفع.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Submit Footer -->
                        <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-end">
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-2 disabled:opacity-50"
                            >
                                <CheckCircleIcon class="w-4 h-4" />
                                <span>حفظ جميع الإعدادات</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
