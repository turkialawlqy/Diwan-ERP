<script setup>
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import axios from 'axios';
import QRCode from 'qrcode';
import { router } from '@inertiajs/vue3';
const { t } = useI18n();

/**
 * Enrol / manage two-factor authentication with an authenticator app.
 */
const props = defineProps({ state: { type: Object, required: true } });

const step = ref(props.state.enabled ? 'enabled' : 'off'); // off | setup | codes | enabled
const password = ref('');
const code = ref('');
const qr = ref('');
const secret = ref('');
const recoveryCodes = ref([]);
const error = ref('');
const busy = ref(false);

const call = async (fn) => {
    busy.value = true;
    error.value = '';
    try {
        await fn();
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = errors ? Object.values(errors)[0][0] : (e.response?.data?.message || t('x_profile_partials_twofactorform.an_error_occurred_try_again'));
    } finally {
        busy.value = false;
    }
};

const qrFailed = ref(false);
const start = () => call(async () => {
    const { data } = await axios.post(route('two-factor.enable'), { current_password: password.value });
    secret.value = data.secret;
    qrFailed.value = false;
    try {
        qr.value = await QRCode.toDataURL(data.uri, { margin: 1, width: 240 });
    } catch {
        qr.value = '';
        qrFailed.value = true; // the key can still be typed into the app
    }
    password.value = '';
    step.value = 'setup';
});

const confirm = () => call(async () => {
    const { data } = await axios.post(route('two-factor.confirm'), { code: code.value });
    recoveryCodes.value = data.recovery_codes;
    code.value = '';
    step.value = 'codes';
});

const regenerate = () => call(async () => {
    const { data } = await axios.post(route('two-factor.recovery-codes'), { current_password: password.value });
    recoveryCodes.value = data.recovery_codes;
    password.value = '';
    step.value = 'codes';
});

const disable = () => call(async () => {
    await axios.delete(route('two-factor.disable'), { data: { current_password: password.value } });
    password.value = '';
    step.value = 'off';
    router.reload({ only: ['twoFactor'] });
});

const finish = () => { recoveryCodes.value = []; step.value = 'enabled'; router.reload({ only: ['twoFactor'] }); };
const copyCodes = () => navigator.clipboard?.writeText(recoveryCodes.value.join('\n'));
const copied = ref(false);
const copySecret = () => { navigator.clipboard?.writeText(secret.value.replace(/\s+/g, '')); copied.value = true; setTimeout(() => { copied.value = false; }, 2000); };
const input = 'w-full max-w-xs rounded-xl border-slate-200 text-xs';
const primary = 'px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50';
const secondary = 'px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold disabled:opacity-50';
</script>

<template>
    <section>
        <div class="flex items-start justify-between gap-2">
            <div>
                <h2 class="font-black text-sm text-slate-800">{{ $t('x_profile_partials_twofactorform.two_factor_authentication_2fa') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $t('x_profile_partials_twofactorform.an_extra_layer_of_protection_aft') }}</p>
            </div>
            <span :class="step === 'enabled' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="shrink-0 px-2 py-1 rounded-lg text-3xs font-bold">{{ step === 'enabled' ? $t('x_profile_partials_twofactorform.enabled') : $t('x_profile_partials_twofactorform.not_enabled') }}</span>
        </div>

        <div class="mt-4 space-y-3 text-xs">
            <template v-if="step === 'off'">
                <ol class="space-y-1.5 text-slate-600" data-2fa-steps>
                    <li class="flex gap-2"><span class="w-5 h-5 shrink-0 rounded-full bg-indigo-600 text-white text-3xs font-black flex items-center justify-center">1</span> {{ $t('x_profile_partials_twofactorform.enter_your_current_password_and') }}</li>
                    <li class="flex gap-2"><span class="w-5 h-5 shrink-0 rounded-full bg-slate-200 text-slate-600 text-3xs font-black flex items-center justify-center">2</span> {{ $t('x_profile_partials_twofactorform.scan_the_code_with_the_authentic') }}</li>
                    <li class="flex gap-2"><span class="w-5 h-5 shrink-0 rounded-full bg-slate-200 text-slate-600 text-3xs font-black flex items-center justify-center">3</span> {{ $t('x_profile_partials_twofactorform.enter_the_6_digit_code_shown_in') }}</li>
                </ol>
                <label class="block font-bold text-slate-700" for="two_factor_password">{{ $t('x_profile_partials_twofactorform.current_password') }}</label>
                <input id="two_factor_password" v-model="password" type="password" autocomplete="current-password" :class="input" @keyup.enter="password && start()" />
                <button type="button" :disabled="busy || !password" @click="start" :class="primary" class="block" data-2fa-start>{{ busy ? $t('x_profile_partials_twofactorform.preparing') : $t('x_profile_partials_twofactorform.show_the_qr_code_and_start_activ') }}</button>
            </template>

            <template v-else-if="step === 'setup'">
                <p class="text-slate-600"><span class="font-bold">2.</span> {{ $t('x_profile_partials_twofactorform.scan_the_code_with_the_authentic_2') }}</p>
                <div class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-slate-200 bg-white">
                    <img v-if="qr" :src="qr" alt="رمز إعداد التحقق الثنائي" class="w-56 h-56" data-2fa-qr />
                    <p v-if="qrFailed" class="text-rose-600 font-bold">{{ $t('x_profile_partials_twofactorform.the_code_could_not_be_created_en') }}</p>
                    <p class="text-slate-500">{{ $t('x_profile_partials_twofactorform.or_enter_this_key_manually') }}</p>
                    <div class="flex items-center gap-2">
                        <code dir="ltr" class="rounded-lg bg-slate-100 px-2 py-1 font-mono text-slate-800">{{ secret }}</code>
                        <button type="button" @click="copySecret" class="text-indigo-600 font-bold">{{ copied ? $t('x_profile_partials_twofactorform.copied') : $t('x_profile_partials_twofactorform.copy_the_key') }}</button>
                    </div>
                </div>
                <label class="block text-slate-600" for="two_factor_code"><span class="font-bold">3.</span> {{ $t('x_profile_partials_twofactorform.enter_the_code_shown_in_the_app') }}</label>
                <input id="two_factor_code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" placeholder="123456" class="w-40 rounded-xl border-slate-200 text-center font-mono tracking-widest" @keyup.enter="code.length === 6 && confirm()" />
                <button type="button" :disabled="busy || code.length < 6" @click="confirm" :class="primary" class="block">{{ $t('x_profile_partials_twofactorform.confirm_activation') }}</button>
            </template>

            <template v-else-if="step === 'codes'">
                <p class="font-bold text-emerald-700">{{ $t('x_profile_partials_twofactorform.keep_the_recovery_codes_somewher') }}</p>
                <ul dir="ltr" class="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-3 font-mono" data-2fa-codes>
                    <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                </ul>
                <div class="flex gap-2">
                    <button type="button" @click="copyCodes" :class="secondary">{{ $t('x_profile_partials_twofactorform.copy_the_codes') }}</button>
                    <button type="button" @click="finish" :class="primary">{{ $t('x_profile_partials_twofactorform.i_saved_them_continue') }}</button>
                </div>
            </template>

            <template v-else>
                <p class="font-bold text-emerald-700">{{ $t('x_profile_partials_twofactorform.two_factor_authentication_is_on', { n0: state.recoveryRemaining }) }}</p>
                <label class="block font-bold text-slate-700" for="two_factor_manage_password">{{ $t('x_profile_partials_twofactorform.current_password_to_regenerate_t') }}</label>
                <input id="two_factor_manage_password" v-model="password" type="password" autocomplete="current-password" :class="input" />
                <div class="flex gap-2">
                    <button type="button" :disabled="busy || !password" @click="regenerate" :class="secondary">{{ $t('x_profile_partials_twofactorform.new_recovery_codes') }}</button>
                    <button v-if="!state.required" type="button" :disabled="busy || !password" @click="disable" :class="secondary" class="border-rose-300 text-rose-700">{{ $t('x_profile_partials_twofactorform.disable') }}</button>
                </div>
            </template>

            <p v-if="error" class="p-2 rounded-xl bg-rose-50 border border-rose-200 font-bold text-rose-700" data-2fa-error>{{ error }}</p>
        </div>
    </section>
</template>
