<script setup>
import { useI18n } from 'vue-i18n';
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { money } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ filters: Object, employees: Array, entries: Array, byEmployee: Array });
const f = reactive({ ...props.filters });
const go = () => router.get(route('work-orders.timesheet'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const hm = (m) => t('wo2_workorders_timesheet.nh_nm', { n0: Math.floor(m / 60), n1: m % 60 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('wo2_workorders_timesheet.time_logs')" />
        <PageHeader :title="$t('wo2_workorders_timesheet.time_logs')" :subtitle="$t('wo2_workorders_timesheet.technicians_hours_on_work_orders')" :crumbs="[{ label: $t('wo2_workorders_timesheet.work_orders'), href: route('work-orders.index') }, { label: $t('wo2_workorders_timesheet.time_logs') }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-2 text-xs">
            <DatePicker v-model="f.from" @change="go" /><DatePicker v-model="f.to" @change="go" />
            <select v-model="f.employee" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('wo2_workorders_timesheet.all_technicians') }}</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4"><div v-for="r in byEmployee" :key="r.name" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs" data-employee-total>
            <b>{{ r.name }}</b><p class="font-mono">{{ $t('wo2_workorders_timesheet.n_billable_n', { n0: hm(r.minutes), n1: hm(r.billable_minutes) }) }}</p><p class="text-slate-500">{{ $t('wo2_workorders_timesheet.cost_n', { n0: money(r.cost) }) }}</p></div></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.date') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.technician') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.order') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.duration') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.billing') }}</th><th class="px-4 py-2 text-start">{{ $t('wo2_workorders_timesheet.cost') }}</th></tr></thead>
            <tbody>
                <tr v-for="e in entries" :key="e.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ e.date }}</td><td class="px-4 py-2 font-bold">{{ e.employee }}</td><td class="px-4 py-2"><span class="font-mono">{{ e.order }}</span> {{ e.title }}</td><td class="px-4 py-2 font-mono">{{ hm(e.minutes) }}<span v-if="e.auto_closed" class="text-amber-600"> {{ $t('wo2_workorders_timesheet.automatic') }}</span></td><td class="px-4 py-2">{{ e.billable ? $t('wo2_workorders_timesheet.yes') : $t('wo2_workorders_timesheet.no') }}</td><td class="px-4 py-2 font-mono">{{ money(e.cost) }}</td></tr>
                <tr v-if="!entries.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('wo2_workorders_timesheet.no_records_in_this_period') }}</td></tr>
            </tbody></table></div>
    </AppLayout>
</template>
