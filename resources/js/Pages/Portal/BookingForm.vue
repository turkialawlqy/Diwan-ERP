<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SlotPicker from '@/Components/Bookings/SlotPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, services: Array });
const form = useForm({ bookable_service_id: '', booking_resource_id: '', date: '', time: '', notes: '', starts_at: '' });
useUnsavedGuard(form);
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
const service = computed(() => props.services.find((s) => s.id === form.bookable_service_id));
const choose = (id) => { form.bookable_service_id = id; form.booking_resource_id = ''; form.time = ''; };
const submit = () => form.transform((d) => ({ ...d, starts_at: `${d.date} ${d.time}`, booking_resource_id: d.booking_resource_id || null })).post(r('customer-portal.bookings.store'));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <Head title="حجز موعد" />
    <PortalLayout :portal="portal" active="bookings">
        <h1 class="text-lg font-black text-slate-900 mb-3">حجز موعد</h1>
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs">
            <Field label="الخدمة *" :error="form.errors.bookable_service_id">
                <div class="grid sm:grid-cols-2 gap-2">
                    <button v-for="s in services" :key="s.id" type="button" class="text-start rounded-xl border p-3" :class="form.bookable_service_id === s.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'" :data-service="s.name" @click="choose(s.id)">
                        <b class="block">{{ s.name }}</b><span class="text-slate-500">{{ s.duration_minutes }} دقيقة · {{ money(s.price) }}</span>
                    </button>
                    <p v-if="!services.length" class="text-slate-400">لا خدمات متاحة للحجز الآن.</p>
                </div>
            </Field>
            <Field v-if="service && service.resources.length > 1" label="مع" :error="form.errors.booking_resource_id">
                <select v-model="form.booking_resource_id" :class="input" @change="form.time = ''"><option value="">أي متاح</option><option v-for="x in service.resources" :key="x.id" :value="x.id">{{ x.name }}</option></select>
            </Field>
            <Field label="التاريخ *" :error="form.errors.date"><DatePicker v-model="form.date" /></Field>
            <Field label="الوقت *" :error="form.errors.starts_at"><SlotPicker v-model="form.time" :url="r('customer-portal.bookings.slots')" :service="form.bookable_service_id" :resource="form.booking_resource_id" :date="form.date" /></Field>
            <Field label="ملاحظات" :error="form.errors.notes"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing || !form.time" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-confirm-booking>تأكيد الحجز</button>
                <Link :href="r('customer-portal.bookings.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">رجوع</Link>
            </div>
        </form>
    </PortalLayout>
</template>
