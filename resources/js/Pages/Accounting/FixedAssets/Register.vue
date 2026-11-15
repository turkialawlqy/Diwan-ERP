<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import { money } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ report: Object, filters: Object });
const columns = [
    ['cost_open', t('a_register.cost_open')], ['additions', t('a_register.additions')], ['disposals', t('a_register.disposals')], ['cost_close', t('a_register.cost_close')],
    ['acc_open', t('a_register.acc_open')], ['acc_added', t('a_register.acc_added')], ['depreciation', t('a_register.depr')], ['impairment', t('a_asset.impairment')],
    ['acc_disposed', t('a_register.acc_disposed')], ['acc_close', t('a_register.acc_close')], ['book_value', t('a_register.book')],
];
const strong = ['cost_close', 'acc_close', 'book_value'];
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_register.fixed_asset_register')" />
        <PageHeader :title="$t('a_register.fixed_asset_register')" :subtitle="$t('a_register.subtitle', { from: filters.start_date, to: filters.end_date })" :crumbs="[{ label: 'المحاسبة' }, { label: 'الأصول الثابتة', href: route('accounting.fixed-assets.index') }, { label: 'السجل' }]">
            <template #actions>
                <a :href="route('accounting.fixed-assets.register', { ...filters, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('a_register.export_csv') }}</a>
            </template>
        </PageHeader>
        <DateRangeFilter route-name="accounting.fixed-assets.register" :filters="filters" />

        <div class="mb-4 p-3 rounded-xl text-xs border" :class="report.ledger.cost_difference === 0 && report.ledger.accumulated_difference === 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-amber-50 border-amber-200 text-amber-800'" data-ledger-check>
            <template v-if="report.ledger.cost_difference === 0 && report.ledger.accumulated_difference === 0">{{ $t('a_register.the_register_matches_the_asset_a') }}</template>
            <template v-else>
                الأستاذ العام: التكلفة {{ money(report.ledger.cost) }} (فرق {{ money(report.ledger.cost_difference) }})، والمجمع {{ money(report.ledger.accumulated) }} (فرق {{ money(report.ledger.accumulated_difference) }}).
                الفرق قيود يدوية على حسابات الأصول خارج سجل الأصول؛ راجعها في دفتر الأستاذ.
            </template>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[1200px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-3 py-2">{{ $t('a_register.asset') }}</th><th v-for="[key, label] in columns" :key="key" class="text-end px-2 py-2">{{ label }}</th></tr>
                </thead>
                <tbody>
                    <template v-for="category in report.categories" :key="category.name">
                        <tr class="bg-indigo-50/50"><td :colspan="columns.length + 1" class="px-3 py-1.5 font-black text-indigo-900">{{ category.name }}</td></tr>
                        <tr v-for="a in category.assets" :key="a.id" class="border-t border-slate-100">
                            <td class="px-3 py-1.5"><Link :href="route('accounting.fixed-assets.show', a.id)" class="hover:text-indigo-600"><span class="font-mono text-slate-400">{{ a.code }}</span> {{ a.name }}</Link></td>
                            <td v-for="[key] in columns" :key="key" class="px-2 py-1.5 text-end font-mono" :class="strong.includes(key) && 'font-bold'">{{ a[key] ? money(a[key]) : '—' }}</td>
                        </tr>
                        <tr class="border-t border-slate-200 font-bold bg-slate-50/50">
                            <td class="px-3 py-1.5">{{ $t('s_invoices.total') }} {{ category.name }}</td>
                            <td v-for="[key] in columns" :key="key" class="px-2 py-1.5 text-end font-mono">{{ money(category.totals[key]) }}</td>
                        </tr>
                    </template>
                    <tr v-if="!report.categories.length"><td :colspan="columns.length + 1" class="py-8 text-center text-slate-400">{{ $t('a_register.no_assets_in_this_period') }}</td></tr>
                </tbody>
                <tfoot v-if="report.categories.length">
                    <tr class="font-black text-slate-900 border-t-2 border-slate-300">
                        <td class="px-3 py-2">{{ $t('a_register.total') }}</td>
                        <td v-for="[key] in columns" :key="key" class="px-2 py-2 text-end font-mono">{{ money(report.totals[key]) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
