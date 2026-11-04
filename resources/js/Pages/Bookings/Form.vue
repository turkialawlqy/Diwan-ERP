<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SlotPicker from '@/Components/Bookings/SlotPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money } from '@/utils/format';

const props = defineProps({ booking: Object, prefill: Object, services: Array, resources: Array });
const b = props.booking;
const pre = props.prefill || {};
const [preDate = '', preTime = ''] = (b ? b.starts_at.replace('T', ' ').slice(0, 16) : (pre.starts_at || '')).split(' ');

const form = useForm({
    customer_id: b?.customer_id || (pre.customer ? Number(pre.customer) : null),
    bookable_service_id: b?.bookable_service_id || (pre.service ? Number(pre.service) : ''),
    booking_resource_id: b?.booking_resource_id || (pre.resource ? Number(pre.resource) : ''),
    date: preDate, time: preTime, deposit_amount: b ? Number(b.deposit_amount) : 0, deposit_method: 'cash', notes: b?.notes || '', starts_at: '',
});
useUnsavedGuard(form);

const service = computed(() => props.services.find((s) => s.id === form.bookable_service_id));
const serviceResources = computed(() => (service.value ? props.resources.filter((r) => service.value.resource_ids.includes(r.id)) : []));
watch(() => form.bookable_service_id, () => {
    if (form.booking_resource_id && !serviceResources.value.some((r) => r.id === form.booking_resource_id)) form.booking_resource_id = '';
    form.time = '';
    if (!b && service.value) form.deposit_amount = Math.round(Number(service.value.price) * service.value.deposit_percent) / 100;
});
const onPicked = (s) => { if (!form.booking_resource_id && !b) pickedResource.value = s.resource_id; };
const pickedResource = ref(null);
const resourceName = computed(() => props.resources.find((r) => r.id === (form.booking_resource_id || pickedResource.value))?.name);

const submit = () => {
    form.transform((d) => ({ ...d, starts_at: `${d.date} ${d.time}`, booking_resource_id: d.booking_resource_id || (b ? undefined : pickedResource.value) || null }));
    if (b) form.put(route('bookings.update', b.id));
    else form.post(route('bookings.store'));
};
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="b ? `تعديل ${b.number}` : 'حجز موعد'" />
        <PageHeader :title="b ? `تعديل موعد ${b.number}` : 'حجز موعد جديد'" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: b ? b.number : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field label="العميل *" :error="form.errors.customer_id"><PartyPicker v-if="!b" v-model="form.customer_id" /><p v-else class="font-bold py-2">{{ b.customer?.name }}</p></Field>
            <Field label="الخدمة *" :error="form.errors.bookable_service_id">
                <SelectCreate v-if="!b" v-model="form.bookable_service_id" :options="services" entity="bookable_service" :label="(s) => `${s.name} — ${s.duration_minutes} د`" placeholder="اختر الخدمة" />
                <p v-else class="font-bold py-2">{{ service?.name }}</p>
            </Field>
            <Field label="المورد" :error="form.errors.booking_resource_id" hint="اتركه فارغًا لاختيار أقل الموارد انشغالًا تلقائيًا">
                <SelectCreate v-model="form.booking_resource_id" :options="serviceResources" entity="booking_resource" :label="(r) => r.name" placeholder="أي مورد متاح" />
            </Field>
            <Field label="التاريخ *" :error="form.errors.date"><DatePicker v-model="form.date" required /></Field>
            <Field label="الوقت *" :error="form.errors.starts_at" class="md:col-span-2">
                <SlotPicker v-model="form.time" :url="route('bookings.slots')" :service="form.bookable_service_id" :resource="form.booking_resource_id" :date="form.date" :ignore="b?.id" @picked="onPicked" />
                <p v-if="form.time && resourceName" class="text-3xs text-slate-500 mt-1">المورد: {{ resourceName }}</p>
            </Field>
            <Field v-if="!b" label="العربون المستلم" :error="form.errors.deposit_amount" hint="يُسجَّل سند قبض باسم العميل الآن، ويُخصم من الفاتورة عند الإنجاز"><div class="flex gap-2"><input v-model.number="form.deposit_amount" type="text" inputmode="decimal" data-num :class="input" data-deposit /><select v-if="Number(form.deposit_amount) > 0" v-model="form.deposit_method" :class="input" class="w-32" data-deposit-method><option value="cash">نقدًا</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل</option></select></div></Field>
            <Field label="ملاحظات" :error="form.errors.notes" class="md:col-span-2"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <p v-if="service" class="md:col-span-2 text-slate-500">السعر: <span class="font-mono font-bold">{{ money(service.price) }}</span> — يُحتسب خصم العضوية تلقائيًا، وتُخصم زيارة من باقة العميل إن وُجدت.</p>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing || !form.time" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-save-booking>{{ b ? 'حفظ الموعد' : 'حجز' }}</button>
                <Link :href="b ? route('bookings.show', b.id) : route('bookings.calendar')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>
