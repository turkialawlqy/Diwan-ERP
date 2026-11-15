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
        <Head :title="$t('x_gym_attendance.attendance_report')" />
        <PageHeader :title="$t('x_gym_attendance.members_attendance_report')" :crumbs="[{ label: $t('x_gym_attendance.club') }, { label: $t('x_gym_attendance.attendance') }]"><template #actions><DatePicker :model-value="date" @update:model-value="go" /></template></PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('x_gym_attendance.entries') }}</p><p class="text-lg font-black font-mono">{{ day.allowed }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('x_gym_attendance.distinct_members') }}</p><p class="text-lg font-black font-mono">{{ day.unique }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('x_gym_attendance.rejected') }}</p><p class="text-lg font-black font-mono text-rose-600">{{ day.denied }}</p></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('x_gym_attendance.time') }}</th><th class="px-4 py-2 text-start">{{ $t('x_gym_attendance.member') }}</th><th class="px-4 py-2 text-start">{{ $t('x_gym_attendance.code') }}</th><th class="px-4 py-2 text-start">{{ $t('x_gym_attendance.result') }}</th></tr></thead>
            <tbody><tr v-for="r in day.rows" :key="r.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ r.time }}</td><td class="px-4 py-2 font-bold">{{ r.name }}</td><td class="px-4 py-2 font-mono">{{ r.code }}</td><td class="px-4 py-2" :class="r.allowed ? 'text-emerald-700' : 'text-rose-600'">{{ r.allowed ? $t('x_gym_attendance.allowed') : r.reason }}</td></tr>
                <tr v-if="!day.rows.length"><td colspan="4" class="py-10 text-center text-slate-400">{{ $t('x_gym_attendance.no_attendance_on_this_day') }}</td></tr></tbody></table></div>
    </AppLayout>
</template>
