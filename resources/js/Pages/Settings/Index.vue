<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LocalAccessCard from '@/Components/LocalAccessCard.vue';
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
    roles: { type: Array, default: () => [] },
    twoFactorRoles: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    countryLocked: { type: Boolean, default: false },
    localAccess: { type: Array, default: null },
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
    country_code: props.company?.country_code || 'SA',
    timezone: props.company?.timezone || 'Asia/Riyadh',
    language: props.company?.language || 'ar',

    // Invoicing & Tax
    vat_rate: props.settings?.vat_rate ? Number(props.settings.vat_rate) : 15,
    invoice_terms: props.settings?.invoice_terms || 'تطبق الشروط والأحكام الخاصة بالمبيعات.',
    document_message: props.settings?.document_message || '',
    show_hijri: props.settings?.show_hijri !== '0',
    two_factor_required_roles: [...(props.twoFactorRoles || [])],
    session_timeout_minutes: props.settings?.session_timeout_minutes ?? 30,
    pos_auto_print: props.settings?.pos_auto_print === '1',
    pos_allow_credit: props.settings?.pos_allow_credit === '1',
    pos_max_discount_percent: props.settings?.pos_max_discount_percent ?? 20,
    budget_enforcement: props.settings?.budget_enforcement ?? 'off',

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
useUnsavedGuard(form);

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
        logoError.value = t('setidx.logo_type_err');
        return;
    }

    if (file.size > 3 * 1024 * 1024) {
        logoError.value = t('setidx.logo_size_err');
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
        logoError.value = err.response?.data?.message || t('setidx.logo_upload_err');
    } finally {
        isUploadingLogo.value = false;
        if (fileInputRef.value) fileInputRef.value.value = '';
    }
};

const removeLogo = async () => {
    if (!confirm(t('setidx.logo_del_q'))) return;

    isUploadingLogo.value = true;
    try {
        const response = await axios.delete(route('settings.logo.delete'));
        if (response.data.success) {
            currentLogo.value = null;
            router.reload({ only: ['company'] });
        }
    } catch (err) {
        logoError.value = t('setidx.logo_del_err');
    } finally {
        isUploadingLogo.value = false;
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$feature('zatca') ? $t('setidx.company_and_zatca_settings') : $t('setidx.company_settings')" />

        <div class="space-y-5 w-full pb-12">
            <!-- Top Breadcrumbs & Action Bar -->
            <PageHeader :title="$t('setidx.settings_and_invoicing')" :crumbs="[{ label: $t('setidx.settings_and_invoicing') }]">
<template #actions>
<button 
                        @click="router.reload()" 
                        :title="$t('setidx.update_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button 
                        @click="saveSettings"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150 disabled:opacity-50"
                    >
                        <CheckBadgeIcon class="w-4 h-4 stroke-2" />
                        <span>{{ form.processing ? $t('setidx.saving') : $t('setidx.save_all_changes') }}</span>
                    </button>
</template>
</PageHeader>

            <LocalAccessCard v-if="localAccess" :urls="localAccess" />

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
                        <span>{{ $t('setidx.company_details') }}</span>
                    </button>

                    <button 
                        v-if="$feature('zatca')"
                        type="button"
                        @click="activeTab = 'zatca'"
                        :class="activeTab === 'zatca' ? 'bg-emerald-50 text-emerald-800 font-bold border border-emerald-200' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition relative"
                    >
                        <ShieldCheckIcon class="w-4 h-4 text-emerald-600" />
                        <span>{{ $t('setidx.invoicing_and_zatca') }}</span>
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
                        <span>{{ $t('setidx.invoicing_and_tax') }}</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'accounting'"
                        :class="activeTab === 'accounting' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <ScaleIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('setidx.accounting_mapping') }}</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'system'"
                        :class="activeTab === 'system' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <GlobeAltIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('setidx.currency_and_language') }}</span>
                    </button>

                    <button 
                        type="button"
                        @click="activeTab = 'pos'"
                        :class="activeTab === 'pos' ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50 font-semibold'"
                        class="w-full text-right px-3.5 py-2.5 rounded-xl text-xs flex items-center gap-2.5 transition"
                    >
                        <DeviceTabletIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('setidx.point_of_sale_pos') }}</span>
                    </button>
                </div>

                <!-- Settings Content Form -->
                <div class="lg:col-span-3 xl:col-span-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 sm:p-8">
                    <form @submit.prevent="saveSettings">
                        <!-- 1. General Profile Tab -->
                        <div v-show="activeTab === 'general'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                {{ $t('setidx.company_identity_and_official_da') }}
                            </h2>

                            <!-- Logo Upload Card -->
                            <div class="mb-5 p-4 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/60 hover:bg-slate-50 transition">
                                <label class="block text-xs font-bold text-slate-800 mb-2">{{ $t('setidx.company_logo') }}</label>
                                
                                <div class="flex flex-col sm:flex-row items-center gap-4">
                                    <!-- Logo Preview -->
                                    <div class="relative group w-24 h-24 sm:w-28 sm:h-28 rounded-xl bg-white border border-slate-200 flex items-center justify-center overflow-hidden shadow-2xs flex-shrink-0">
                                        <img 
                                            v-if="currentLogo" 
                                            :src="currentLogo" 
                                            :alt="$t('setidx.logo_alt')" 
                                            class="w-full h-full object-contain p-2"
                                        />
                                        <div v-else class="text-center p-2 text-slate-400">
                                            <PhotoIcon class="w-8 h-8 mx-auto stroke-1 text-slate-300" />
                                            <span class="text-3xs font-medium block mt-1">{{ $t('setidx.no_logo') }}</span>
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
                                                <span>{{ currentLogo ? $t('setidx.change_logo') : $t('setidx.upload_company_logo') }}</span>
                                            </button>

                                            <button 
                                                v-if="currentLogo"
                                                type="button" 
                                                @click="removeLogo"
                                                :disabled="isUploadingLogo"
                                                class="px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition flex items-center gap-1"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                                <span>{{ $t('setidx.delete') }}</span>
                                            </button>
                                        </div>

                                        <p class="text-3xs text-slate-400 leading-relaxed">
                                            {{ $t('setidx.supported_formats_png_jpg_webp_g') }}<br>
                                            {{ $t('setidx.the_logo_appears_automatically_i') }}
                                        </p>

                                        <p v-if="logoError" class="text-3xs text-rose-600 font-bold">
                                            {{ logoError }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.company_establishment_name') }}</label>
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
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $page.props.tax?.tax_number_label || $t('setidx.tax_number') }} *</label>
                                    <input 
                                        v-model="form.tax_number" 
                                        type="text" 
                                        :placeholder="$page.props.tax?.tax_number_example || ''"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                    <p v-if="$feature('zatca')" class="text-2xs text-slate-400 mt-1">{{ $t('setidx.the_15_digit_number_registered_w') }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.commercial_register_number_cr') }}</label>
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
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.official_email') }}</label>
                                    <input 
                                        v-model="form.email" 
                                        type="email" 
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                        dir="ltr"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.phone_or_mobile_number') }}</label>
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
                        <div v-if="$feature('zatca')" v-show="activeTab === 'zatca'" class="space-y-6">
                            <div class="border-b border-slate-100 pb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div>
                                    <h2 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                        <ShieldCheckIcon class="w-5 h-5 text-emerald-600" />
                                        <span>{{ $t('setidx.e_invoicing_system_zatca_fatoora') }}</span>
                                    </h2>
                                    <p class="text-2xs text-slate-500 mt-0.5">{{ $t('setidx.choose_the_tax_compliance_phase') }}</p>
                                </div>
                                <span 
                                    class="text-xs font-bold px-3 py-1 rounded-full border self-start"
                                    :class="form.zatca_phase === 'phase2' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'"
                                >
                                    {{ form.zatca_phase === 'phase2' ? $t('setidx.phase_2_linking_and_integrat') : $t('setidx.phase_1_issue_and_store_2') }}
                                </span>
                            </div>

                            <!-- Phase Selection Interactive Cards -->
                            <div class="space-y-3">
                                <label class="block text-xs font-black text-slate-800">{{ $t('setidx.choose_the_phase_applied_in_your') }}</label>
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
                                                <h3 class="text-xs font-black text-slate-800">{{ $t('setidx.phase_1_issue_and_store') }}</h3>
                                            </div>
                                            <span class="text-3xs font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">{{ $t('setidx.offline') }}</span>
                                        </div>

                                        <p class="text-2xs text-slate-500 mt-2 leading-relaxed">
                                            {{ $t('setidx.issue_invoices_electronically_an') }}
                                        </p>

                                        <ul class="text-2xs text-slate-600 mt-3 space-y-1 font-medium">
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" />
                                                <span>{{ $t('setidx.five_field_qr_code_compatible_wi') }}</span>
                                            </li>
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" />
                                                <span>{{ $t('setidx.ready_and_working_at_once_for_al') }}</span>
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
                                                <h3 class="text-xs font-black text-slate-800">{{ $t('setidx.phase_2_linking_and_integration') }}</h3>
                                            </div>
                                            <span class="text-3xs font-bold px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">{{ $t('setidx.cloud_api_link') }}</span>
                                        </div>

                                        <p class="text-2xs text-slate-500 mt-2 leading-relaxed">
                                            {{ $t('setidx.direct_link_with_the_fatoora_pla') }}
                                        </p>

                                        <ul class="text-2xs text-slate-600 mt-3 space-y-1 font-medium">
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" />
                                                <span>{{ $t('setidx.build_ubl_2_1_xml_files_and_the') }}</span>
                                            </li>
                                            <li class="flex items-center gap-1.5">
                                                <CheckCircleIcon class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" />
                                                <span>{{ $t('setidx.invoice_clearance_and_reporting') }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div v-show="form.zatca_phase === 'phase2'" class="bg-indigo-50/60 border border-indigo-200/80 rounded-2xl p-5 text-xs space-y-2">
                                <h4 class="font-black text-indigo-900">{{ $t('setidx.linking_and_integration_phase_2') }}</h4>
                                <p class="text-indigo-700">{{ $t('setidx.invoicing_devices_egs_certificat') }}</p>
                                <Link :href="route('zatca.index')" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('setidx.open_the_e_invoicing_screen') }}</Link>
                            </div>
                        </div>

                        <!-- 3. Invoicing & Tax Tab -->
                        <div v-show="activeTab === 'invoicing'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                {{ $t('setidx.e_invoice_and_tax_settings') }}
                            </h2>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.vat_rate') }}</label>
                                    <input 
                                        :value="15"
                                        type="text" inputmode="decimal" data-num 
                                        readonly
                                        disabled
                                        class="w-full text-xs rounded-xl border-slate-200 bg-slate-50 font-mono font-bold"
                                    />
                                    <p class="text-3xs text-slate-500 mt-1">{{ $t('setidx.the_statutory_rate_in_the_kingdo') }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.invoice_and_document_numbering') }}</label>
                                    <Link v-if="$canRoute('settings.numbering')" :href="route('settings.numbering')" class="inline-flex items-center px-3 py-2 rounded-xl border border-indigo-200 text-indigo-700 text-xs font-bold hover:bg-indigo-50" data-numbering-link>{{ $t('setidx.numbering_guide_prefixes_and_dig') }}</Link>
                                    <p v-else class="text-3xs text-slate-500">{{ $t('setidx.numbering_prefixes_are_set_in_th') }}</p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.default_invoice_terms_and_condit') }}</label>
                                <textarea 
                                    v-model="form.invoice_terms" 
                                    rows="4"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 leading-relaxed"
                                    :placeholder="$t('setidx.these_terms_appear_at_the_bottom')"
                                ></textarea>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.message_text_for_sending_documen') }}</label>
                                <textarea
                                    v-model="form.document_message"
                                    rows="5"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 leading-relaxed"
                                    :placeholder="$t('setidx.leave_empty_to_use_the_default_t')"
                                ></textarea>
                                <p class="text-2xs text-slate-400 mt-1">{{ $t('setidx.variables_available') }} {customer} {document} {number} {total} {currency} {company}</p>
                            </div>

                            <label class="flex items-center gap-2 text-xs font-bold text-slate-700">
                                <input v-model="form.show_hijri" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                {{ $t('setidx.show_the_hijri_date_umm_al_qura') }}
                            </label>
                        </div>

                        <!-- Accounting Tab (الربط والتوجيه المحاسبي) -->
                        <div v-show="activeTab === 'accounting'" class="space-y-6">
                            <div class="border-b border-slate-100 pb-3 mb-2 flex items-center justify-between">
                                <div>
                                    <h2 class="text-sm font-black text-slate-900">
                                        {{ $t('setidx.chart_of_accounts_mapping') }}
                                    </h2>
                                    <p class="text-2xs text-slate-500 mt-1">
                                        {{ $t('setidx.choose_the_default_accounts_in_t') }}
                                    </p>
                                </div>
                                <span class="px-2.5 py-1 bg-indigo-50 text-indigo-700 text-3xs font-black rounded-lg border border-indigo-100">
                                    {{ $t('setidx.custom_chart_of_accounts') }}
                                </span>
                            </div>

                            <div class="bg-amber-50/70 border border-amber-200/80 rounded-xl p-3.5 flex items-start gap-2.5 text-xs text-amber-900">
                                <InformationCircleIcon class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                                <div class="text-[11px] leading-relaxed">
                                    <strong class="font-bold block text-amber-950 mb-0.5">{{ $t('setidx.how_does_the_system_recognise_ac') }}</strong>
                                    {{ $t('setidx.the_system_links_operational_tra') }}
                                </div>
                            </div>

                            <!-- 1. المبيعات والعملاء -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span>{{ $t('setidx.sales_and_receivables_accounts') }}</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.sales_revenue_account_default_cr') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_sales_revenue_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_sales_account_from_th')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.credited_as_revenue_when_any_sal') }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.receivables_customers_account_de') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_receivables_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_customers_receivables')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.debited_for_credit_invoices_or_r') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. المشتريات والموردين -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                    <span>{{ $t('setidx.purchases_and_payables_accounts') }}</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.purchases_cost_of_goods_sold_acc') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_purchases_cogs_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_purchases_cost_accoun')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.recorded_as_an_expense_or_purcha') }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.payables_suppliers_account') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_payables_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_suppliers_payables_ac')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.credited_as_a_financial_liabilit') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. ضريبة القيمة المضافة -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                                    <span>{{ $t('setidx.vat_accounts') }}</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.output_vat_collected_sales') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_vat_output_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_output_vat_account')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.a_liability_account_owed_to_the') }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.input_vat_purchases') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_vat_input_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_input_vat_account')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.a_current_asset_account_where_re') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. النقدية والبنوك الافتراضية -->
                            <div class="p-4 rounded-xl border border-slate-200/80 bg-slate-50/50 space-y-4">
                                <h3 class="text-xs font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span>{{ $t('setidx.default_cash_and_banks') }}</span>
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.default_main_cash_box') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_default_cash_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_main_cash_box_account')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.used_to_record_quick_cash_receip') }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            {{ $t('setidx.default_main_bank_account') }}
                                        </label>
                                        <SelectCreate v-model="form.acc_default_bank_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar} (${acc.type})`" :placeholder="$t('setidx.choose_the_default_bank_account')" />
                                        <p class="text-[10px] text-slate-400 mt-1">{{ $t('setidx.used_automatically_for_bank_tran') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. System & Localization Tab -->
                        <div v-show="activeTab === 'system'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                {{ $t('setidx.currency_region_and_language') }}
                            </h2>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="sm:col-span-3 lg:col-span-1">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-bold text-slate-700">{{ $t('setidx.base_currency') }}</label>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                            <LockClosedIcon class="w-3 h-3 text-slate-500" />
                                            <span>{{ $t('setidx.locked_for_accounting') }}</span>
                                        </span>
                                    </div>
                                    <select 
                                        v-model="form.currency"
                                        disabled
                                        class="w-full text-xs rounded-xl border-slate-200 bg-slate-100/90 text-slate-600 cursor-not-allowed font-bold"
                                    >
                                        <option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.name }} ({{ c.code }} - {{ c.symbol }})</option>
                                    </select>
                                    <p class="text-[11px] text-amber-800 bg-amber-50/70 border border-amber-200/60 rounded-xl p-2.5 mt-2 flex items-start gap-1.5 leading-relaxed font-medium">
                                        <LockClosedIcon class="w-3.5 h-3.5 text-amber-600 mt-0.5 flex-shrink-0" />
                                        <span>{{ $t('setidx.the_base_currency_is_locked_to_k') }}</span>
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.company_country') }}</label>
                                    <select v-model="form.country_code" :disabled="countryLocked" class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 disabled:bg-slate-100" data-company-country>
                                        <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                                    </select>
                                    <p class="text-[11px] text-slate-500 mt-1">{{ countryLocked ? $t('setidx.cannot_change_after_invoices') : $t('setidx.sets_the_company_tax_and_its') }}</p>
                                    <p v-if="form.errors.country_code" class="text-[11px] text-rose-600 mt-1">{{ form.errors.country_code }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.time_zone') }}</label>
                                    <select 
                                        v-model="form.timezone"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                    >
                                        <option v-for="tz in [...new Set(countries.map((c) => c.timezone))]" :key="tz" :value="tz">{{ tz }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('setidx.default_system_language') }}</label>
                                    <select 
                                        v-model="form.language"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                                    >
                                        <option value="ar">{{ $t('setidx.arabic') }}</option>
                                        <option value="en">{{ $t('setidx.english') }}</option>
                                    </select>
                                </div>
                            </div>
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-2 mt-6">
                                {{ $t('setidx.security_mandatory_two_factor_au') }}
                            </h2>
                            <p class="text-xs text-slate-500">{{ $t('setidx.users_in_the_chosen_roles_must_e') }}</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
                                <label class="flex items-center gap-2 font-bold text-indigo-700">
                                    <input v-model="form.two_factor_required_roles" type="checkbox" value="*" class="rounded border-slate-300 text-indigo-600" />
                                    {{ $t('setidx.all_users') }}
                                </label>
                                <label v-for="r in roles" :key="r.name" class="flex items-center gap-2" :class="form.two_factor_required_roles.includes('*') ? 'opacity-50' : ''">
                                    <input v-model="form.two_factor_required_roles" type="checkbox" :value="r.name" :disabled="form.two_factor_required_roles.includes('*')" class="rounded border-slate-300 text-indigo-600" />
                                    {{ r.label }}
                                </label>
                            </div>
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-2 mt-6">{{ $t('setidx.security_automatic_sign_out') }}</h2>
                            <div class="flex flex-wrap items-center gap-3 text-xs">
                                <label class="font-bold text-slate-700">{{ $t('setidx.inactivity_timeout_before_sign_o') }}</label>
                                <input v-model="form.session_timeout_minutes" type="text" inputmode="decimal" data-num class="w-24 rounded-xl border-slate-200 text-xs font-mono" data-session-timeout />
                                <span class="text-slate-500">{{ $t('setidx.a_countdown_warning_appears_one') }}</span>
                            </div>
                        </div>


                        <!-- 5. POS Tab -->
                        <div v-show="activeTab === 'pos'" class="space-y-4">
                            <h2 class="text-sm font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">
                                {{ $t('setidx.point_of_sale_settings_pos') }}
                            </h2>

                            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/60">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input 
                                        v-model="form.pos_auto_print" 
                                        type="checkbox" 
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-600 w-4 h-4"
                                    />
                                    <div>
                                        <span class="text-xs font-bold text-slate-800">{{ $t('setidx.print_the_receipt_automatically') }}</span>
                                        <p class="text-[11px] text-slate-500">{{ $t('setidx.sends_the_simplified_receipt_str') }}</p>
                                    </div>
                                </label>
                            </div>

                            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/60">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="form.pos_allow_credit" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-600 w-4 h-4" data-pos-allow-credit />
                                    <div>
                                        <span class="text-xs font-bold text-slate-800">{{ $t('setidx.allow_credit_sales_at_the_cashie') }}</span>
                                        <p class="text-[11px] text-slate-500">{{ $t('setidx.a_credit_sale_button_appears_for') }}</p>
                                    </div>
                                </label>
                            </div>

                            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/60">
                                <label class="block text-xs font-bold text-slate-800 mb-1">{{ $t('setidx.maximum_cashier_discount') }}</label>
                                <input v-model="form.pos_max_discount_percent" type="text" inputmode="decimal" data-num class="w-28 rounded-xl border-slate-200 text-xs font-mono" data-pos-max-discount />
                                <p class="text-[11px] text-slate-500 mt-1">{{ $t('setidx.covers_line_discount_order_disco') }}</p>
                            </div>
                        </div>

                        <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200/60" data-budget-enforcement-box>
                            <label class="block text-xs font-bold text-slate-800 mb-1">{{ $t('setidx.budget_enforcement') }}</label>
                            <select v-model="form.budget_enforcement" class="rounded-xl border-slate-200 text-xs ps-3 pe-8" data-budget-enforcement>
                                <option value="off">{{ $t('setidx.off_for_information_only') }}</option>
                                <option value="warn">{{ $t('setidx.warn_when_the_budget_is_exceeded') }}</option>
                                <option value="block">{{ $t('setidx.block_overruns_except_for_those') }}</option>
                            </select>
                            <p class="text-[11px] text-slate-500 mt-1">{{ $t('setidx.compares_the_expense_purchase_in') }}</p>
                        </div>

                        <!-- Submit Footer -->
                        <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-end">
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-2 disabled:opacity-50"
                            >
                                <CheckCircleIcon class="w-4 h-4" />
                                <span>{{ $t('setidx.save_all_settings') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
