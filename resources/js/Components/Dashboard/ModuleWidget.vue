<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

/** A system's card on the dashboard: its live numbers, each linking to the page behind it. */
defineProps({ widget: { type: Object, required: true } });
const tones = { neutral: 'text-slate-800', good: 'text-emerald-600', warn: 'text-amber-600', danger: 'text-rose-600' };
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl p-4" :data-widget="widget.key">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-black text-sm text-slate-700">{{ widget.title }}</h3>
            <Link v-if="$canRoute(widget.link)" :href="route(widget.link)" class="text-[11px] font-bold text-indigo-600 inline-flex items-center gap-1">{{ $t('cmp.view_module') }} <ArrowLeftIcon class="w-3 h-3" /></Link>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <component :is="m.route && $canRoute(m.route) ? Link : 'div'" v-for="m in widget.metrics" :key="m.label" :href="m.route && $canRoute(m.route) ? route(m.route) : undefined"
                class="rounded-xl bg-slate-50 px-2.5 py-2 text-center" :class="m.route && $canRoute(m.route) ? 'hover:bg-slate-100' : ''" data-metric>
                <div class="text-lg font-black font-mono" :class="tones[m.tone] || tones.neutral">{{ m.value }}</div>
                <div class="text-[10px] text-slate-500 leading-tight">{{ m.label }}</div>
            </component>
        </div>
    </div>
</template>
