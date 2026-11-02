<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';

/** Dispatch board: one column per technician for the day, plus orders nobody has yet. */
const props = defineProps({ date: String, technicians: Array, orders: Array, statuses: Object });
const col = (id) => props.orders.filter((o) => o.assigned_to === id);
const unassigned = computed(() => props.orders.filter((o) => !o.assigned_to));
const go = (d) => router.get(route('work-orders.board'), { date: d }, { preserveState: true });
const tone = { new: 'border-slate-300 bg-white', scheduled: 'border-sky-300 bg-sky-50', in_progress: 'border-emerald-300 bg-emerald-50', on_hold: 'border-amber-300 bg-amber-50' };
const time = (v) => (v ? String(v).slice(11, 16) : '—');
</script>

<template>
    <AppLayout>
        <Head title="لوحة توزيع الأوامر" />
        <PageHeader title="لوحة توزيع الأوامر" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: 'اللوحة' }]"><template #actions><DatePicker :model-value="date" @update:model-value="go" /></template></PageHeader>
        <div class="flex gap-3 overflow-x-auto pb-2" data-board>
            <div class="w-64 shrink-0 bg-slate-50 border border-dashed border-slate-300 rounded-2xl p-2 space-y-2" data-column="unassigned">
                <p class="text-xs font-bold text-slate-600 px-1">بلا فني / بلا موعد ({{ unassigned.length }})</p>
                <Link v-for="o in unassigned" :key="o.id" :href="route('work-orders.edit', o.id)" class="block rounded-xl border p-2 text-xs" :class="tone[o.status]"><b class="font-mono text-indigo-600">{{ o.number }}</b> {{ o.title }}<span class="block text-slate-500">{{ o.customer?.name }}</span></Link>
            </div>
            <div v-for="t in technicians" :key="t.id" class="w-64 shrink-0 bg-white border border-slate-200 rounded-2xl p-2 space-y-2" :data-column="t.name">
                <p class="text-xs font-bold px-1">{{ t.name }} ({{ col(t.id).length }})</p>
                <Link v-for="o in col(t.id)" :key="o.id" :href="route('work-orders.show', o.id)" class="block rounded-xl border p-2 text-xs" :class="tone[o.status]">
                    <span class="font-mono text-slate-500">{{ time(o.scheduled_start) }}</span> <b class="font-mono text-indigo-600">{{ o.number }}</b> {{ o.title }}<span class="block text-slate-500">{{ o.customer?.name }} · {{ statuses[o.status] }}</span>
                </Link>
            </div>
            <p v-if="!technicians.length && !unassigned.length" class="text-xs text-slate-400 p-6">لا أوامر في هذا اليوم.</p>
        </div>
    </AppLayout>
</template>
