<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ vehicle: Object, customer: Object });
const v = props.vehicle;
const form = useForm({
    customer_id: v?.customer_id || props.customer?.id || null, plate: v?.plate || '', make: v?.make || '', model: v?.model || '', year: v?.year || '', vin: v?.vin || '', color: v?.color || '',
    odometer: v?.odometer || 0, next_service_date: v?.next_service_date ? String(v.next_service_date).slice(0, 10) : '', next_service_km: v?.next_service_km || '', notes: v?.notes || '',
});
useUnsavedGuard(form);
const submit = () => (v ? form.put(route('workshop.vehicles.update', v.id)) : form.post(route('workshop.vehicles.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="v ? `تعديل ${v.plate}` : 'مركبة جديدة'" />
        <PageHeader :title="v ? `تعديل المركبة ${v.plate}` : 'مركبة عميل جديدة'" :crumbs="[{ label: 'الورشة' }, { label: 'المركبات', href: route('workshop.vehicles.index') }, { label: v ? v.plate : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="العميل *" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" :initial="customer" /></Field>
            <Field label="رقم اللوحة *" :error="form.errors.plate"><input v-model="form.plate" required :class="input" class="font-mono" data-plate /></Field>
            <Field label="الماركة" :error="form.errors.make"><input v-model="form.make" :class="input" /></Field>
            <Field label="الموديل" :error="form.errors.model"><input v-model="form.model" :class="input" /></Field>
            <Field label="سنة الصنع" :error="form.errors.year"><input v-model="form.year" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="رقم الهيكل (VIN)" :error="form.errors.vin"><input v-model="form.vin" :class="input" class="font-mono" dir="ltr" /></Field>
            <Field label="اللون" :error="form.errors.color"><input v-model="form.color" :class="input" /></Field>
            <Field label="قراءة العداد (كم)" :error="form.errors.odometer"><input v-model="form.odometer" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="الصيانة القادمة (تاريخ)" :error="form.errors.next_service_date"><DatePicker v-model="form.next_service_date" /></Field>
            <Field label="الصيانة القادمة (كم)" :error="form.errors.next_service_km"><input v-model="form.next_service_km" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="ملاحظات" :error="form.errors.notes" class="md:col-span-3"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-vehicle>حفظ</button><Link :href="route('workshop.vehicles.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
