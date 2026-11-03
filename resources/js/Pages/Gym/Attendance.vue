<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';

const props = defineProps({ date: String, day: Object });
const go = (d) => router.get(route('gym.attendance'), { date: d }, { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head title="تقرير الحضور" />
        <PageHeader title="تقرير حضور الأعضاء" :crumbs="[{ label: 'النادي' }, { label: 'الحضور' }]"><template #actions><DatePicker :model-value="date" @update:model-value="go" /></template></PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">عدد الدخول</p><p class="text-lg font-black font-mono">{{ day.allowed }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">أعضاء مختلفون</p><p class="text-lg font-black font-mono">{{ day.unique }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">مرفوض</p><p class="text-lg font-black font-mono text-rose-600">{{ day.denied }}</p></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الوقت</th><th class="px-4 py-2 text-start">العضو</th><th class="px-4 py-2 text-start">الكود</th><th class="px-4 py-2 text-start">النتيجة</th></tr></thead>
            <tbody><tr v-for="r in day.rows" :key="r.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ r.time }}</td><td class="px-4 py-2 font-bold">{{ r.name }}</td><td class="px-4 py-2 font-mono">{{ r.code }}</td><td class="px-4 py-2" :class="r.allowed ? 'text-emerald-700' : 'text-rose-600'">{{ r.allowed ? 'مسموح' : r.reason }}</td></tr>
                <tr v-if="!day.rows.length"><td colspan="4" class="py-10 text-center text-slate-400">لا حضور في هذا اليوم.</td></tr></tbody></table></div>
    </AppLayout>
</template>
