<script setup>
import { ArrowTrendingUpIcon, ArrowTrendingDownIcon } from '@heroicons/vue/20/solid';

defineProps({
    title: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        required: true,
    },
    unit: {
        type: String,
        default: undefined, // the company's currency
    },
    trend: {
        type: Number,
        default: null,
    },
    trendText: {
        type: String,
        default: 'مقارنة بالفترة السابقة',
    },
    color: {
        type: String,
        default: 'indigo', // emerald, indigo, amber, purple, blue
    }
});
</script>

<template>
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden group">
        <!-- Top row: Title and Icon Slot -->
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold text-slate-500 tracking-wide">{{ title }}</span>
            
            <div 
                :class="[
                    color === 'emerald' ? 'bg-emerald-50 text-emerald-600' :
                    color === 'amber' ? 'bg-amber-50 text-amber-600' :
                    color === 'purple' ? 'bg-purple-50 text-purple-600' :
                    color === 'blue' ? 'bg-blue-50 text-blue-600' :
                    'bg-indigo-50 text-indigo-600',
                    'w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0'
                ]"
            >
                <slot name="icon">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </slot>
            </div>
        </div>

        <!-- Value row -->
        <div class="mt-3 flex items-baseline gap-1.5">
            <span class="text-2xl font-black text-slate-900 tracking-tight font-sans">
                {{ value }}
            </span>
            <span v-if="unit !== ''" class="text-xs font-bold text-slate-500">
                {{ unit ?? $cur() }}
            </span>
        </div>

        <!-- Bottom trend indicator -->
        <div v-if="trend !== null" class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-1.5 text-[11px]">
            <span 
                :class="[
                    trend >= 0 ? 'text-emerald-700 bg-emerald-50' : 'text-red-700 bg-red-50',
                    'inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md font-bold'
                ]"
            >
                <ArrowTrendingUpIcon v-if="trend >= 0" class="w-3 h-3" />
                <ArrowTrendingDownIcon v-else class="w-3 h-3" />
                <span>{{ Math.abs(trend) }}%</span>
            </span>
            <span class="text-slate-400 font-medium truncate">{{ trendText }}</span>
        </div>
    </div>
</template>
