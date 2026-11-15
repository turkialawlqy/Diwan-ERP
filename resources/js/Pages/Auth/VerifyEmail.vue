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
        <Head :title="$t('x_auth_verifyemail.confirm_email')" />
        <div class="text-center mb-5">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3"><EnvelopeOpenIcon class="w-6 h-6" /></div>
            <h2 class="text-xl font-black text-slate-900">{{ $t('x_auth_verifyemail.confirm_your_email') }}</h2>
            <p class="text-xs text-slate-500 mt-2 leading-relaxed">
                {{ $t('x_auth_verifyemail.we_sent_the_confirmation_link_to') }} <span v-if="email" class="font-bold text-slate-700" dir="ltr">{{ email }}</span>{{ $t('x_auth_verifyemail.click_the_link_in_the_message_to') }}
            </p>
        </div>

        <div v-if="sent" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
            {{ $t('x_auth_verifyemail.a_new_confirmation_link_was_sent') }}
        </div>
        <p class="mb-5 p-3 rounded-xl bg-slate-50 text-[11px] text-slate-500 leading-relaxed">
            {{ $t('x_auth_verifyemail.did_the_message_not_arrive_check') }}
        </p>

        <form @submit.prevent="submit" class="space-y-3">
            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm py-2.5 px-4 rounded-xl shadow-md transition disabled:opacity-50">{{ form.processing ? $t('x_auth_verifyemail.sending') : $t('x_auth_verifyemail.resend_the_confirmation_message') }}</button>
            <Link :href="route('logout')" method="post" as="button" class="w-full text-center text-xs font-semibold text-slate-500 hover:text-slate-800">{{ $t('x_auth_verifyemail.sign_out') }}</Link>
        </form>
    </AuthLayout>
</template>
