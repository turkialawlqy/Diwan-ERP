<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

/** A day grid: a column per resource, 30-minute rows across the working hours; click an empty cell to book. */
const props = defineProps({ date: String, resources: Array, bookings: Array, statuses: Object });
const ROW = 30; // minutes per row
const toMin = (hhmm) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5));
const bounds = computed(() => {
    const all = props.resources.flatMap((r) => r.hours.map((h) => [toMin(h.start_time), toMin(h.end_time)]));
    const lo = all.length ? Math.min(...all.map((a) => a[0])) : 8 * 60;
    const hi = all.length ? Math.max(...all.map((a) => a[1])) : 20 * 60;
    const bk = props.bookings.map((b) => [toMin(b.starts_at.slice(11, 16)), toMin(b.ends_at.slice(11, 16))]);
    return [Math.min(lo, ...bk.map((a) => a[0]), lo), Math.max(hi, ...bk.map((a) => a[1]), hi)];
});
const rows = computed(() => { const out = []; for (let m = Math.floor(bounds.value[0] / ROW) * ROW; m < bounds.value[1]; m += ROW) out.push(m); return out; });
const label = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const open = (r, m) => r.hours.some((h) => m >= toMin(h.start_time) && m < toMin(h.end_time));
const forRes = (r) => props.bookings.filter((b) => b.booking_resource_id === r.id);
const style = (b) => {
    const s = toMin(b.starts_at.slice(11, 16)), e = toMin(b.ends_at.slice(11, 16));
    return { top: `${((s - rows.value[0]) / ROW) * 2.5}rem`, height: `${Math.max(1, (e - s) / ROW) * 2.5 - 0.15}rem` };
};
const tone = { pending: 'bg-amber-100 border-amber-300 text-amber-900', confirmed: 'bg-sky-100 border-sky-300 text-sky-900', completed: 'bg-emerald-100 border-emerald-300 text-emerald-900', no_show: 'bg-slate-100 border-slate-300 text-slate-500' };
const go = (d) => router.get(route('bookings.calendar'), { date: d }, { preserveState: true });
const shift = (n) => { const d = new Date(`${props.date}T00:00:00`); d.setDate(d.getDate() + n); go(d.toISOString().slice(0, 10)); };
const book = (r, m) => router.get(route('bookings.create'), { resource: r.id, starts_at: `${props.date} ${label(m)}` });
</script>

<template>
    <AppLayout>
        <Head title="تقويم الحجوزات" />
        <PageHeader title="تقويم الحجوزات" :crumbs="[{ label: 'الحجوزات' }, { label: 'التقويم' }]">
            <template #actions>
                <button type="button" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="shift(-1)">‹ اليوم السابق</button>
                <DatePicker :model-value="date" @update:model-value="go" />
                <button type="button" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="shift(1)">اليوم التالي ›</button>
                <Link v-if="$can('bookings.bookings.create')" :href="route('bookings.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-booking><PlusIcon class="w-4 h-4" /> حجز جديد</Link>
            </template>
        </PageHeader>
        <p v-if="!resources.length" class="bg-white border border-slate-200 rounded-2xl p-8 text-center text-xs text-slate-500">أضف موردًا بساعات عمل أولًا من «الموارد».</p>
        <div v-else class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-calendar>
            <div class="flex min-w-max">
                <div class="w-14 shrink-0 border-e border-slate-100">
                    <div class="h-9 border-b border-slate-100"></div>
                    <div v-for="m in rows" :key="m" class="h-10 text-3xs font-mono text-slate-400 text-center pt-1 border-b border-slate-50">{{ m % 60 === 0 ? label(m) : '' }}</div>
                </div>
                <div v-for="r in resources" :key="r.id" class="w-48 shrink-0 border-e border-slate-100" :data-resource="r.name">
                    <div class="h-9 border-b border-slate-100 flex items-center justify-center text-xs font-bold text-slate-700">{{ r.name }}</div>
                    <div class="relative">
                        <button v-for="m in rows" :key="m" type="button" :disabled="!open(r, m)" class="block w-full h-10 border-b border-slate-50 text-3xs"
                            :class="open(r, m) ? 'hover:bg-indigo-50 cursor-pointer' : 'bg-slate-50/70 cursor-default'" :data-cell="`${r.id}-${label(m)}`" @click="open(r, m) && book(r, m)"></button>
                        <Link v-for="b in forRes(r)" :key="b.id" :href="route('bookings.show', b.id)" :style="style(b)" class="absolute inset-x-1 rounded-lg border px-2 py-1 overflow-hidden text-3xs leading-tight" :class="tone[b.status]" :data-event="b.number">
                            <b class="block truncate">{{ b.customer?.name }}</b><span class="block truncate">{{ b.service?.name }} · {{ b.starts_at.slice(11, 16) }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
