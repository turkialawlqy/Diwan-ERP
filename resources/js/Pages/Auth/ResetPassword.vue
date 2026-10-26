<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ email: { type: String, required: true }, token: { type: String, required: true } });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const submit = () => form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <AuthLayout>
        <Head title="تعيين كلمة مرور جديدة" />
        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900">تعيين كلمة مرور جديدة</h2>
            <p class="text-xs text-slate-500 mt-1">اختر كلمة مرور قوية لا تستخدمها في مواقع أخرى.</p>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="email" class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" required autocomplete="username" dir="ltr" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all text-right" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>
            <div>
                <label for="password" class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور الجديدة</label>
                <input id="password" v-model="form.password" type="password" required autofocus autocomplete="new-password" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all" />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>
            <div>
                <label for="password_confirmation" class="block text-xs font-bold text-slate-700 mb-1">تأكيد كلمة المرور</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all" />
                <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
            </div>
            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md transition disabled:opacity-50">حفظ كلمة المرور</button>
        </form>
    </AuthLayout>
</template>
