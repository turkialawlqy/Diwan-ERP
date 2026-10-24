<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });
const submit = () => form.post(route('password.confirm'), { onFinish: () => form.reset() });
</script>

<template>
    <AuthLayout>
        <Head title="تأكيد كلمة المرور" />
        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900">تأكيد كلمة المرور</h2>
            <p class="text-xs text-slate-500 mt-1">هذه منطقة محمية، أدخل كلمة المرور للمتابعة.</p>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="password" class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور</label>
                <input id="password" v-model="form.password" type="password" required autofocus autocomplete="current-password" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all" />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>
            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md transition disabled:opacity-50">متابعة</button>
        </form>
    </AuthLayout>
</template>
