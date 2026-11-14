<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DateRangeFilter from '@/Components/UI/DateRangeFilter.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { amount } from '@/utils/format';

const props = defineProps({ supplier: Object, transactions: Array, openingBalance: Number, closingBalance: Number, filters: Object });
const totals = computed(() => ({
    debit: props.transactions.reduce((s, t) => s + (Number(t.debit) || 0), 0),
    credit: props.transactions.reduce((s, t) => s + (Number(t.credit) || 0), 0),
}));
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="`كشف حساب المورد - ${supplier.name}`" />
        <PageHeader :title="`كشف حساب المورد: ${supplier.name}`" :crumbs="[{ label: 'المشتريات' }, { label: 'الموردون', href: route('purchases.suppliers.index') }, { label: 'كشف حساب' }]">
            <template #actions>
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold print:hidden">{{ $t('ui.print') }}</button>
                <ExportButtons report="supplier-statement" :params="{ supplier_id: supplier.id, start_date: filters.start_date, end_date: filters.end_date }" permission="purchases.suppliers.export" />
            </template>
        </PageHeader>

        <DateRangeFilter :route-name="'purchases.suppliers.statement'" :route-params="{ supplier: supplier.id }" :filters="{ start_date: filters.start_date || '', end_date: filters.end_date || '' }" />

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('p_suppliers_statement.opening_balance') }}</p><p class="font-mono font-black text-lg mt-1">{{ amount(openingBalance) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('p_suppliers_statement.total_invoices') }}</p><p class="font-mono font-black text-lg mt-1">{{ amount(totals.credit) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">{{ $t('p_suppliers_statement.paid_and_returned') }}</p><p class="font-mono font-black text-lg mt-1 text-emerald-700">{{ amount(totals.debit) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4" data-closing><p class="text-slate-500">{{ $t('p_suppliers_statement.due_to_the_supplier') }}</p><p class="font-mono font-black text-lg mt-1 text-rose-600">{{ amount(closingBalance) }}</p></div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-4 py-2">{{ $t('p_suppliers_statement.date') }}</th><th class="px-3 py-2 text-start">{{ $t('p_suppliers_statement.type') }}</th><th class="px-3 py-2 text-start">{{ $t('p_suppliers_statement.reference') }}</th><th class="px-3 py-2 text-start">{{ $t('p_suppliers_statement.description') }}</th><th class="px-3 py-2 text-end">{{ $t('p_suppliers_statement.debit') }}</th><th class="px-3 py-2 text-end">{{ $t('p_suppliers_statement.credit') }}</th><th class="px-3 py-2 text-end">{{ $t('p_suppliers_statement.balance') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(t, i) in transactions" :key="i" class="border-t border-slate-100" data-statement-row>
                        <td class="px-4 py-1.5 font-mono">{{ t.date }}</td>
                        <td class="px-3 py-1.5">{{ t.type_ar }}</td>
                        <td class="px-3 py-1.5 font-mono">{{ t.reference }}</td>
                        <td class="px-3 py-1.5 text-slate-600">{{ t.notes }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ t.debit ? amount(t.debit) : '' }}</td>
                        <td class="px-3 py-1.5 text-end font-mono">{{ t.credit ? amount(t.credit) : '' }}</td>
                        <td class="px-3 py-1.5 text-end font-mono font-bold">{{ amount(t.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
