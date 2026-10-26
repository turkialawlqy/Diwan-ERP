<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    tone: { type: String, default: 'slate' }, // slate | emerald | rose | indigo | amber
});

const tones = {
    slate: 'text-slate-800',
    emerald: 'text-emerald-600',
    rose: 'text-rose-600',
    indigo: 'text-indigo-600',
    amber: 'text-amber-600',
};
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl p-4">
        <p class="text-[11px] font-semibold text-slate-500">{{ label }}</p>
        <p :class="[tones[tone] || tones.slate, 'text-lg font-black mt-1 font-mono']" dir="ltr">{{ value }}</p>
    </div>
</template>
