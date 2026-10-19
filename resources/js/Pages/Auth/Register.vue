<script setup>
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { 
    BuildingOfficeIcon, 
    GlobeAltIcon, 
    UserIcon, 
    EnvelopeIcon, 
    PhoneIcon, 
    LockClosedIcon,
    SparklesIcon,
    BanknotesIcon
} from '@heroicons/vue/24/outline';

const form = useForm({
    company_name: '',
    subdomain: '',
    currency: 'SAR',
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

// Auto-generate subdomain from company name (transliterated / cleaned)
const updateSubdomainSuggestion = () => {
    if (!form.subdomain && form.company_name) {
        form.subdomain = form.company_name
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '')
            .substring(0, 20);
    }
};

const fullDomainPreview = computed(() => {
    const sub = form.subdomain ? form.subdomain.toLowerCase() : 'your-company';
    return `${sub}.${usePage().props.brand?.domain}`;
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="تسجيل منشأة جديدة" />

        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900 tracking-tight">تسجيل منشأة جديدة</h2>
            <p class="text-xs text-slate-500 mt-1">ابدأ تجربتك المجانية لمدة 14 يوماً مع {{ $page.props.brand?.name }}</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Company Name -->
            <div>
                <label for="company_name" class="block text-xs font-bold text-slate-700 mb-1">
                    اسم المنشأة أو المؤسسة *
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                        <BuildingOfficeIcon class="w-4 h-4" />
                    </div>
                    <input
                        id="company_name"
                        type="text"
                        v-model="form.company_name"
                        @blur="updateSubdomainSuggestion"
                        required
                        autofocus
                        placeholder="مثال: مؤسسة الأفق للتجارة"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all font-sans"
                    />
                </div>
                <InputError class="mt-1.5" :message="form.errors.company_name" />
            </div>

            <!-- Subdomain -->
            <div>
                <label for="subdomain" class="block text-xs font-bold text-slate-700 mb-1">
                    رابط النظام المخصص (Subdomain) *
                </label>
                <div class="relative flex rounded-xl shadow-xs">
                    <span class="inline-flex items-center px-3 rounded-s-xl border border-e-0 border-slate-200 bg-slate-100 text-slate-500 text-xs font-mono select-none" dir="ltr">
                        .{{ $page.props.brand?.domain }}
                    </span>
                    <input
                        id="subdomain"
                        type="text"
                        v-model="form.subdomain"
                        required
                        dir="ltr"
                        placeholder="mycompany"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-e-xl px-3 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all font-mono"
                    />
                </div>
                <div class="mt-1 flex items-center gap-1 text-[11px] text-slate-500">
                    <GlobeAltIcon class="w-3.5 h-3.5 text-indigo-600" />
                    <span>سيكون رابط دخولك: <strong class="text-indigo-600" dir="ltr">{{ fullDomainPreview }}</strong></span>
                </div>
                <InputError class="mt-1.5" :message="form.errors.subdomain" />
            </div>

            <!-- Base Currency Selection -->
            <div>
                <label for="currency" class="block text-xs font-bold text-slate-700 mb-1">
                    العملة الأساسية للنظام والحسابات *
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                        <BanknotesIcon class="w-4 h-4" />
                    </div>
                    <select
                        id="currency"
                        v-model="form.currency"
                        required
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all font-sans font-bold"
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
                </div>
                <div class="mt-1.5 p-2 bg-amber-50/70 border border-amber-200/60 rounded-lg flex items-start gap-1.5 text-[11px] text-amber-800 font-medium">
                    <LockClosedIcon class="w-3.5 h-3.5 text-amber-600 mt-0.5 flex-shrink-0" />
                    <span>تنبيه هام: يتم قفل العملة الأساسية نهائياً بعد إنشاء المنشأة ولا يمكن تغييرها لاحقاً لضمان سلامة القيود المحاسبية والفواتير.</span>
                </div>
                <InputError class="mt-1.5" :message="form.errors.currency" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Admin Name -->
                <div>
                    <label for="name" class="block text-xs font-bold text-slate-700 mb-1">
                        اسم المسؤول *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                            <UserIcon class="w-4 h-4" />
                        </div>
                        <input
                            id="name"
                            type="text"
                            v-model="form.name"
                            required
                            placeholder="الاسم الكامل"
                            class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                        />
                    </div>
                    <InputError class="mt-1.5" :message="form.errors.name" />
                </div>

                <!-- Phone -->
                <div>
                    <label for="phone" class="block text-xs font-bold text-slate-700 mb-1">
                        رقم الجوال *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                            <PhoneIcon class="w-4 h-4" />
                        </div>
                        <input
                            id="phone"
                            type="tel"
                            v-model="form.phone"
                            required
                            dir="ltr"
                            placeholder="05xxxxxxxx"
                            class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                        />
                    </div>
                    <InputError class="mt-1.5" :message="form.errors.phone" />
                </div>
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-xs font-bold text-slate-700 mb-1">
                    البريد الإلكتروني للادارة *
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                        <EnvelopeIcon class="w-4 h-4" />
                    </div>
                    <input
                        id="email"
                        type="email"
                        v-model="form.email"
                        required
                        dir="ltr"
                        placeholder="admin@yourcompany.com"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    />
                </div>
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <!-- Password & Confirmation -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="block text-xs font-bold text-slate-700 mb-1">
                        كلمة المرور *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                            <LockClosedIcon class="w-4 h-4" />
                        </div>
                        <input
                            id="password"
                            type="password"
                            v-model="form.password"
                            required
                            dir="ltr"
                            placeholder="••••••••"
                            class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                        />
                    </div>
                    <InputError class="mt-1.5" :message="form.errors.password" />
                </div>

                <div>
                    <label for="password_confirmation" class="block text-xs font-bold text-slate-700 mb-1">
                        تأكيد كلمة المرور *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                            <LockClosedIcon class="w-4 h-4" />
                        </div>
                        <input
                            id="password_confirmation"
                            type="password"
                            v-model="form.password_confirmation"
                            required
                            dir="ltr"
                            placeholder="••••••••"
                            class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                        />
                    </div>
                    <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <!-- Consent (PDPL) -->
            <div>
                <label class="flex items-start gap-2 text-xs text-slate-600">
                    <input v-model="form.terms" type="checkbox" class="mt-0.5 rounded border-slate-300 text-indigo-600" />
                    <span>أوافق على <a :href="route('legal.show', 'terms')" target="_blank" class="text-indigo-600 underline">الشروط والأحكام</a> و<a :href="route('legal.show', 'privacy')" target="_blank" class="text-indigo-600 underline">سياسة الخصوصية وحماية البيانات</a>.</span>
                </label>
                <InputError class="mt-1.5" :message="form.errors.terms" />
            </div>

            <!-- Submit Button -->
            <div class="pt-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold text-xs sm:text-sm py-3 px-4 rounded-xl shadow-md hover:shadow-lg transition duration-150 disabled:opacity-50"
                >
                    <SparklesIcon class="w-4 h-4" />
                    <span>{{ form.processing ? 'جاري إنشاء الحساب...' : 'إنشاء المنشأة والبدء مجاناً' }}</span>
                </button>
            </div>
        </form>

        <!-- Back to Login -->
        <div class="mt-6 pt-4 border-t border-slate-100 text-center">
            <span class="text-xs text-slate-500">لديك حساب بالفعل؟ </span>
            <Link :href="route('login')" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition">
                تسجيل الدخول
            </Link>
        </div>
    </AuthLayout>
</template>
