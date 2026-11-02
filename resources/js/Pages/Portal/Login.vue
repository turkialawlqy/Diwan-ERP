<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ company: Object });
const form = useForm({ email: '' });
const submit = () => form.post(route('customer-portal.send-code', { company: props.company.id }));
</script>

<template>
    <Head :title="`دخول — ${company.name}`" />
    <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4" dir="rtl">
        <div class="bg-white border border-slate-200 rounded-2xl p-6 w-full max-w-sm space-y-4 shadow-sm">
            <div><h1 class="font-black text-lg text-slate-900">{{ company.name }}</h1><p class="text-xs text-slate-500">بوابة العملاء: فواتيرك وتذاكرك وعقودك</p></div>
            <form @submit.prevent="submit" class="space-y-3 text-xs">
                <label class="block"><span class="font-bold text-slate-700">بريدك الإلكتروني</span>
                    <input v-model="form.email" type="email" required autocomplete="email" class="mt-1 w-full rounded-xl border-slate-200 text-sm" dir="ltr" data-portal-email /></label>
                <p v-if="form.errors.email" class="text-rose-600 font-bold">{{ form.errors.email }}</p>
                <button type="submit" :disabled="form.processing" class="w-full py-2.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-portal-send>إرسال رمز الدخول</button>
                <p class="text-slate-400">يصلك رمز من 6 أرقام على بريدك، بلا كلمة مرور.</p>
            </form>
        </div>
    </div>
</template>
