<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({ status: { type: String } });
const form = useForm({ email: '' });
const submit = () => form.post(route('password.email'));
</script>

<template>
    <AuthLayout>
        <Head title="نسيت كلمة المرور" />
        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900">نسيت كلمة المرور؟</h2>
            <p class="text-xs text-slate-500 mt-1">أدخل بريدك وسنرسل لك رابطاً لتعيين كلمة مرور جديدة.</p>
        </div>
        <div v-if="status" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">{{ status }}</div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="email" class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" required autofocus autocomplete="username" dir="ltr" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all text-right" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>
            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md transition disabled:opacity-50">إرسال رابط التعيين</button>
            <Link :href="route('login')" class="block text-center text-xs font-semibold text-indigo-600 hover:text-indigo-800">العودة لتسجيل الدخول</Link>
        </form>
    </AuthLayout>
</template>
