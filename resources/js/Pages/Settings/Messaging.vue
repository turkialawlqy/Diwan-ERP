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
        <Head title="إعدادات الرسائل" />
        <PageHeader title="إعدادات الرسائل" subtitle="بريد الشركة الخاص (SMTP) وبوابتا SMS وواتساب؛ بدونها يُستعمل بريد المنصة وروابط واتساب" :crumbs="[{ label: 'الإعدادات' }, { label: 'الرسائل' }]" />

        <form class="space-y-5 text-xs" @submit.prevent="save">
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" data-mail-settings>
                <h3 class="font-black text-slate-700">البريد الإلكتروني (SMTP)</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <Field label="الخادم"><input v-model="form.mail_host" :class="input" placeholder="smtp.example.com" /></Field>
                    <Field label="المنفذ" :error="form.errors.mail_port"><input v-model="form.mail_port" type="text" inputmode="numeric" data-num :class="input" placeholder="587" /></Field>
                    <Field label="التشفير"><select v-model="form.mail_encryption" :class="input + ' ps-3 pe-8'"><option value="">بدون</option><option value="tls">TLS</option><option value="ssl">SSL</option></select></Field>
                    <Field label="اسم المستخدم"><input v-model="form.mail_username" :class="input" autocomplete="off" /></Field>
                    <Field :label="'كلمة المرور' + (values.mail_password_set ? ' (محفوظة — اتركها فارغة لإبقائها)' : '')"><input v-model="form.mail_password" type="password" :class="input" autocomplete="new-password" /></Field>
                    <span></span>
                    <Field label="عنوان المرسِل" :error="form.errors.mail_from_address"><input v-model="form.mail_from_address" type="email" :class="input" placeholder="billing@company.com" /></Field>
                    <Field label="اسم المرسِل"><input v-model="form.mail_from_name" :class="input" /></Field>
                </div>
            </section>

            <section v-for="g in [{ key: 'sms', title: 'رسائل SMS' }, { key: 'whatsapp', title: 'واتساب' }]" :key="g.key" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" :data-gateway="g.key">
                <h3 class="font-black text-slate-700">{{ g.title }} — بوابة HTTPS</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Field label="عنوان البوابة (https)" :error="form.errors[g.key + '_url']"><input v-model="form[g.key + '_url']" :class="input" placeholder="https://api.provider.com/messages" /></Field>
                    <Field :label="'الرمز (Bearer)' + (values[g.key + '_token_set'] ? ' (محفوظ — اتركه فارغًا لإبقائه)' : '')"><input v-model="form[g.key + '_token']" type="password" :class="input" autocomplete="new-password" /></Field>
                    <Field v-if="g.key === 'sms'" label="اسم المرسِل (Sender)"><input v-model="form.sms_sender" :class="input" /></Field>
                    <Field label="قالب جسم الطلب (JSON)" class="sm:col-span-2" :error="form.errors[g.key + '_body']"><textarea v-model="form[g.key + '_body']" rows="2" :class="input + ' font-mono'" dir="ltr"></textarea></Field>
                </div>
                <p class="text-[11px] text-slate-500">المتغيرات: <span class="font-mono">{to}</span> رقم المستلم، <span class="font-mono">{message}</span> نص الرسالة، <span class="font-mono">{sender}</span> المرسِل. يُرسَل الطلب POST بـJSON مع ترويسة Authorization: Bearer.</p>
            </section>

            <div class="flex justify-end"><button type="submit" :disabled="form.processing" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button></div>
        </form>

        <section class="bg-white border border-slate-200 rounded-2xl p-5 mt-5 text-xs" data-messaging-test>
            <h3 class="font-black text-slate-700 mb-2">إرسال تجربة (بعد الحفظ)</h3>
            <div class="flex flex-wrap items-end gap-3">
                <Field label="القناة"><select v-model="testForm.channel" :class="input + ' ps-3 pe-8'"><option value="email">بريد</option><option value="sms">SMS</option><option value="whatsapp">واتساب</option></select></Field>
                <Field :label="testForm.channel === 'email' ? 'البريد' : 'الرقم (بصيغة دولية)'"><input v-model="testForm.to" :class="input" /></Field>
                <button type="button" :disabled="testForm.processing || !testForm.to" @click="test" class="px-4 py-2 rounded-xl border border-slate-200 font-bold" data-send-test>أرسل تجربة</button>
            </div>
            <p v-if="testForm.errors.test" class="text-rose-600 font-bold mt-2">{{ testForm.errors.test }}</p>
        </section>
    </AppLayout>
</template>
