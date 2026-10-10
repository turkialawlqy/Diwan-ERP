<script setup>
import { ref } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

const submit = () => {
    form.transform((data) => (useRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .post(route('two-factor.verify'), { onFinish: () => form.reset() });
};
</script>

<template>
    <AuthLayout>
        <Head title="التحقق الثنائي" />
        <div class="text-center mb-6">
            <h2 class="text-xl font-black text-slate-900">التحقق الثنائي</h2>
            <p class="text-xs text-slate-500 mt-1">
                {{ useRecovery ? 'أدخل أحد رموز الاسترداد التي حفظتها عند التفعيل.' : 'أدخل الرمز المكوّن من 6 أرقام من تطبيق المصادقة.' }}
            </p>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div v-if="!useRecovery">
                <label for="code" class="block text-xs font-bold text-slate-700 mb-1">رمز التحقق</label>
                <input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" autofocus maxlength="6" dir="ltr"
                    class="w-full rounded-xl border-slate-200 text-center text-lg font-mono tracking-[0.5em]" />
                <InputError :message="form.errors.code" class="mt-1" />
            </div>
            <div v-else>
                <label for="recovery_code" class="block text-xs font-bold text-slate-700 mb-1">رمز الاسترداد</label>
                <input id="recovery_code" v-model="form.recovery_code" autocomplete="off" dir="ltr" class="w-full rounded-xl border-slate-200 text-center font-mono" />
                <InputError :message="form.errors.recovery_code" class="mt-1" />
            </div>
            <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-indigo-600 py-2.5 text-sm font-bold text-white disabled:opacity-50">تحقق ودخول</button>
            <div class="flex items-center justify-between text-xs">
                <button type="button" @click="useRecovery = !useRecovery" class="font-bold text-indigo-600">{{ useRecovery ? 'استخدام رمز التطبيق' : 'استخدام رمز استرداد' }}</button>
                <Link :href="route('login')" class="text-slate-500">رجوع لتسجيل الدخول</Link>
            </div>
        </form>
    </AuthLayout>
</template>
