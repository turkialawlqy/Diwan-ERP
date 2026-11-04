<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ order: Object, prefill: Object, workTypes: Array, vehicles: { type: Array, default: () => [] }, rentalUnits: { type: Array, default: () => [] }, technicians: Array, warehouses: Array, priorities: Object });
const o = props.order;
const pre = props.prefill || {};
const split = (v) => (v ? String(v).replace('T', ' ').slice(0, 16).split(' ') : ['', '']);
const [sd, st] = split(o?.scheduled_start || pre.scheduled_start);
const [ed, et] = split(o?.scheduled_end || pre.scheduled_end);

const form = useForm({
    customer_id: o?.customer_id || pre.customer_id || null, ticket_id: o?.ticket_id || pre.ticket_id || null, booking_id: o?.booking_id || pre.booking_id || null,
    work_type_id: o?.work_type_id || '', vehicle_id: o?.vehicle_id || pre.vehicle_id || '', odometer_in: o?.odometer_in || pre.odometer_in || '', property_unit_id: o?.property_unit_id || pre.property_unit_id || '', title: o?.title || pre.title || '', description: o?.description || pre.description || '', site_address: o?.site_address || '', site_phone: o?.site_phone || '',
    priority: o?.priority || 'normal', start_date: sd, start_time: st || '09:00', end_date: ed, end_time: et || '', assigned_to: o?.assigned_to || '', crew: o?.crew_ids || [],
    tasks: (o?.tasks || []).map((t) => t.title), warehouse_id: o?.warehouse_id || '',
});
useUnsavedGuard(form);
const customerVehicles = computed(() => props.vehicles.filter((v) => !form.customer_id || v.customer_id === form.customer_id));
const toggleCrew = (id) => { form.crew = form.crew.includes(id) ? form.crew.filter((x) => x !== id) : [...form.crew, id]; };
const submit = () => {
    form.transform((d) => ({
        ...d, tasks: d.tasks.filter((t) => t && t.trim()),
        scheduled_start: d.start_date && d.start_time ? `${d.start_date} ${d.start_time}` : null,
        scheduled_end: d.end_date && d.end_time ? `${d.end_date} ${d.end_time}` : null,
    }));
    if (o) form.put(route('work-orders.update', o.id));
    else form.post(route('work-orders.store'));
};
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="o ? `تعديل ${o.number}` : 'أمر عمل جديد'" />
        <PageHeader :title="o ? `تعديل أمر العمل ${o.number}` : 'أمر عمل جديد'" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: o ? o.number : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="العميل *" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" :initial="o?.customer" /></Field>
            <Field label="العنوان *" :error="form.errors.title" class="md:col-span-2"><input v-model="form.title" required :class="input" data-title /></Field>
            <Field label="نوع العمل" :error="form.errors.work_type_id" hint="يحدد سعر الساعة المفوتَر"><SelectCreate v-model="form.work_type_id" :options="workTypes" entity="work_type" :label="(t) => t.name" placeholder="بدون" /></Field>
            <Field label="الأولوية" :error="form.errors.priority"><select v-model="form.priority" :class="input"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="مستودع القطع" :error="form.errors.warehouse_id"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" :label="(w) => w.name_ar" placeholder="الافتراضي" /></Field>
            <Field v-if="vehicles.length || form.vehicle_id" label="المركبة" :error="form.errors.vehicle_id"><SelectCreate v-model="form.vehicle_id" :options="customerVehicles" entity="vehicle" :label="(v) => v.label" placeholder="بدون" data-vehicle /></Field>
            <Field v-if="form.vehicle_id" label="قراءة العداد عند الاستلام (كم)" :error="form.errors.odometer_in"><input v-model="form.odometer_in" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-odometer-in /></Field>
            <Field v-if="rentalUnits.length || form.property_unit_id" label="الوحدة العقارية" :error="form.errors.property_unit_id"><SelectCreate v-model="form.property_unit_id" :options="rentalUnits" entity="property_unit" :label="(u) => u.label" placeholder="بدون" /></Field>
            <Field label="الوصف" :error="form.errors.description" class="md:col-span-3"><textarea v-model="form.description" rows="2" :class="input"></textarea></Field>
            <Field label="عنوان الموقع" :error="form.errors.site_address" class="md:col-span-2"><input v-model="form.site_address" :class="input" /></Field>
            <Field label="هاتف الموقع" :error="form.errors.site_phone"><input v-model="form.site_phone" :class="input" dir="ltr" /></Field>
            <Field label="يبدأ في" :error="form.errors.scheduled_start"><div class="flex gap-2"><DatePicker v-model="form.start_date" /><input v-model="form.start_time" type="time" :class="input" class="w-28" dir="ltr" /></div></Field>
            <Field label="ينتهي في" :error="form.errors.scheduled_end"><div class="flex gap-2"><DatePicker v-model="form.end_date" /><input v-model="form.end_time" type="time" :class="input" class="w-28" dir="ltr" /></div></Field>
            <Field label="الفني المسؤول" :error="form.errors.assigned_to"><SelectCreate v-model="form.assigned_to" :options="technicians" entity="employee" :label="(t) => t.name" placeholder="غير محدد" data-lead /></Field>
            <div class="md:col-span-3">
                <p class="font-bold text-slate-700 mb-2">باقي الفريق</p>
                <div class="flex flex-wrap gap-2"><label v-for="t in technicians" :key="t.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :class="form.crew.includes(t.id) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200'"><input type="checkbox" class="rounded border-slate-300" :checked="form.crew.includes(t.id)" @change="toggleCrew(t.id)" /> {{ t.name }}</label></div>
            </div>
            <div class="md:col-span-3 space-y-2">
                <p class="font-bold text-slate-700">قائمة المهام</p>
                <div v-for="(t, i) in form.tasks" :key="i" class="flex gap-2"><input v-model="form.tasks[i]" :class="input" :data-task-input="i" /><button type="button" class="p-1 text-rose-600" @click="form.tasks.splice(i, 1)"><TrashIcon class="w-4 h-4" /></button></div>
                <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold text-slate-600" @click="form.tasks.push('')" data-add-task><PlusIcon class="w-3.5 h-3.5 inline" /> مهمة</button>
            </div>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-order>حفظ</button><Link :href="o ? route('work-orders.show', o.id) : route('work-orders.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>
