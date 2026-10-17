<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import StatementSection from '@/Components/UI/StatementSection.vue';
import { amount } from '@/utils/format';

const props = defineProps({ report: Object, filters: Object, eosCurrent: { type: Number, default: 0 } });
const ledgerQuery = { end_date: props.filters.as_of };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head title="قائمة المركز المالي" />
        <PageHeader title="قائمة المركز المالي (الميزانية العمومية)" :subtitle="`كما في ${filters.as_of}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'القوائم المالية' }]">
            <template #actions><button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('ui.print') }}</button></template>
        </PageHeader>

        <DateRangeFilter route-name="accounting.reports.balance-sheet" :filters="filters" single />

        <div :class="[report.is_balanced ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200', 'border rounded-xl px-4 py-2 text-xs font-bold mb-4 max-w-5xl']">
            {{ report.is_balanced ? '✓ الميزانية متوازنة: الأصول = الخصوم + حقوق الملكية' : '✗ الميزانية غير متوازنة — راجع القيود' }}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 max-w-5xl">
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <StatementSection title="الأصول" :rows="report.assets.rows" :total="report.total_assets" :ledger-query="ledgerQuery" />
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <StatementSection title="الخصوم" :rows="report.liabilities.rows" :total="report.total_liabilities" :ledger-query="ledgerQuery" />
                <p v-if="eosCurrent > 0" class="text-3xs text-slate-500 px-1">منها جزء متداول من مخصص نهاية الخدمة (مستحق خلال 12 شهراً): <span class="font-mono font-bold">{{ amount(eosCurrent) }}</span></p>
                <StatementSection title="حقوق الملكية" :rows="[...report.equity.rows, { name: 'صافي ربح/خسارة الفترة غير المقفلة', amount: report.unclosed_profit }]" :total="report.total_equity" :ledger-query="ledgerQuery" />
                <div class="flex justify-between px-4 py-2 rounded-xl bg-slate-100 font-black text-xs">
                    <span>إجمالي الخصوم وحقوق الملكية</span>
                    <span class="font-mono">{{ amount(report.total_liabilities_equity) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
