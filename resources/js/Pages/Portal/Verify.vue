<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ company: Object, email: String });
const form = useForm({ code: '' });
const submit = () => form.post(route('customer-portal.verify.check', { company: props.company.id }), { onError: () => form.reset('code') });
</script>

<template>
    <Head :title="`رمز الدخول — ${company.name}`" />
    <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4" dir="rtl">
        <div class="bg-white border border-slate-200 rounded-2xl p-6 w-full max-w-sm space-y-4 shadow-sm">
            <div><h1 class="font-black text-lg text-slate-900">أدخل رمز الدخول</h1><p class="text-xs text-slate-500">أرسلنا رمزًا إلى <b dir="ltr">{{ email }}</b> إن كان مسجّلًا لدينا. صالح 10 دقائق.</p></div>
            <form @submit.prevent="submit" class="space-y-3 text-xs">
                <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required placeholder="000000" class="w-full text-center text-2xl tracking-[0.5em] font-mono rounded-xl border-slate-200" dir="ltr" data-portal-code />
                <p v-if="form.errors.code" class="text-rose-600 font-bold" data-portal-error>{{ form.errors.code }}</p>
                <button type="submit" :disabled="form.processing || form.code.length < 6" class="w-full py-2.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-portal-verify>دخول</button>
            </form>
            <Link :href="route('customer-portal.login', { company: company.id })" class="block text-center text-xs text-indigo-600 font-bold">استعمال بريد آخر أو طلب رمز جديد</Link>
        </div>
    </div>
</template>
