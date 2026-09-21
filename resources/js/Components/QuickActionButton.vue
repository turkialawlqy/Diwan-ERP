<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
    color: {
        type: String,
        default: 'indigo', // emerald, indigo, purple, blue
    }
});
</script>

<template>
    <Link 
        :href="href"
        class="flex items-center gap-3.5 p-3.5 rounded-2xl border transition-all duration-200 shadow-sm hover:shadow-md bg-white hover:-translate-y-0.5 group"
        :class="[
            color === 'emerald' ? 'border-emerald-200/60 hover:border-emerald-300' :
            color === 'purple' ? 'border-purple-200/60 hover:border-purple-300' :
            color === 'blue' ? 'border-blue-200/60 hover:border-blue-300' :
            'border-indigo-200/60 hover:border-indigo-300'
        ]"
    >
        <div 
            :class="[
                color === 'emerald' ? 'bg-emerald-50 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white' :
                color === 'purple' ? 'bg-purple-50 text-purple-600 group-hover:bg-purple-600 group-hover:text-white' :
                color === 'blue' ? 'bg-blue-50 text-blue-600 group-hover:bg-blue-600 group-hover:text-white' :
                'bg-indigo-50 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white',
                'w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors shadow-sm'
            ]"
        >
            <slot name="icon" />
        </div>

        <div class="flex flex-col text-start">
            <span class="text-xs sm:text-sm font-bold text-slate-800 group-hover:text-indigo-600 transition-colors">
                {{ title }}
            </span>
            <span v-if="subtitle" class="text-[11px] text-slate-400 font-medium">
                {{ subtitle }}
            </span>
        </div>
    </Link>
</template>
