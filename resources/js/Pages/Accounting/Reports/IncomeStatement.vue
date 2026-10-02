<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';

const props = defineProps({ report: Object, costCenters: Array, filters: Object });
const costCenter = ref(props.filters.cost_center_id ?? '');
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head title="قائمة الدخل" />
        <PageHeader title="قائمة الدخل (الأرباح والخسائر)" :subtitle="`من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]">
            <template #actions><button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button></template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.income-statement" :filters="filters" :extra="{ cost_center_id: costCenter || undefined }">
            <label><span class="block font-bold text-slate-600 mb-1">مركز التكلفة</span>
                <select v-model="costCenter" class="rounded-xl border-slate-200 text-xs">
                    <option value="">كل المراكز</option>
                    <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name_ar }}</option>
                </select>
            </label>
        </DateRangeFilter>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 max-w-4xl">
            <StatementSection title="الإيرادات" :rows="report.revenue.rows" :total="report.total_revenue" :ledger-query="filters" />
            <StatementSection title="المصروفات والتكاليف" :rows="report.expense.rows" :total="report.total_expense" :ledger-query="filters" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 text-xs">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">تكلفة المبيعات</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.cost_of_sales) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">مجمل الربح</p><p class="font-mono font-black text-slate-800 mt-1">{{ amount(report.gross_profit) }}</p></div>
                <div :class="[report.net_profit >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700', 'rounded-xl p-3']">
                    <p>{{ report.net_profit >= 0 ? 'صافي الربح' : 'صافي الخسارة' }}</p>
                    <p class="font-mono font-black text-lg mt-1">{{ amount(report.net_profit) }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
