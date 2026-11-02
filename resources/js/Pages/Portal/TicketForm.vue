<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ portal: Object, categories: Array, subscriptionId: Number });
const form = useForm({ subject: '', description: '', category_id: '', subscription_id: props.subscriptionId || '' });
useUnsavedGuard(form);
const submit = () => form.post(route('customer-portal.tickets.store', { company: props.portal.company.id }));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <Head title="تذكرة جديدة" />
    <PortalLayout :portal="portal" active="tickets">
        <h1 class="text-lg font-black text-slate-900 mb-3">تذكرة جديدة</h1>
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs">
            <Field label="الموضوع *" :error="form.errors.subject"><input v-model="form.subject" required maxlength="255" :class="input" data-subject /></Field>
            <Field v-if="categories.length" label="التصنيف" :error="form.errors.category_id"><select v-model="form.category_id" :class="input"><option value="">بدون</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
            <Field label="التفاصيل *" :error="form.errors.description"><textarea v-model="form.description" rows="6" required :class="input" data-description></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-ticket>إرسال</button>
                <Link :href="route('customer-portal.tickets.index', { company: portal.company.id })" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </PortalLayout>
</template>
