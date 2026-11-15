<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { qty, money } from '@/utils/format';

const props = defineProps({ rows: Object, totals: Object, warehouses: Array, categories: Array, filters: Object });
const form = reactive({ warehouse_id: props.filters.warehouse_id ?? '', category_id: props.filters.category_id ?? '', as_of: props.filters.as_of ?? '', search: props.filters.search ?? '' });
const apply = () => router.get(route('inventory.reports.valuation'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_reports_valuation.stock_valuation')" />
        <PageHeader :title="$t('i_reports_valuation.stock_balances_and_valuation')" :subtitle="filters.as_of ? `كما في ${filters.as_of}` : 'الرصيد الحالي'" :crumbs="[{ label: 'المستودعات' }, { label: 'تقييم المخزون' }]" />
        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
            <select v-model="form.warehouse_id" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('i_reports_valuation.all_warehouses') }}</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select>
            <select v-model="form.category_id" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('i_reports_valuation.all_categories') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
            <DatePicker v-model="form.as_of" class="rounded-xl border-slate-200 text-xs" :title="$t('i_reports_valuation.as_of_date')" />
            <input v-model="form.search" :placeholder="$t('i_reports_valuation.search')" class="rounded-xl border-slate-200 text-xs" />
            <button class="rounded-xl bg-indigo-600 text-white font-bold py-2">{{ $t('ui.apply') }}</button>
        </form>
        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard :label="$t('i_reports_valuation.number_of_items')" :value="totals.items" />
            <StatCard :label="$t('i_reports_valuation.total_quantities')" :value="qty(totals.qty)" tone="indigo" />
            <StatCard :label="$t('i_reports_valuation.stock_value')" :value="money(totals.value)" tone="emerald" />
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('i_reports_valuation.item') }}</th><th class="text-start px-3 py-2">{{ $t('i_reports_valuation.category') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_valuation.quantity') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_valuation.average_cost') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_valuation.value') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" class="border-t border-slate-100">
                        <td class="px-3 py-2"><Link :href="route('inventory.reports.item-card', { product_id: r.id })" class="hover:text-indigo-600 font-semibold">{{ r.name_ar }}</Link> <span class="text-slate-400 font-mono">{{ r.sku }}</span></td>
                        <td class="px-3 py-2 text-slate-500">{{ r.category || '—' }}</td>
                        <td class="px-3 py-2 text-end font-mono" :class="r.reorder_level && Number(r.qty) <= Number(r.reorder_level) ? 'text-rose-600 font-bold' : ''">{{ qty(r.qty) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(r.qty ? r.value / r.qty : r.average_cost) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(r.value) }}</td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="5" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>
