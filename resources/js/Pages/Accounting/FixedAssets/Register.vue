<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { money } from '@/utils/format';

const props = defineProps({ report: Object, filters: Object });
const columns = [
    ['cost_open', 'التكلفة أول المدة'], ['additions', 'إضافات'], ['disposals', 'استبعادات'], ['cost_close', 'التكلفة آخر المدة'],
    ['acc_open', 'المجمع أول المدة'], ['acc_added', 'مجمع أصول افتتاحية'], ['depreciation', 'إهلاك الفترة'], ['impairment', 'هبوط القيمة'],
    ['acc_disposed', 'مجمع المستبعد'], ['acc_close', 'المجمع آخر المدة'], ['book_value', 'صافي القيمة الدفترية'],
];
const strong = ['cost_close', 'acc_close', 'book_value'];
</script>

<template>
    <AppLayout>
        <Head title="سجل الأصول الثابتة" />
        <PageHeader title="سجل الأصول الثابتة" :subtitle="`حركة الأصول والإهلاك من ${filters.start_date} إلى ${filters.end_date}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'الأصول الثابتة', href: route('accounting.fixed-assets.index') }, { label: 'السجل' }]">
            <template #actions>
                <a :href="route('accounting.fixed-assets.register', { ...filters, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تصدير CSV</a>
            </template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.fixed-assets.register" :filters="filters" />

        <div class="mb-4 p-3 rounded-xl text-xs border" :class="report.ledger.cost_difference === 0 && report.ledger.accumulated_difference === 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-amber-50 border-amber-200 text-amber-800'" data-ledger-check>
            <template v-if="report.ledger.cost_difference === 0 && report.ledger.accumulated_difference === 0">✓ السجل يطابق أرصدة حسابات الأصول ومجمعات الإهلاك في الأستاذ العام.</template>
            <template v-else>
                الأستاذ العام: التكلفة {{ money(report.ledger.cost) }} (فرق {{ money(report.ledger.cost_difference) }})، والمجمع {{ money(report.ledger.accumulated) }} (فرق {{ money(report.ledger.accumulated_difference) }}).
                الفرق قيود يدوية على حسابات الأصول خارج سجل الأصول؛ راجعها في دفتر الأستاذ.
            </template>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[1200px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-3 py-2">الأصل</th><th v-for="[key, label] in columns" :key="key" class="text-end px-2 py-2">{{ label }}</th></tr>
                </thead>
                <tbody>
                    <template v-for="category in report.categories" :key="category.name">
                        <tr class="bg-indigo-50/50"><td :colspan="columns.length + 1" class="px-3 py-1.5 font-black text-indigo-900">{{ category.name }}</td></tr>
                        <tr v-for="a in category.assets" :key="a.id" class="border-t border-slate-100">
                            <td class="px-3 py-1.5"><Link :href="route('accounting.fixed-assets.show', a.id)" class="hover:text-indigo-600"><span class="font-mono text-slate-400">{{ a.code }}</span> {{ a.name }}</Link></td>
                            <td v-for="[key] in columns" :key="key" class="px-2 py-1.5 text-end font-mono" :class="strong.includes(key) && 'font-bold'">{{ a[key] ? money(a[key]) : '—' }}</td>
                        </tr>
                        <tr class="border-t border-slate-200 font-bold bg-slate-50/50">
                            <td class="px-3 py-1.5">إجمالي {{ category.name }}</td>
                            <td v-for="[key] in columns" :key="key" class="px-2 py-1.5 text-end font-mono">{{ money(category.totals[key]) }}</td>
                        </tr>
                    </template>
                    <tr v-if="!report.categories.length"><td :colspan="columns.length + 1" class="py-8 text-center text-slate-400">لا توجد أصول في هذه الفترة.</td></tr>
                </tbody>
                <tfoot v-if="report.categories.length">
                    <tr class="font-black text-slate-900 border-t-2 border-slate-300">
                        <td class="px-3 py-2">الإجمالي</td>
                        <td v-for="[key] in columns" :key="key" class="px-2 py-2 text-end font-mono">{{ money(report.totals[key]) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
