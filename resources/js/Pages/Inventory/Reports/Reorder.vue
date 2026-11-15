<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { qty, money } from '@/utils/format';

defineProps({ rows: Object });
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_reports_reorder.reorder_level_2')" />
        <PageHeader :title="$t('i_reports_reorder.items_that_reached_the_reorder_l')" :subtitle="$t('i_reports_reorder.with_the_suggested_purchase_quan')" :crumbs="[{ label: 'المستودعات' }, { label: 'إعادة الطلب' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('i_reports_reorder.item') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_reorder.balance') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_reorder.reorder_level') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_reorder.suggested_quantity') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_reorder.estimated_cost') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-semibold">{{ r.name_ar }} <span class="text-slate-400 font-mono">{{ r.sku }}</span></td>
                        <td class="px-3 py-2 text-end font-mono text-rose-600 font-bold">{{ qty(r.on_hand) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(r.reorder_level) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(r.suggested) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(r.suggested * r.purchase_price) }}</td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="5" class="py-10 text-center text-slate-400">{{ $t('i_reports_reorder.no_items_below_the_reorder_level') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>
