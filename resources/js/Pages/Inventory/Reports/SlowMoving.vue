<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { qty, money } from '@/utils/format';

const props = defineProps({ rows: Object, days: Number });
const days = ref(props.days);
const apply = () => router.get(route('inventory.reports.slow-moving'), { days: days.value }, { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_reports_slowmoving.slow_moving_items')" />
        <PageHeader :title="$t('i_reports_slowmoving.slow_moving_items')" :subtitle="`أصناف لها رصيد ولم تُصرف منذ ${days} يوماً`" :crumbs="[{ label: 'المستودعات' }, { label: 'الأصناف الراكدة' }]">
            <template #actions><form @submit.prevent="apply" class="flex gap-2 text-xs"><input v-model.number="days" type="text" inputmode="decimal" data-num min="1" class="w-24 rounded-xl border-slate-200 text-xs" /><button class="px-4 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('ui.apply') }}</button></form></template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('i_reports_slowmoving.item') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_slowmoving.balance') }}</th><th class="text-end px-3 py-2">{{ $t('i_reports_slowmoving.value') }}</th><th class="px-3 py-2">{{ $t('i_reports_slowmoving.last_issue') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" class="border-t border-slate-100"><td class="px-3 py-2 font-semibold">{{ r.name_ar }}</td><td class="px-3 py-2 text-end font-mono">{{ qty(r.qty) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(r.value) }}</td><td class="px-3 py-2 text-center font-mono">{{ r.last_out || $t('i_reports_slowmoving.never') }}</td></tr>
                    <tr v-if="!rows.data.length"><td colspan="4" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>
