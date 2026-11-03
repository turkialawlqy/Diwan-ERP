<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ unit: Object, propertyId: Number, properties: Array, types: Object });
const u = props.unit;
const form = useForm({ property_id: props.propertyId || '', unit_number: u?.unit_number || '', type: u?.type || 'apartment', floor: u?.floor || '', area: u?.area || '', rent_amount: u ? Number(u.rent_amount) : 0, status: u && u.status !== 'occupied' ? u.status : 'vacant', notes: u?.notes || '' });
useUnsavedGuard(form);
const submit = () => (u ? form.put(route('real-estate.units.update', u.id)) : form.post(route('real-estate.units.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="u ? `تعديل الوحدة ${u.unit_number}` : 'وحدة جديدة'" />
        <PageHeader :title="u ? `تعديل الوحدة ${u.unit_number}` : 'وحدة جديدة'" :crumbs="[{ label: 'العقارات', href: route('real-estate.properties.index') }, { label: u ? u.unit_number : 'وحدة جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="العقار *" :error="form.errors.property_id"><SelectCreate v-model="form.property_id" :options="properties" entity="property" :label="(p) => p.name" placeholder="اختر العقار" required /></Field>
            <Field label="رقم الوحدة *" :error="form.errors.unit_number"><input v-model="form.unit_number" required :class="input" data-unit-number /></Field>
            <Field label="النوع" :error="form.errors.type"><select v-model="form.type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="الدور" :error="form.errors.floor"><input v-model="form.floor" :class="input" /></Field>
            <Field label="المساحة (م²)" :error="form.errors.area"><input v-model="form.area" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="الإيجار المعتاد" :error="form.errors.rent_amount"><input v-model.number="form.rent_amount" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-unit-rent /></Field>
            <Field v-if="!u || u.status !== 'occupied'" label="الحالة" :error="form.errors.status"><select v-model="form.status" :class="input"><option value="vacant">شاغرة</option><option value="maintenance">تحت الصيانة</option></select></Field>
            <Field label="ملاحظات" :error="form.errors.notes" class="md:col-span-3"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-unit>حفظ</button><Link :href="route('real-estate.properties.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
