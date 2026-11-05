<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import BreakdownTable from '@/Components/UI/BreakdownTable.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { amount } from '@/utils/format';

defineProps({ filters: Object, stats: Object, by: String, breakdown: Object });
const tabs = [['supplier', 'حسب المورد'], ['item', 'حسب الصنف'], ['branch', 'حسب الفرع']];
</script>

<template>
    <AppLayout>
        <Head title="تقارير المشتريات" />
        <PageHeader title="تقارير المشتريات" :subtitle="`من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'التقارير' }, { label: 'المشتريات' }]">
            <template #actions>
                <ExportButtons report="purchases-analysis" :params="{ start_date: filters.start_date, end_date: filters.end_date, by }" permission="reports.purchases.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="reports.purchases" :filters="filters" :extra="{ by }" />

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">فواتير المشتريات</p><p class="font-mono font-black text-lg mt-1">{{ stats.invoices_count }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">المشتريات قبل الضريبة</p><p class="font-mono font-black text-lg mt-1 text-indigo-600">{{ amount(stats.total_purchases) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">ضريبة المدخلات</p><p class="font-mono font-black text-lg mt-1">{{ amount(stats.total_tax) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">المتبقي للموردين</p><p class="font-mono font-black text-lg mt-1 text-rose-600">{{ amount(stats.unpaid) }}</p></div>
        </div>

        <BreakdownTable route-name="reports.purchases" :tabs="tabs" :by="by" :filters="filters" :breakdown="breakdown" :name-label="by === 'item' ? 'الصنف' : by === 'branch' ? 'الفرع' : 'المورد'" />
    </AppLayout>
</template>
