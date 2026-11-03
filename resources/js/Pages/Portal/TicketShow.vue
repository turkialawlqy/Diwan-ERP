<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ portal: Object, ticket: Object, messages: Array, canReply: Boolean, canRate: Boolean, statuses: Object });
const reply = useForm({ body: '' });
const rate = useForm({ rating: props.ticket.rating || 0, comment: props.ticket.rating_comment || '' });
useUnsavedGuard(reply);
const p = (n) => route(n, { company: props.portal.company.id, ticket: props.ticket.id });
const send = () => reply.post(p('customer-portal.tickets.reply'), { preserveScroll: true, onSuccess: () => reply.reset() });
const sendRate = () => rate.post(p('customer-portal.tickets.rate'), { preserveScroll: true });
</script>

<template>
    <Head :title="`تذكرة ${ticket.number}`" />
    <PortalLayout :portal="portal" active="tickets">
        <div class="mb-3"><h1 class="text-lg font-black text-slate-900">{{ ticket.subject }}</h1><p class="text-xs text-slate-500"><span class="font-mono">{{ ticket.number }}</span> · {{ statuses[ticket.status] || ticket.status }}</p></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-3 text-xs whitespace-pre-line">{{ ticket.description }}</div>
        <div class="space-y-2 mb-4" data-messages>
            <div v-for="m in messages" :key="m.id" class="rounded-2xl p-3 text-xs max-w-[85%]" :class="m.mine ? 'bg-indigo-50 border border-indigo-100 me-auto' : 'bg-white border border-slate-200 ms-auto'">
                <p class="font-bold text-slate-700 mb-1">{{ m.author }} <span class="font-mono font-normal text-slate-400">{{ m.created_at?.slice(0, 16).replace('T', ' ') }}</span></p>
                <p class="whitespace-pre-line">{{ m.body }}</p>
            </div>
        </div>
        <form v-if="canReply" @submit.prevent="send" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2 text-xs">
            <textarea v-model="reply.body" rows="3" required placeholder="اكتب ردك" class="w-full rounded-xl border-slate-200 text-xs" data-reply></textarea>
            <p v-if="reply.errors.body" class="text-rose-600 font-bold">{{ reply.errors.body }}</p>
            <button type="submit" :disabled="reply.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-send-reply>إرسال الرد</button>
        </form>
        <form v-if="canRate" @submit.prevent="sendRate" class="bg-white border border-slate-200 rounded-2xl p-4 mt-3 space-y-2 text-xs" data-rate>
            <p class="font-bold">قيّم الخدمة</p>
            <div class="flex gap-1"><button v-for="n in 5" :key="n" type="button" class="text-2xl" :class="n <= rate.rating ? 'text-amber-400' : 'text-slate-300'" @click="rate.rating = n">★</button></div>
            <textarea v-model="rate.comment" rows="2" placeholder="ملاحظات (اختياري)" class="w-full rounded-xl border-slate-200 text-xs"></textarea>
            <button type="submit" :disabled="!rate.rating || rate.processing" class="px-5 py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold disabled:opacity-40">إرسال التقييم</button>
        </form>
    </PortalLayout>
</template>
