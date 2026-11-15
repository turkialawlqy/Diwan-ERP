<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ values: Object });
const form = useForm({ ...props.values });
useUnsavedGuard(form);
const save = () => form.put(route('settings.messaging.update'), { preserveScroll: true, onSuccess: () => form.reset('mail_password', 'sms_token', 'whatsapp_token') });

const testForm = useForm({ channel: 'email', to: '' });
const test = () => testForm.post(route('settings.messaging.test'), { preserveScroll: true });
const input = 'w-full rounded-xl border-slate-200 text-xs';
const page = usePage();
</script>

<template>
    <AppLayout>
        <Head :title="$t('set2_settings_messaging.messaging_settings')" />
        <PageHeader :title="$t('set2_settings_messaging.messaging_settings')" :subtitle="$t('set2_settings_messaging.the_company_s_own_mail_smtp_and')" :crumbs="[{ label: $t('set2_settings_messaging.settings') }, { label: $t('set2_settings_messaging.messages') }]" />

        <form class="space-y-5 text-xs" @submit.prevent="save">
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" data-mail-settings>
                <h3 class="font-black text-slate-700">{{ $t('set2_settings_messaging.email_smtp') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <Field :label="$t('set2_settings_messaging.server')"><input v-model="form.mail_host" :class="input" placeholder="smtp.example.com" /></Field>
                    <Field :label="$t('set2_settings_messaging.port')" :error="form.errors.mail_port"><input v-model="form.mail_port" type="text" inputmode="numeric" data-num :class="input" placeholder="587" /></Field>
                    <Field :label="$t('set2_settings_messaging.encryption')"><select v-model="form.mail_encryption" :class="input + ' ps-3 pe-8'"><option value="">{{ $t('set2_settings_messaging.none') }}</option><option value="tls">TLS</option><option value="ssl">SSL</option></select></Field>
                    <Field :label="$t('set2_settings_messaging.username')"><input v-model="form.mail_username" :class="input" autocomplete="off" /></Field>
                    <Field :label="$t('set2_settings_messaging.password') + (values.mail_password_set ? $t('set2_settings_messaging.saved_leave_empty_to_keep_it') : '')"><input v-model="form.mail_password" type="password" :class="input" autocomplete="new-password" /></Field>
                    <span></span>
                    <Field :label="$t('set2_settings_messaging.sender_address')" :error="form.errors.mail_from_address"><input v-model="form.mail_from_address" type="email" :class="input" placeholder="billing@company.com" /></Field>
                    <Field :label="$t('set2_settings_messaging.sender_name')"><input v-model="form.mail_from_name" :class="input" /></Field>
                </div>
            </section>

            <section v-for="g in [{ key: 'sms', title: $t('set2_settings_messaging.sms_messages') }, { key: 'whatsapp', title: $t('set2_settings_messaging.whatsapp') }]" :key="g.key" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" :data-gateway="g.key">
                <h3 class="font-black text-slate-700">{{ $t('set2_settings_messaging.n_https_gateway', { n0: g.title }) }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Field :label="$t('set2_settings_messaging.gateway_address_https')" :error="form.errors[g.key + '_url']"><input v-model="form[g.key + '_url']" :class="input" placeholder="https://api.provider.com/messages" /></Field>
                    <Field :label="$t('set2_settings_messaging.token_bearer') + (values[g.key + '_token_set'] ? $t('set2_settings_messaging.saved_leave_empty_to_keep_it_2') : '')"><input v-model="form[g.key + '_token']" type="password" :class="input" autocomplete="new-password" /></Field>
                    <Field v-if="g.key === 'sms'" :label="$t('set2_settings_messaging.sender_name_2')"><input v-model="form.sms_sender" :class="input" /></Field>
                    <Field :label="$t('set2_settings_messaging.request_body_template_json')" class="sm:col-span-2" :error="form.errors[g.key + '_body']"><textarea v-model="form[g.key + '_body']" rows="2" :class="input + ' font-mono'" dir="ltr"></textarea></Field>
                </div>
                <p class="text-[11px] text-slate-500">{{ $t('set2_settings_messaging.variables') }} <span class="font-mono">{to}</span> {{ $t('set2_settings_messaging.recipient_number') }} <span class="font-mono">{message}</span> {{ $t('set2_settings_messaging.message_text') }} <span class="font-mono">{sender}</span> {{ $t('set2_settings_messaging.sender_the_request_is_sent_as_a') }}</p>
            </section>

            <div class="flex justify-end"><button type="submit" :disabled="form.processing" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('set2_settings_messaging.save') }}</button></div>
        </form>

        <section class="bg-white border border-slate-200 rounded-2xl p-5 mt-5 text-xs" data-messaging-test>
            <h3 class="font-black text-slate-700 mb-2">{{ $t('set2_settings_messaging.send_a_test_after_saving') }}</h3>
            <div class="flex flex-wrap items-end gap-3">
                <Field :label="$t('set2_settings_messaging.channel')"><select v-model="testForm.channel" :class="input + ' ps-3 pe-8'"><option value="email">{{ $t('set2_settings_messaging.email') }}</option><option value="sms">SMS</option><option value="whatsapp">{{ $t('set2_settings_messaging.whatsapp') }}</option></select></Field>
                <Field :label="testForm.channel === 'email' ? $t('set2_settings_messaging.email_2') : $t('set2_settings_messaging.number_international_format')"><input v-model="testForm.to" :class="input" /></Field>
                <button type="button" :disabled="testForm.processing || !testForm.to" @click="test" class="px-4 py-2 rounded-xl border border-slate-200 font-bold" data-send-test>{{ $t('set2_settings_messaging.send_a_test') }}</button>
            </div>
            <p v-if="testForm.errors.test" class="text-rose-600 font-bold mt-2">{{ testForm.errors.test }}</p>
        </section>
    </AppLayout>
</template>
