<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';

defineProps({ report: Object, filters: Object });
const labels = { operating: 'التدفقات النقدية من الأنشطة التشغيلية', investing: 'التدفقات النقدية من الأنشطة الاستثمارية', financing: 'التدفقات النقدية من الأنشطة التمويلية' };
</script>

<template>
    <AppLayout>
        <Head title="قائمة التدفقات النقدية" />
        <PageHeader title="قائمة التدفقات النقدية (الطريقة المباشرة)" :subtitle="`من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]" />
        <DateRangeFilter route-name="accounting.reports.cash-flow" :filters="filters" />

        <div class="bg-white border border-slate-200 rounded-2xl p-5 max-w-4xl">
            <StatementSection v-for="(section, key) in report.sections" :key="key" :title="labels[key]" :rows="section.rows" :total="section.total" totalLabel="صافي التدفق" />
            <div class="grid grid-cols-3 gap-3 text-xs">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">النقدية أول الفترة</p><p class="font-mono font-black mt-1">{{ amount(report.opening_cash) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">صافي التغير في النقدية</p><p class="font-mono font-black mt-1">{{ amount(report.net_change) }}</p></div>
                <div class="rounded-xl bg-indigo-50 text-indigo-700 p-3"><p>النقدية آخر الفترة</p><p class="font-mono font-black mt-1">{{ amount(report.closing_cash) }}</p></div>
            </div>
        </div>
    </AppLayout>
</template>
