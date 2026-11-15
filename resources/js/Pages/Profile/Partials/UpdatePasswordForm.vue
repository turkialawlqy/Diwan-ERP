<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Field from '@/Components/UI/Field.vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <h2 class="font-black text-sm text-slate-800">{{ $t('x_profile_partials_updatepasswordform.change_password') }}</h2>
        <p class="mt-1 text-xs text-slate-500">{{ $t('x_profile_partials_updatepasswordform.use_a_long_unpredictable_passwor') }}</p>

        <form @submit.prevent="updatePassword" class="mt-4 space-y-3">
            <Field :label="$t('x_profile_partials_updatepasswordform.current_password')" :error="form.errors.current_password">
                <input id="update_current_password" ref="currentPasswordInput" v-model="form.current_password" type="password" autocomplete="current-password" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>
            <Field :label="$t('x_profile_partials_updatepasswordform.new_password')" :error="form.errors.password">
                <input id="password" ref="passwordInput" v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>
            <Field :label="$t('x_profile_partials_updatepasswordform.confirm_password')" :error="form.errors.password_confirmation">
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-xl border-slate-200 text-xs" />
            </Field>
            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('x_profile_partials_updatepasswordform.save') }}</button>
                <p v-if="form.recentlySuccessful" class="text-xs font-bold text-emerald-600">{{ $t('x_profile_partials_updatepasswordform.saved') }}</p>
            </div>
        </form>
    </section>
</template>
