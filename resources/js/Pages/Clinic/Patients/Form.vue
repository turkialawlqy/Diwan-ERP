<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ patient: Object, customerId: Number, customer: Object, genders: Object });
const p = props.patient;
const form = useForm({
    customer_id: props.customerId || null, birth_date: p?.birth_date ? String(p.birth_date).slice(0, 10) : '', gender: p?.gender || '', blood_type: p?.blood_type || '',
    allergies: p?.allergies || '', chronic_conditions: p?.chronic_conditions || '', emergency_contact: p?.emergency_contact || '', notes: p?.notes || '',
});
useUnsavedGuard(form);
const submit = () => (p ? form.put(route('clinic.patients.update', p.id)) : form.post(route('clinic.patients.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="p ? `ملف ${p.file_number}` : 'ملف مريض جديد'" />
        <PageHeader :title="p ? `تعديل ملف المريض ${p.file_number}` : 'ملف مريض جديد'" :crumbs="[{ label: 'العيادة' }, { label: 'ملفات المرضى', href: route('clinic.patients.index') }, { label: p ? p.file_number : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="المريض (العميل) *" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-if="!p" v-model="form.customer_id" :initial="customer" /><p v-else class="font-bold py-2">{{ customer?.name }}</p></Field>
            <Field label="تاريخ الميلاد" :error="form.errors.birth_date"><DatePicker v-model="form.birth_date" /></Field>
            <Field label="الجنس" :error="form.errors.gender"><select v-model="form.gender" :class="input"><option value="">—</option><option v-for="(l, k) in genders" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="فصيلة الدم" :error="form.errors.blood_type"><input v-model="form.blood_type" :class="input" dir="ltr" maxlength="4" /></Field>
            <Field label="جهة اتصال للطوارئ" :error="form.errors.emergency_contact"><input v-model="form.emergency_contact" :class="input" /></Field>
            <Field label="الحساسية" :error="form.errors.allergies" class="md:col-span-3"><textarea v-model="form.allergies" rows="2" :class="input"></textarea></Field>
            <Field label="الأمراض المزمنة" :error="form.errors.chronic_conditions" class="md:col-span-3"><textarea v-model="form.chronic_conditions" rows="2" :class="input"></textarea></Field>
            <Field label="ملاحظات" :error="form.errors.notes" class="md:col-span-3"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-patient>حفظ</button><Link :href="p ? route('clinic.patients.show', p.id) : route('clinic.patients.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
