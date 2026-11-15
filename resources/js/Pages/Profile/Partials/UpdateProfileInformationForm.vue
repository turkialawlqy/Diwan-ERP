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
        <h2 class="font-black text-sm text-slate-800">{{ $t('x_profile_partials_updateprofileinformationform.account_details') }}</h2>
        <p class="mt-1 text-xs text-slate-500">{{ $t('x_profile_partials_updateprofileinformationform.update_your_name_and_the_email_y') }}</p>

        <form @submit.prevent="submit" class="mt-4 space-y-3">
            <Field :label="$t('x_profile_partials_updateprofileinformationform.name')" :error="form.errors.name">
                <input id="name" v-model="form.name" type="text" required autocomplete="name" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>
            <Field :label="$t('x_profile_partials_updateprofileinformationform.email')" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" required autocomplete="username" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs text-right" />
            </Field>
            <Field v-if="emailChanged" :label="$t('x_profile_partials_updateprofileinformationform.current_password_to_confirm_the')" :error="form.errors.current_password">
                <input id="current_password" v-model="form.current_password" type="password" required autocomplete="current-password" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="text-xs text-slate-700">
                {{ $t('x_profile_partials_updateprofileinformationform.your_email_is_not_confirmed') }}
                <Link :href="route('verification.send')" method="post" as="button" class="text-indigo-600 underline font-bold">{{ $t('x_profile_partials_updateprofileinformationform.resend_the_confirmation_message') }}</Link>
                <p v-show="status === 'verification-link-sent'" class="mt-1 font-bold text-emerald-600">{{ $t('x_profile_partials_updateprofileinformationform.a_new_confirmation_link_was_sent') }}</p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('x_profile_partials_updateprofileinformationform.save') }}</button>
                <p v-if="form.recentlySuccessful" class="text-xs font-bold text-emerald-600">{{ $t('x_profile_partials_updateprofileinformationform.saved') }}</p>
            </div>
        </form>
    </section>
</template>
