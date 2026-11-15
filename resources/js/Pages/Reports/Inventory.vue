<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    CubeIcon,
    BanknotesIcon,
    ExclamationTriangleIcon,
    PrinterIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Object,
    stats: Object,
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const printReport = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('rep2_reports_inventory.inventory_valuation_report')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('rep2_reports_inventory.inventory_valuation_report')" :crumbs="[{ label: $t('rep2_reports_inventory.reports') }, { label: $t('rep2_reports_inventory.inventory_report') }]">
<template #actions>
<button 
                        @click="printReport"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PrinterIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('rep2_reports_inventory.print_the_inventory_report') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 no-print">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_inventory.total_registered_items') }}</span>
                        <h3 class="text-lg font-black text-slate-900">{{ $t('rep2_reports_inventory.n_items', { n0: stats?.total_products || 0 }) }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_inventory.total_estimated_stock_value') }}</span>
                        <h3 class="text-lg font-black font-mono text-emerald-600">
                            {{ formatCurrency(stats?.total_stock_value) }} <span class="text-xs font-normal">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">{{ $t('rep2_reports_inventory.low_stock_alerts') }}</span>
                        <h3 class="text-lg font-black text-amber-600">{{ $t('rep2_reports_inventory.n_items', { n0: stats?.low_stock_count || 0 }) }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <ExclamationTriangleIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Inventory Valuation Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-2xs overflow-hidden print:border-none print:shadow-none">
                <div class="p-4 border-b border-slate-100 hidden print:block">
                    <h2 class="text-base font-black text-slate-900">{{ $t('rep2_reports_inventory.comprehensive_inventory_valuatio') }}</h2>
                    <p class="text-3xs text-slate-500">{{ $t('rep2_reports_inventory.n_for_business_and_warehouse_man', { n0: $page.props.brand?.name }) }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-500 font-bold">
                                <th class="py-3 px-4 text-start">{{ $t('rep2_reports_inventory.item_code_sku') }}</th>
                                <th class="py-3 px-4 text-start">{{ $t('rep2_reports_inventory.product_name') }}</th>
                                <th class="py-3 px-4 text-start">{{ $t('rep2_reports_inventory.category') }}</th>
                                <th class="py-3 px-4 text-end">{{ $t('rep2_reports_inventory.cost_price') }}</th>
                                <th class="py-3 px-4 text-end">{{ $t('rep2_reports_inventory.selling_price') }}</th>
                                <th class="py-3 px-4 text-center">{{ $t('rep2_reports_inventory.quantity_in_stock') }}</th>
                                <th class="py-3 px-4 text-end">{{ $t('rep2_reports_inventory.total_value_at_cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="prod in products.data" :key="prod.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                    {{ prod.sku || '-' }}
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ prod.name_ar }}
                                </td>
                                <td class="py-3 px-4 text-slate-600 text-3xs">
                                    {{ prod.category || $t('rep2_reports_inventory.general') }}
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(prod.purchase_price) }} ﷼
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700 text-end">
                                    {{ formatCurrency(prod.selling_price) }} ﷼
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-bold">
                                    <span :class="prod.is_low ? 'text-rose-600' : 'text-slate-800'">
                                        {{ Number(prod.total_stock) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono font-black text-emerald-600 text-end">
                                    {{ formatCurrency(prod.stock_value) }} ﷼
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100 font-black border-t-2 border-slate-200 text-slate-900 text-xs">
                                <td colspan="6" class="py-3 px-4 text-start font-sans">{{ $t('rep2_reports_inventory.total_stock_value') }}</td>
                                <td class="py-3 px-4 font-mono text-end text-sm text-emerald-600">
                                    {{ formatCurrency(stats?.total_stock_value) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div v-if="products.last_page > 1" class="no-print flex items-center justify-between p-4 border-t border-slate-100 text-xs text-slate-500">
                    <span>{{ $t('rep2_reports_inventory.page_n_of_n_n_items', { n0: products.current_page, n1: products.last_page, n2: products.total }) }}</span>
                    <div class="flex gap-2">
                        <Link v-if="products.prev_page_url" :href="products.prev_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('rep2_reports_inventory.previous') }}</Link>
                        <Link v-if="products.next_page_url" :href="products.next_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('rep2_reports_inventory.next') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    .no-print {
        display: none !important;
    }
}
</style>
