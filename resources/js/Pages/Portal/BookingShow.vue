<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, booking: Object, canCancel: Boolean, cancelHours: Number, statuses: Object });
const form = useForm({ reason: '' });
const cancel = () => { if (confirm('إلغاء هذا الحجز؟')) form.post(route('customer-portal.bookings.cancel', { company: props.portal.company.id, booking: props.booking.id }), { preserveScroll: true }); };
const when = (v) => String(v).replace('T', ' ').slice(0, 16);
</script>

<template>
    <Head :title="`حجز ${booking.number}`" />
    <PortalLayout :portal="portal" active="bookings">
        <h1 class="text-lg font-black text-slate-900 mb-3">حجز <span class="font-mono">{{ booking.number }}</span></h1>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3 text-xs mb-3">
            <div><p class="text-slate-500">الخدمة</p><p class="font-bold">{{ booking.service }}</p></div>
            <div><p class="text-slate-500">الحالة</p><p class="font-bold" data-status>{{ statuses[booking.status] }}</p></div>
            <div><p class="text-slate-500">الموعد</p><p class="font-mono font-bold">{{ when(booking.starts_at) }}</p></div>
            <div><p class="text-slate-500">السعر</p><p class="font-mono font-bold">{{ money(Number(booking.price) - Number(booking.member_discount)) }}</p></div>
            <div v-if="booking.cancel_reason" class="col-span-2"><p class="text-slate-500">سبب الإلغاء</p><p>{{ booking.cancel_reason }}</p></div>
        </div>
        <div v-if="['pending', 'confirmed'].includes(booking.status)" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-2">
            <template v-if="canCancel"><input v-model="form.reason" placeholder="سبب الإلغاء (اختياري)" class="w-full rounded-xl border-slate-200 text-xs" /><button type="button" :disabled="form.processing" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 font-bold" @click="cancel" data-cancel-booking>إلغاء الحجز</button></template>
            <p v-else class="text-slate-500">لا يمكن الإلغاء من البوابة قبل أقل من {{ cancelHours }} ساعة من الموعد؛ تواصل معنا.</p>
            <p v-if="form.errors.status" class="text-rose-600 font-bold">{{ form.errors.status }}</p>
        </div>
        <Link :href="route('customer-portal.bookings.index', { company: portal.company.id })" class="inline-block mt-3 text-xs text-indigo-600 font-bold">← كل حجوزاتي</Link>
    </PortalLayout>
</template>
