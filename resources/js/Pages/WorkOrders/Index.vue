<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ orders: Object, filters: Object, statuses: Object, priorities: Object, technicians: Array, stats: Object });
const f = reactive({ ...props.filters });
const go = () => router.get(route('work-orders.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const tone = { new: 'text-slate-600', scheduled: 'text-sky-700', in_progress: 'text-emerald-700', on_hold: 'text-amber-700', done: 'text-emerald-800', invoiced: 'text-indigo-700', cancelled: 'text-rose-600' };
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
</script>

<template>
    <AppLayout>
        <Head title="أوامر العمل" />
        <PageHeader title="أوامر العمل" subtitle="مهام الفنيين الميدانية" :crumbs="[{ label: 'أوامر العمل' }, { label: 'القائمة' }]">
            <template #actions>
                <Link :href="route('work-orders.board')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">لوحة التوزيع</Link>
                <Link v-if="$can('work_orders.orders.create')" :href="route('work-orders.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-order><PlusIcon class="w-4 h-4" /> أمر عمل جديد</Link>
            </template>
        </PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4"><StatCard label="مفتوحة" :value="stats.open" /><StatCard label="قيد التنفيذ" :value="stats.in_progress" /><StatCard label="بانتظار الفوترة" :value="stats.to_invoice" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-2 text-xs">
            <input v-model="f.search" @keyup.enter="go" placeholder="رقم أو عنوان أو عميل" class="rounded-xl border-slate-200 text-xs w-48" />
            <select v-model="f.status" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.technician" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">كل الفنيين</option><option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            <DatePicker v-model="f.from" @change="go" /><DatePicker v-model="f.to" @change="go" />
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">العنوان</th><th class="px-4 py-2 text-start">العميل</th><th class="px-4 py-2 text-start">الفني</th><th class="px-4 py-2 text-start">الموعد</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100" :data-order="o.number">
                        <td class="px-4 py-2"><Link :href="route('work-orders.show', o.id)" class="font-mono font-bold text-indigo-600">{{ o.number }}</Link></td>
                        <td class="px-4 py-2 font-bold">{{ o.title }}<span v-if="o.work_type" class="block font-normal text-slate-500">{{ o.work_type.name }}</span></td><td class="px-4 py-2">{{ o.customer?.name }}</td><td class="px-4 py-2">{{ o.lead?.name || '—' }}</td>
                        <td class="px-4 py-2 font-mono">{{ when(o.scheduled_start) }}</td><td class="px-4 py-2 font-bold" :class="tone[o.status]">{{ statuses[o.status] }}</td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('work-orders.show', o.id)" :edit="['new', 'scheduled', 'in_progress', 'on_hold'].includes(o.status) && $can('work_orders.orders.edit') ? route('work-orders.edit', o.id) : null" /></td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="7" class="py-10 text-center text-slate-400">لا أوامر عمل.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="orders" />
    </AppLayout>
</template>
