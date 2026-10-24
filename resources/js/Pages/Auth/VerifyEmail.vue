<script setup>
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { EnvelopeOpenIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ status: { type: String } });
const form = useForm({});
const submit = () => form.post(route('verification.send'));
const sent = computed(() => props.status === 'verification-link-sent');
const email = computed(() => usePage().props.auth?.user?.email);
</script>

<template>
    <AuthLayout>
        <Head title="تأكيد البريد الإلكتروني" />
        <div class="text-center mb-5">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3"><EnvelopeOpenIcon class="w-6 h-6" /></div>
            <h2 class="text-xl font-black text-slate-900">أكّد بريدك الإلكتروني</h2>
            <p class="text-xs text-slate-500 mt-2 leading-relaxed">
                أرسلنا رابط التأكيد إلى <span v-if="email" class="font-bold text-slate-700" dir="ltr">{{ email }}</span>.
                اضغط الرابط في الرسالة لتفعيل حسابك والبدء.
            </p>
        </div>

        <div v-if="sent" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
            أُرسل رابط تأكيد جديد إلى بريدك.
        </div>
        <p class="mb-5 p-3 rounded-xl bg-slate-50 text-[11px] text-slate-500 leading-relaxed">
            لم تصلك الرسالة؟ تحقق من مجلد البريد غير المرغوب (Spam)، ثم اطلب رسالة جديدة. إن لم تصل خلال دقائق فتواصل مع إدارة المنصة.
        </p>

        <form @submit.prevent="submit" class="space-y-3">
            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md transition disabled:opacity-50">{{ form.processing ? 'جارٍ الإرسال...' : 'إعادة إرسال رسالة التأكيد' }}</button>
            <Link :href="route('logout')" method="post" as="button" class="w-full text-center text-xs font-semibold text-slate-500 hover:text-slate-800">تسجيل الخروج</Link>
        </form>
    </AuthLayout>
</template>
