<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money } from '@/utils/format';

defineProps({ payrolls: Object, stats: Object });
const now = new Date();
const form = useForm({ month: now.getMonth() + 1, year: now.getFullYear(), notes: '' });
</script>

<template>
    <AppLayout>
        <Head title="مسيرات الرواتب" />
        <PageHeader title="مسيرات الرواتب" subtitle="احتساب الرواتب مع الإضافي والغياب والإجازات والسلف والتأمينات الاجتماعية" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الرواتب' }]" />
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="موظفون على رأس العمل" :value="stats.employees" />
            <StatCard label="الأجور الشهرية الثابتة" :value="money(stats.monthly_wages)" />
            <StatCard label="صافي آخر مسير" :value="money(stats.last_net)" tone="indigo" />
            <div v-if="$can('hr.payroll.create')" class="bg-white border border-slate-200 rounded-2xl p-3 flex items-end gap-2 text-xs">
                <input v-model="form.month" type="number" min="1" max="12" class="w-16 rounded-xl border-slate-200 text-xs font-mono" />
                <input v-model="form.year" type="number" class="w-20 rounded-xl border-slate-200 text-xs font-mono" />
                <button @click="form.post(route('hr.payroll.store'))" :disabled="form.processing" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إعداد مسير</button>
            </div>
        </div>
        <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mb-2">{{ msg }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الرقم</th><th class="px-3 py-2">الشهر</th><th class="px-3 py-2">الموظفون</th><th class="px-3 py-2 text-end">الإضافي</th><th class="px-3 py-2 text-end">التأمينات (موظف/منشأة)</th><th class="px-3 py-2 text-end">الصافي</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in payrolls.data" :key="p.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ p.payroll_number }}</td><td class="px-3 py-2 text-center font-mono">{{ p.month }}/{{ p.year }}</td><td class="px-3 py-2 text-center">{{ p.items_count }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(p.total_overtime) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.total_gosi_employee) }} / {{ money(p.total_gosi_employer) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(p.net_total) }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="p.status" /></td>
                        <td class="px-3 py-2 text-end"><Link :href="route('hr.payroll.show', p.id)" class="text-indigo-600 font-bold">فتح</Link></td>
                    </tr>
                    <tr v-if="!payrolls.data.length"><td colspan="8" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="payrolls" />
        </div>
    </AppLayout>
</template>
