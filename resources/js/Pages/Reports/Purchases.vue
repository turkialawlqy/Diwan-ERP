<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import BreakdownTable from '@/Components/UI/BreakdownTable.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { amount } from '@/utils/format';
const { t } = useI18n();

defineProps({ filters: Object, stats: Object, by: String, breakdown: Object });
const tabs = computed(() => ([['supplier', t('rep2_reports_purchases.by_supplier')], ['item', t('rep2_reports_purchases.by_item')], ['branch', t('rep2_reports_purchases.by_branch')]]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('rep2_reports_purchases.purchase_reports')" />
        <PageHeader :title="$t('rep2_reports_purchases.purchase_reports')" :subtitle="$t('rep2_reports_purchases.from_n_to_n', { n0: filters.start_date, n1: filters.end_date })" :crumbs="[{ label: $t('rep2_reports_purchases.reports') }, { label: $t('rep2_reports_purchases.purchases') }]">
            <template #actions>
                <ExportButtons report="purchases-analysis" :params="{ start_date: filters.start_date, end_date: filters.end_date, by }" permission="reports.purchases.export" />
            </template>
        </PageHeader>

        <DateRangeFilter route-name="reports.purchases" :filters="filters" :extra="{ by }" />

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('rep2_reports_purchases.purchase_invoices') }}</p><p class="font-mono font-black text-lg mt-1">{{ stats.invoices_count }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('rep2_reports_purchases.purchases_before_tax') }}</p><p class="font-mono font-black text-lg mt-1 text-indigo-600">{{ amount(stats.total_purchases) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('rep2_reports_purchases.input_tax') }}</p><p class="font-mono font-black text-lg mt-1">{{ amount(stats.total_tax) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('rep2_reports_purchases.remaining_due_to_suppliers') }}</p><p class="font-mono font-black text-lg mt-1 text-rose-600">{{ amount(stats.unpaid) }}</p></div>
        </div>

        <BreakdownTable route-name="reports.purchases" :tabs="tabs" :by="by" :filters="filters" :breakdown="breakdown" :name-label="by === 'item' ? $t('rep2_reports_purchases.item') : by === 'branch' ? $t('rep2_reports_purchases.branch') : $t('rep2_reports_purchases.supplier')" />
    </AppLayout>
</template>
