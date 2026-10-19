<script setup>
import { ref } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { 
    EnvelopeIcon, 
    LockClosedIcon, 
    EyeIcon, 
    EyeSlashIcon,
    ArrowRightEndOnRectangleIcon
} from '@heroicons/vue/24/outline';

defineProps({
    canResetPassword: {
        type: Boolean,
        default: true,
    },
    status: {
        type: String,
    },
    // Only sent by the server when DEMO_LOGIN=true (never in production).
    demoAccounts: {
        type: Array,
        default: () => [],
    },
});

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const fillDemo = (account) => {
    form.email = account.email;
    form.password = account.password;
};
</script>

<template>
    <AuthLayout>
        <Head title="تسجيل الدخول" />

        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900 tracking-tight">تسجيل الدخول</h2>
            <p class="text-xs text-slate-500 mt-1">أدخل بريدك الإلكتروني وكلمة المرور للمتابعة إلى حسابك</p>
        </div>

        <div v-if="status" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
            {{ status }}
        </div>

        <!-- Demo accounts: only when the server enables DEMO_LOGIN -->
        <div v-if="demoAccounts.length" class="mb-6 p-3 rounded-2xl bg-indigo-50/60 border border-indigo-100 flex items-center justify-between gap-2 text-xs">
            <span class="text-slate-500 text-[11px] font-medium">حسابات تجريبية سريعة:</span>
            <div class="flex gap-2">
                <button
                    v-for="account in demoAccounts"
                    :key="account.email"
                    type="button"
                    @click="fillDemo(account)"
                    class="px-2.5 py-1 bg-white hover:bg-indigo-600 hover:text-white border border-indigo-200 rounded-lg font-bold text-[11px] text-indigo-700 transition shadow-xs"
                >
                    {{ account.label }}
                </button>
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Email Field -->
            <div>
                <label for="email" class="block text-xs font-bold text-slate-700 mb-1">
                    البريد الإلكتروني
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
                        autofocus
                        autocomplete="username"
                        :placeholder="`example@${$page.props.brand?.domain || 'company.sa'}`"
                        dir="ltr"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all font-sans"
                    />
                </div>
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <!-- Password Field -->
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="password" class="block text-xs font-bold text-slate-700">
                        كلمة المرور
                    </label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-[11px] text-indigo-600 hover:text-indigo-800 font-semibold transition"
                    >
                        نسيت كلمة المرور؟
                    </Link>
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                        <LockClosedIcon class="w-4 h-4" />
                    </div>
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                        dir="ltr"
                        class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl ps-9 pe-10 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    />
                    <button
                        type="button"
                        @click="showPassword = !showPassword"
                        class="absolute inset-y-0 end-0 pe-3 flex items-center text-slate-400 hover:text-slate-600 transition"
                    >
                        <EyeSlashIcon v-if="showPassword" class="w-4 h-4" />
                        <EyeIcon v-else class="w-4 h-4" />
                    </button>
                </div>
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <!-- Remember Me -->
            <div class="flex items-center">
                <input
                    id="remember"
                    type="checkbox"
                    v-model="form.remember"
                    class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500"
                />
                <label for="remember" class="ms-2 text-xs font-medium text-slate-600 select-none">
                    تذكر بيانات الدخول
                </label>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md hover:shadow-lg transition duration-150 disabled:opacity-50"
                >
                    <ArrowRightEndOnRectangleIcon class="w-4 h-4" />
                    <span>{{ form.processing ? 'جاري التحقق...' : 'تسجيل الدخول' }}</span>
                </button>
            </div>
        </form>

        <!-- Google OAuth Login -->
        <div class="mt-6">
            <div class="relative flex py-2 items-center">
                <div class="flex-grow border-t border-slate-200"></div>
                <span class="flex-shrink mx-4 text-xs font-medium text-slate-400">أو</span>
                <div class="flex-grow border-t border-slate-200"></div>
            </div>

            <a
                :href="route('auth.google')"
                class="mt-2 w-full flex items-center justify-center gap-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-semibold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-xs transition"
            >
                <!-- Google SVG Icon -->
                <svg class="w-4 h-4" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" />
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" />
                </svg>
                <span>تسجيل الدخول بواسطة Google</span>
            </a>
        </div>

        <!-- Register Company Link -->
        <div class="mt-6 pt-4 border-t border-slate-100 text-center">
            <span class="text-xs text-slate-500">ليس لديك حساب منشأة؟ </span>
            <Link :href="route('register')" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition">
                سجل منشأتك الآن واحصل على تجربة مجانية
            </Link>
            <div class="mt-3 text-3xs text-slate-400">
                <a :href="route('legal.show', 'terms')" class="hover:text-indigo-600">الشروط والأحكام</a> ·
                <a :href="route('legal.show', 'privacy')" class="hover:text-indigo-600">سياسة الخصوصية</a>
            </div>
        </div>
    </AuthLayout>
</template>
