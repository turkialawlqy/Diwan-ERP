<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Field from '@/Components/UI/Field.vue';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    current_password: '',
});

// Changing the login email asks for the current password.
const emailChanged = computed(() => (form.email || '').trim().toLowerCase() !== (user.email || '').toLowerCase());

const submit = () => form.patch(route('profile.update'), { preserveScroll: true, onSuccess: () => form.reset('current_password') });
</script>

<template>
    <section>
        <h2 class="font-black text-sm text-slate-800">بيانات الحساب</h2>
        <p class="mt-1 text-xs text-slate-500">حدّث اسمك والبريد الإلكتروني الذي تدخل به.</p>

        <form @submit.prevent="submit" class="mt-4 space-y-3">
            <Field label="الاسم" :error="form.errors.name">
                <input id="name" v-model="form.name" type="text" required autocomplete="name" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>
            <Field label="البريد الإلكتروني" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" required autocomplete="username" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs text-right" />
            </Field>
            <Field v-if="emailChanged" label="كلمة المرور الحالية (لتأكيد تغيير البريد)" :error="form.errors.current_password">
                <input id="current_password" v-model="form.current_password" type="password" required autocomplete="current-password" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="text-xs text-slate-700">
                بريدك الإلكتروني غير مؤكد.
                <Link :href="route('verification.send')" method="post" as="button" class="text-indigo-600 underline font-bold">أعد إرسال رسالة التأكيد</Link>
                <p v-show="status === 'verification-link-sent'" class="mt-1 font-bold text-emerald-600">أُرسل رابط تأكيد جديد إلى بريدك الإلكتروني.</p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">حفظ</button>
                <p v-if="form.recentlySuccessful" class="text-xs font-bold text-emerald-600">تم الحفظ.</p>
            </div>
        </form>
    </section>
</template>
