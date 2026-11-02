<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ customerId: Number, customer: Object });
const form = useForm({ customer_id: props.customerId || null, name: '', email: '' });
useUnsavedGuard(form);
const submit = () => form.post(route('portal.users.store'));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="دعوة مستخدم بوابة" />
        <PageHeader title="دعوة مستخدم إلى بوابة العملاء" :crumbs="[{ label: 'المبيعات' }, { label: 'بوابة العملاء', href: route('portal.users.index') }, { label: 'دعوة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field label="العميل *" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" :initial="customer" /></Field>
            <Field label="الاسم *" :error="form.errors.name"><input v-model="form.name" required :class="input" data-portal-name /></Field>
            <Field label="البريد الإلكتروني *" :error="form.errors.email" hint="يصله رمز الدخول عند كل تسجيل"><input v-model="form.email" type="email" required :class="input" dir="ltr" data-portal-email-input /></Field>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-portal-user>إرسال الدعوة</button>
                <Link :href="route('portal.users.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>
