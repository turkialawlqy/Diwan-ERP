<script setup>
import { computed } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
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
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">بيانات الحساب</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">حدّث اسمك والبريد الإلكتروني الذي تدخل به.</p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <div>
                <InputLabel for="name" value="الاسم" />
                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="البريد الإلكتروني" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="emailChanged">
                <InputLabel for="current_password" value="كلمة المرور الحالية (لتأكيد تغيير البريد)" />
                <TextInput id="current_password" type="password" class="mt-1 block w-full" v-model="form.current_password" required autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">
                    بريدك الإلكتروني غير مؤكد.
                    <Link :href="route('verification.send')" method="post" as="button" class="rounded-md text-sm text-indigo-600 underline hover:text-indigo-800">
                        أعد إرسال رسالة التأكيد
                    </Link>
                </p>
                <div v-show="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-green-600">
                    أُرسل رابط تأكيد جديد إلى بريدك الإلكتروني.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">حفظ</PrimaryButton>
                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">تم الحفظ.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
