<script setup>
import { computed, watch } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { BuildingOfficeIcon, UserIcon, EnvelopeIcon, PhoneIcon, LockClosedIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';

/**
 * First run of a system installed on the office's own computer: the company
 * and the manager's account, in one step. Shown only until a company exists.
 */
const props = defineProps({ countries: { type: Array, default: () => [] }, currencies: { type: Array, default: () => [] } });

const form = useForm({
    company_name: '',
    country_code: 'SA',
    currency: 'SAR',
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
});

const selectedCountry = computed(() => props.countries.find((c) => c.code === form.country_code));
watch(() => form.country_code, () => { if (selectedCountry.value) form.currency = selectedCountry.value.currency; });

const submit = () => form.post(route('setup'), { onFinish: () => form.reset('password', 'password_confirmation') });

const input = 'w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl ps-10 pe-4 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:bg-white transition';
const select = 'w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl ps-10 pe-10 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
</script>

<template>
    <AuthLayout wide>
        <Head :title="$t('x_auth_localsetup.first_setup')" />

        <div class="text-center mb-6">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3"><CheckCircleIcon class="w-7 h-7" /></div>
            <h2 class="text-xl font-black text-slate-900">{{ $t('x_auth_localsetup.the_system_was_installed_success') }}</h2>
            <p class="text-sm text-slate-500 mt-1">{{ $t('x_auth_localsetup.one_last_step_enter_your_company') }}</p>
        </div>

        <form @submit.prevent="submit" class="space-y-6" data-local-setup>
            <section class="space-y-4">
                <h3 class="flex items-center gap-2 text-sm font-black text-slate-800"><span class="w-6 h-6 rounded-full bg-indigo-600 text-white text-xs flex items-center justify-center">1</span> {{ $t('x_auth_localsetup.company_details') }}</h3>
                <div>
                    <label for="company_name" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.company_name') }}</label>
                    <div class="relative">
                        <BuildingOfficeIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                        <input id="company_name" v-model="form.company_name" type="text" required autofocus :placeholder="$t('x_auth_localsetup.example_al_nakheel_trading_est')" :class="input" />
                    </div>
                    <InputError class="mt-1" :message="form.errors.company_name" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="country_code" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.country') }}</label>
                        <select id="country_code" v-model="form.country_code" :class="select">
                            <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                        </select>
                        <p v-if="selectedCountry" class="text-[11px] text-slate-500 mt-1">{{ selectedCountry.tax }}</p>
                    </div>
                    <div>
                        <label for="currency" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.base_currency') }}</label>
                        <select id="currency" v-model="form.currency" :class="select">
                            <option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.name }} ({{ c.code }})</option>
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">{{ $t('x_auth_localsetup.it_cannot_be_changed_after_start') }}</p>
                    </div>
                </div>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-5">
                <h3 class="flex items-center gap-2 text-sm font-black text-slate-800"><span class="w-6 h-6 rounded-full bg-indigo-600 text-white text-xs flex items-center justify-center">2</span> {{ $t('x_auth_localsetup.manager_account_you_sign_in_with') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="name" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.name') }}</label>
                        <div class="relative">
                            <UserIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                            <input id="name" v-model="form.name" type="text" required :class="input" />
                        </div>
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div>
                        <label for="phone" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.mobile_optional') }}</label>
                        <div class="relative">
                            <PhoneIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                            <input id="phone" v-model="form.phone" type="tel" dir="ltr" :class="input" />
                        </div>
                    </div>
                </div>
                <div>
                    <label for="email" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.email_sign_in_name') }}</label>
                    <div class="relative">
                        <EnvelopeIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                        <input id="email" v-model="form.email" type="email" required dir="ltr" placeholder="you@example.com" :class="input" />
                    </div>
                    <InputError class="mt-1" :message="form.errors.email" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.password') }}</label>
                        <div class="relative">
                            <LockClosedIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                            <input id="password" v-model="form.password" type="password" required autocomplete="new-password" :class="input" />
                        </div>
                        <InputError class="mt-1" :message="form.errors.password" />
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('x_auth_localsetup.confirm_password') }}</label>
                        <div class="relative">
                            <LockClosedIcon class="w-5 h-5 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                            <input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="input" />
                        </div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500">{{ $t('x_auth_localsetup.at_least_8_characters_keep_the_e') }}</p>
            </section>

            <button type="submit" :disabled="form.processing" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl shadow-lg transition disabled:opacity-50">
                {{ form.processing ? $t('x_auth_localsetup.preparing_this_may_take_a_minute') : $t('x_auth_localsetup.start_using_the_system') }}
            </button>
        </form>
    </AuthLayout>
</template>
