<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { money } from '@/utils/format';

const props = defineProps({ filters: Object, employees: Array, entries: Array, byEmployee: Array });
const f = reactive({ ...props.filters });
const go = () => router.get(route('work-orders.timesheet'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const hm = (m) => `${Math.floor(m / 60)}س ${m % 60}د`;
</script>

<template>
    <AppLayout>
        <Head title="سجلات الوقت" />
        <PageHeader title="سجلات الوقت" subtitle="ساعات الفنيين على أوامر العمل" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: 'سجلات الوقت' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-2 text-xs">
            <DatePicker v-model="f.from" @change="go" /><DatePicker v-model="f.to" @change="go" />
            <select v-model="f.employee" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">كل الفنيين</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4"><div v-for="r in byEmployee" :key="r.name" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs" data-employee-total>
            <b>{{ r.name }}</b><p class="font-mono">{{ hm(r.minutes) }} — قابل للفوترة {{ hm(r.billable_minutes) }}</p><p class="text-slate-500">التكلفة {{ money(r.cost) }}</p></div></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">التاريخ</th><th class="px-4 py-2 text-start">الفني</th><th class="px-4 py-2 text-start">الأمر</th><th class="px-4 py-2 text-start">المدة</th><th class="px-4 py-2 text-start">الفوترة</th><th class="px-4 py-2 text-start">التكلفة</th></tr></thead>
            <tbody>
                <tr v-for="e in entries" :key="e.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ e.date }}</td><td class="px-4 py-2 font-bold">{{ e.employee }}</td><td class="px-4 py-2"><span class="font-mono">{{ e.order }}</span> {{ e.title }}</td><td class="px-4 py-2 font-mono">{{ hm(e.minutes) }}<span v-if="e.auto_closed" class="text-amber-600"> (تلقائي)</span></td><td class="px-4 py-2">{{ e.billable ? 'نعم' : 'لا' }}</td><td class="px-4 py-2 font-mono">{{ money(e.cost) }}</td></tr>
                <tr v-if="!entries.length"><td colspan="6" class="py-10 text-center text-slate-400">لا سجلات في هذه الفترة.</td></tr>
            </tbody></table></div>
    </AppLayout>
</template>
