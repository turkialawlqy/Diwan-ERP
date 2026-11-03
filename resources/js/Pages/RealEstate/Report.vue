<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { money } from '@/utils/format';

defineProps({ expiring: Array, arrears: Array, occupancy: Object });
</script>

<template>
    <AppLayout>
        <Head title="تقارير العقارات" />
        <PageHeader title="تقارير العقارات" :crumbs="[{ label: 'العقارات' }, { label: 'التقارير' }]" />
        <div class="grid grid-cols-3 gap-3 mb-4"><StatCard label="الوحدات" :value="occupancy.units" /><StatCard label="مؤجَّرة" :value="occupancy.occupied" /><StatCard label="شاغرة" :value="occupancy.vacant" /></div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-expiring><h2 class="px-5 py-3 font-bold border-b border-slate-100">عقود تنتهي خلال 60 يومًا</h2><table class="w-full"><tbody>
                <tr v-for="l in expiring" :key="l.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('real-estate.leases.show', l.id)" class="font-mono font-bold text-indigo-600">{{ l.number }}</Link></td><td class="px-4 py-2">{{ l.unit }}</td><td class="px-4 py-2">{{ l.tenant }}</td><td class="px-4 py-2 font-mono">{{ l.end_date }} ({{ l.days }} يوم)</td></tr>
                <tr v-if="!expiring.length"><td class="py-6 text-center text-slate-400">لا عقود قريبة الانتهاء.</td></tr></tbody></table></div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-arrears><h2 class="px-5 py-3 font-bold border-b border-slate-100">إيجارات متأخرة</h2><table class="w-full"><tbody>
                <tr v-for="l in arrears" :key="l.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('real-estate.leases.show', l.id)" class="font-mono font-bold text-indigo-600">{{ l.number }}</Link></td><td class="px-4 py-2">{{ l.unit }}</td><td class="px-4 py-2">{{ l.tenant }}</td><td class="px-4 py-2 font-mono">{{ money(l.rent) }}</td></tr>
                <tr v-if="!arrears.length"><td class="py-6 text-center text-slate-400">لا متأخرات.</td></tr></tbody></table></div>
        </div>
    </AppLayout>
</template>
