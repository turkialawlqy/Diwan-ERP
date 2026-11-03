<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ property: Object, types: Object });
const p = props.property;
const form = useForm({ name: p?.name || '', type: p?.type || 'building', address: p?.address || '', notes: p?.notes || '' });
useUnsavedGuard(form);
const submit = () => (p ? form.put(route('real-estate.properties.update', p.id)) : form.post(route('real-estate.properties.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="p ? `تعديل ${p.name}` : 'عقار جديد'" />
        <PageHeader :title="p ? `تعديل العقار: ${p.name}` : 'عقار جديد'" :crumbs="[{ label: 'العقارات', href: route('real-estate.properties.index') }, { label: p ? p.name : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="الاسم *" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" data-property-name /></Field>
            <Field label="النوع *" :error="form.errors.type"><select v-model="form.type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="العنوان" :error="form.errors.address" class="md:col-span-3"><input v-model="form.address" :class="input" /></Field>
            <Field label="ملاحظات" :error="form.errors.notes" class="md:col-span-3"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-property>حفظ</button><Link :href="route('real-estate.properties.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
