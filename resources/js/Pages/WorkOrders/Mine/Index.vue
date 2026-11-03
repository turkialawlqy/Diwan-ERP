<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';
import { PhoneIcon, MapPinIcon } from '@heroicons/vue/24/outline';

defineProps({ hasEmployee: Boolean, open: Array, recent: Array, running: Object, statuses: Object });
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : 'بلا موعد');
const tone = { new: 'bg-slate-100 text-slate-600', scheduled: 'bg-sky-100 text-sky-700', in_progress: 'bg-emerald-100 text-emerald-700', on_hold: 'bg-amber-100 text-amber-700', done: 'bg-emerald-50 text-emerald-700' };
</script>

<template>
    <Head title="أوامري" />
    <MobileLayout title="أوامر العمل الموكلة إليّ">
        <p v-if="!hasEmployee" class="bg-white rounded-2xl p-5 text-xs text-slate-600" data-no-employee>حسابك غير مرتبط بملف موظف. اطلب من المدير ربط مستخدمك بموظف من «الموارد البشرية».</p>
        <Link v-if="running" :href="route('work-orders.mine.show', running.work_order_id)" class="block rounded-2xl bg-emerald-600 text-white p-4 text-xs font-bold" data-running>مؤقت يعمل الآن — اضغط للعودة إليه</Link>
        <div v-for="o in open" :key="o.id" class="bg-white rounded-2xl p-4 shadow-sm space-y-1" :data-order="o.number">
            <div class="flex items-center justify-between"><span class="font-mono font-bold text-indigo-600 text-xs">{{ o.number }}</span><span class="px-2 py-0.5 rounded-full text-3xs font-bold" :class="tone[o.status]">{{ statuses[o.status] }}</span></div>
            <Link :href="route('work-orders.mine.show', o.id)" class="block font-black text-sm" data-open-order>{{ o.title }}</Link>
            <p class="text-xs text-slate-600">{{ o.customer?.name }}<span v-if="o.work_type"> · {{ o.work_type.name }}</span></p>
            <p class="text-3xs text-slate-500 font-mono">{{ when(o.scheduled_start) }}</p>
            <p v-if="o.site_address" class="text-3xs text-slate-500 flex items-center gap-1"><MapPinIcon class="w-3.5 h-3.5" /> {{ o.site_address }}</p>
        </div>
        <p v-if="hasEmployee && !open.length" class="text-center text-xs text-slate-400 py-10">لا أوامر عمل مفتوحة.</p>
        <div v-if="recent.length" class="pt-2"><p class="text-3xs font-bold text-slate-500 mb-1">أُنجزت مؤخرًا</p>
            <Link v-for="o in recent" :key="o.id" :href="route('work-orders.mine.show', o.id)" class="block bg-white/70 rounded-xl p-3 mb-1 text-xs"><b class="font-mono text-slate-500">{{ o.number }}</b> {{ o.title }}</Link></div>
    </MobileLayout>
</template>
