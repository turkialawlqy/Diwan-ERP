<script setup>
import { computed } from 'vue';

/**
 * Wear bar for one periodic item: green → amber (due soon) → red (overdue),
 * with the remaining hours / km / days that drive it.
 */
const props = defineProps({ status: { type: Object, required: true }, compact: { type: Boolean, default: false } });

const colors = { ok: 'bg-emerald-500', due_soon: 'bg-amber-500', overdue: 'bg-rose-600' };
const labels = { ok: 'سليم', due_soon: 'يستحق قريباً', overdue: 'متأخر' };
const badge = { ok: 'bg-emerald-50 text-emerald-700', due_soon: 'bg-amber-50 text-amber-700', overdue: 'bg-rose-50 text-rose-700' };
const units = { hours: 'ساعة', km: 'كم', days: 'يوم' };

const width = computed(() => `${Math.min(100, props.status.percent)}%`);
const parts = computed(() => Object.entries(props.status.checks || {}).map(([k, c]) => {
    const n = Number(c.remaining);
    return { key: k, state: c.state, text: n > 0 ? `متبقٍ ${n.toLocaleString('en-US')} ${units[k]}` : `متأخر ${Math.abs(n).toLocaleString('en-US')} ${units[k]}` };
}));
</script>

<template>
    <div class="min-w-[9rem]">
        <div class="flex items-center gap-2">
            <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" :aria-valuenow="status.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="labels[status.state]">
                <div :class="[colors[status.state], 'h-full rounded-full']" :style="{ width }"></div>
            </div>
            <span :class="[badge[status.state], 'px-1.5 py-0.5 rounded-md text-[10px] font-bold whitespace-nowrap']">{{ labels[status.state] }}</span>
        </div>
        <div v-if="!compact" class="mt-1 flex flex-wrap gap-x-2 text-[10px]">
            <span v-for="p in parts" :key="p.key" :class="p.state === 'overdue' ? 'text-rose-600 font-bold' : p.state === 'due_soon' ? 'text-amber-700' : 'text-slate-400'">{{ p.text }}</span>
        </div>
    </div>
</template>
