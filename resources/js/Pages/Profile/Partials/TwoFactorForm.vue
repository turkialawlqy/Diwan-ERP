<script setup>
import { ref } from 'vue';
import axios from 'axios';
import QRCode from 'qrcode';
import { router } from '@inertiajs/vue3';

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
        error.value = errors ? Object.values(errors)[0][0] : (e.response?.data?.message || 'حدث خطأ، حاول مرة أخرى.');
    } finally {
        busy.value = false;
    }
};

const start = () => call(async () => {
    const { data } = await axios.post(route('two-factor.enable'), { current_password: password.value });
    secret.value = data.secret;
    qr.value = await QRCode.toDataURL(data.uri, { margin: 1, width: 200 });
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
</script>

<template>
    <section dir="rtl" class="text-right">
        <header>
            <h2 class="text-lg font-bold text-gray-900">التحقق الثنائي (2FA)</h2>
            <p class="mt-1 text-sm text-gray-600">
                طبقة حماية إضافية: بعد كلمة المرور يُطلب رمز من تطبيق المصادقة في جوالك (Google Authenticator أو Microsoft Authenticator أو Authy).
            </p>
            <p v-if="state.required && step !== 'enabled'" class="mt-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm font-bold text-amber-800">
                التحقق الثنائي إلزامي لحسابك. فعّله للمتابعة إلى النظام.
            </p>
        </header>

        <div class="mt-5 space-y-4 text-sm">
            <template v-if="step === 'off'">
                <label class="block font-bold text-gray-700">كلمة المرور الحالية</label>
                <input v-model="password" type="password" autocomplete="current-password" class="w-full max-w-xs rounded-lg border-gray-300" @keyup.enter="start" />
                <button type="button" :disabled="busy || !password" @click="start" class="block rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">تفعيل التحقق الثنائي</button>
            </template>

            <template v-else-if="step === 'setup'">
                <ol class="list-decimal pe-5 space-y-1 text-gray-700">
                    <li>افتح تطبيق المصادقة وامسح الرمز التالي.</li>
                    <li>أو أدخل المفتاح يدوياً: <code dir="ltr" class="rounded bg-gray-100 px-2 py-0.5 font-mono">{{ secret }}</code></li>
                    <li>أدخل الرمز المكوّن من 6 أرقام الظاهر في التطبيق.</li>
                </ol>
                <img :src="qr" alt="رمز إعداد التحقق الثنائي" class="h-48 w-48 rounded-lg border" />
                <input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" placeholder="123456" class="w-40 rounded-lg border-gray-300 text-center font-mono tracking-widest" @keyup.enter="confirm" />
                <button type="button" :disabled="busy || code.length < 6" @click="confirm" class="block rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">تأكيد التفعيل</button>
            </template>

            <template v-else-if="step === 'codes'">
                <p class="font-bold text-emerald-700">احفظ رموز الاسترداد في مكان آمن. كل رمز يُستخدم مرة واحدة للدخول إذا فقدت جوالك، ولن تظهر مرة أخرى.</p>
                <ul dir="ltr" class="grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-3 font-mono">
                    <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                </ul>
                <div class="flex gap-2">
                    <button type="button" @click="copyCodes" class="rounded-lg border border-gray-300 px-4 py-2 font-bold">نسخ الرموز</button>
                    <button type="button" @click="finish" class="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white">حفظتها، متابعة</button>
                </div>
            </template>

            <template v-else>
                <p class="font-bold text-emerald-700">التحقق الثنائي مفعّل. رموز الاسترداد المتبقية: {{ state.recoveryRemaining }}</p>
                <label class="block font-bold text-gray-700">كلمة المرور الحالية (لإعادة توليد الرموز أو التعطيل)</label>
                <input v-model="password" type="password" autocomplete="current-password" class="w-full max-w-xs rounded-lg border-gray-300" />
                <div class="flex gap-2">
                    <button type="button" :disabled="busy || !password" @click="regenerate" class="rounded-lg border border-gray-300 px-4 py-2 font-bold disabled:opacity-50">رموز استرداد جديدة</button>
                    <button v-if="!state.required" type="button" :disabled="busy || !password" @click="disable" class="rounded-lg border border-rose-300 px-4 py-2 font-bold text-rose-700 disabled:opacity-50">تعطيل</button>
                </div>
            </template>

            <p v-if="error" class="font-bold text-rose-600">{{ error }}</p>
        </div>
    </section>
</template>
