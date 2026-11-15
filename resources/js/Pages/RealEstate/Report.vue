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
        <Head :title="$t('x_realestate_report.property_reports')" />
        <PageHeader :title="$t('x_realestate_report.property_reports')" :crumbs="[{ label: $t('x_realestate_report.properties') }, { label: $t('x_realestate_report.reports') }]" />
        <div class="grid grid-cols-3 gap-3 mb-4"><StatCard :label="$t('x_realestate_report.modules')" :value="occupancy.units" /><StatCard :label="$t('x_realestate_report.rented')" :value="occupancy.occupied" /><StatCard :label="$t('x_realestate_report.vacant')" :value="occupancy.vacant" /></div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-expiring><h2 class="px-5 py-3 font-bold border-b border-slate-100">{{ $t('x_realestate_report.leases_ending_within_60_days') }}</h2><table class="w-full"><tbody>
                <tr v-for="l in expiring" :key="l.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('real-estate.leases.show', l.id)" class="font-mono font-bold text-indigo-600">{{ l.number }}</Link></td><td class="px-4 py-2">{{ l.unit }}</td><td class="px-4 py-2">{{ l.tenant }}</td><td class="px-4 py-2 font-mono">{{ $t('x_realestate_report.n_n_days', { n0: l.end_date, n1: l.days }) }}</td></tr>
                <tr v-if="!expiring.length"><td class="py-6 text-center text-slate-400">{{ $t('x_realestate_report.no_leases_ending_soon') }}</td></tr></tbody></table></div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-arrears><h2 class="px-5 py-3 font-bold border-b border-slate-100">{{ $t('x_realestate_report.overdue_rent') }}</h2><table class="w-full"><tbody>
                <tr v-for="l in arrears" :key="l.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('real-estate.leases.show', l.id)" class="font-mono font-bold text-indigo-600">{{ l.number }}</Link></td><td class="px-4 py-2">{{ l.unit }}</td><td class="px-4 py-2">{{ l.tenant }}</td><td class="px-4 py-2 font-mono">{{ money(l.rent) }}</td></tr>
                <tr v-if="!arrears.length"><td class="py-6 text-center text-slate-400">{{ $t('x_realestate_report.no_arrears') }}</td></tr></tbody></table></div>
        </div>
    </AppLayout>
</template>
