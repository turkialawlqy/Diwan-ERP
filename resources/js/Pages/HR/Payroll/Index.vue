<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, thisMonth } from '@/utils/format';

defineProps({ payrolls: Object, stats: Object });
const [year, month] = thisMonth().split('-').map(Number);
const form = useForm({ month, year, notes: '' });
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_payroll_index.payroll_runs')" />
        <PageHeader :title="$t('hr_payroll_index.payroll_runs')" :subtitle="$t('hr_payroll_index.payroll_with_overtime_absence_le')" :crumbs="[{ label: $t('hr2_hr_payroll_index.human_resources') }, { label: $t('hr2_hr_payroll_index.payroll') }]" />
        <!-- Four across only from xl: narrower, the payroll card cannot fit month, year and button. -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('hr_payroll_index.active_employees')" :value="stats.employees" />
            <StatCard :label="$t('hr_payroll_index.fixed_monthly_wages')" :value="money(stats.monthly_wages)" />
            <StatCard :label="$t('hr_payroll_index.net_of_the_last_run')" :value="money(stats.last_net)" tone="indigo" />
            <div v-if="$can('hr.payroll.create')" class="bg-white border border-slate-200 rounded-2xl p-3 flex flex-wrap items-end gap-2 text-xs">
                <input v-model.number="form.month" type="text" inputmode="decimal" data-num min="1" max="12" class="w-16 rounded-xl border-slate-200 text-xs font-mono" />
                <input v-model.number="form.year" type="text" inputmode="decimal" data-num class="w-20 rounded-xl border-slate-200 text-xs font-mono" />
                <button @click="form.post(route('hr.payroll.store'))" :disabled="form.processing" class="flex-1 whitespace-nowrap px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_payroll_index.prepare_a_payroll_run') }}</button>
            </div>
        </div>
        <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mb-2">{{ msg }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_payroll_index.number') }}</th><th class="px-3 py-2">{{ $t('hr_payroll_index.month') }}</th><th class="px-3 py-2">{{ $t('hr_payroll_index.employees') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_payroll_index.overtime') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_payroll_index.insurance_employee_company') }}</th><th class="px-3 py-2 text-end">{{ $t('hr_payroll_index.net') }}</th><th class="px-3 py-2">{{ $t('hr_payroll_index.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in payrolls.data" :key="p.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ p.payroll_number }}</td><td class="px-3 py-2 text-center font-mono">{{ p.month }}/{{ p.year }}</td><td class="px-3 py-2 text-center">{{ p.items_count }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(p.total_overtime) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.total_gosi_employee) }} / {{ money(p.total_gosi_employer) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(p.net_total) }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="p.status" /></td>
                        <td class="px-3 py-2 text-end"><Link :href="route('hr.payroll.show', p.id)" class="text-indigo-600 font-bold">{{ $t('hr_payroll_index.open') }}</Link></td>
                    </tr>
                    <tr v-if="!payrolls.data.length"><td colspan="8" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table></div>
            <Pagination :paginator="payrolls" />
        </div>
    </AppLayout>
</template>
