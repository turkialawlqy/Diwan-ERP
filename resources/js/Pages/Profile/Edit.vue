<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';
import { ShieldExclamationIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
    twoFactor: { type: Object, default: () => ({ enabled: false, required: false, recoveryRemaining: 0 }) },
});
// Until a mandatory 2FA is set up every other screen leads back here: that card comes first.
const mustEnrol = props.twoFactor.required && !props.twoFactor.enabled;
</script>

<template>
    <AppLayout own-errors>
        <Head title="الملف الشخصي" />
        <PageHeader title="الملف الشخصي" subtitle="بيانات حسابك وكلمة المرور والتحقق الثنائي" :crumbs="[{ label: 'حسابي' }, { label: 'الملف الشخصي' }]" />

        <div v-if="mustEnrol" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-start gap-2" data-2fa-required>
            <ShieldExclamationIcon class="w-5 h-5 shrink-0" />
            <p><span class="font-bold">التحقق الثنائي إلزامي لحسابك.</span> فعّله من البطاقة أدناه لتفتح بقية شاشات النظام.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
            <div :class="mustEnrol ? 'lg:order-first' : 'lg:order-last'" id="two-factor" class="bg-white border border-slate-200 rounded-2xl p-5">
                <TwoFactorForm :state="twoFactor" />
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <UpdatePasswordForm />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
